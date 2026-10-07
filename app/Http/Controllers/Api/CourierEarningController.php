<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourierDetail;
use App\Models\CourierEarning;
use App\Models\CourierPayout;
use App\Models\LogisticsCompany;
use App\Models\ParcelAssignment;
use App\Models\Profile;
use App\Services\CourierEarningService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CourierEarningController extends Controller
{
    public function __construct(private CourierEarningService $earnings) {}

    private function company(Request $request, bool $write = false): LogisticsCompany
    {
        $company = LogisticsCompany::forMember($request->user()->id)->where('status', 'approved')->where('account_status', 'active')->firstOrFail();
        if ($write && $company->owner_profile_id !== $request->user()->id) {
            abort_unless($company->admins()->where('profile_id', $request->user()->id)->where('status', 'active')->where('role', 'admin')->exists(), 403);
        }

        return $company;
    }

    private function courier(Request $request): string
    {
        abort_unless(in_array($request->user()->role, ['courier', 'driver'], true), 403);

        return $request->user()->id;
    }

    private function companyCourier(LogisticsCompany $company, string $id): void
    {
        abort_unless(CourierDetail::where('logistics_company_id', $company->id)->where('profile_id', $id)->exists()
            || CourierEarning::where('company_id', $company->id)->where('courier_id', $id)->exists(), 404);
    }

    public function summary(Request $request)
    {
        $id = $this->courier($request);
        $company = CourierDetail::with('logisticsCompany')->find($id)?->logisticsCompany;

        return response()->json(['data' => $this->earnings->summary($id, $company)]);
    }

    public function ledger(Request $request)
    {
        $request->validate(['status' => ['nullable', Rule::in(['pending', 'available', 'paid', 'void'])], 'courier_id' => ['nullable', 'uuid']]);
        $company = str_starts_with($request->path(), 'api/logistics/') ? $this->company($request) : null;
        $query = CourierEarning::with('order:id,order_number')->when($company,
            fn ($q) => $q->where('company_id', $company->id)->when($request->courier_id, fn ($q) => $q->where('courier_id', $request->courier_id)),
            fn ($q) => $q->where('courier_id', $this->courier($request)));

        return response()->json(['data' => $query->when($request->status, fn ($q) => $q->where('status', $request->status))->latest()->orderByDesc('id')->paginate(25)]);
    }

    public function couriers(Request $request)
    {
        $company = $this->company($request);
        $roster = Profile::whereIn('id', fn ($q) => $q->select('profile_id')->from('courier_details')->where('logistics_company_id', $company->id))
            ->orWhereIn('id', fn ($q) => $q->select('courier_id')->from('courier_earnings')->where('company_id', $company->id))
            ->select('id', 'first_name', 'last_name')->orderBy('first_name')->paginate(25);
        $ids = $roster->pluck('id');
        $totals = CourierEarning::where('company_id', $company->id)->whereIn('courier_id', $ids)
            ->selectRaw('courier_id, status, SUM(remaining_cents) as amount')->groupBy('courier_id', 'status')->get()->groupBy('courier_id');
        $cod = DB::table('courier_cod_collections')->where('company_id', $company->id)->whereIn('courier_id', $ids)
            ->selectRaw('courier_id, SUM(remaining_cents) as amount')->groupBy('courier_id')->pluck('amount', 'courier_id');
        $roster->getCollection()->transform(function ($profile) use ($totals, $cod) {
            $amounts = ($totals->get($profile->id) ?? collect())->pluck('amount', 'status');
            $profile->setAttribute('pending_cents', (int) ($amounts['pending'] ?? 0));
            $profile->setAttribute('available_cents', (int) ($amounts['available'] ?? 0));
            $profile->setAttribute('cod_owed_cents', (int) ($cod[$profile->id] ?? 0));

            return $profile;
        });

        return response()->json(['data' => $roster->toArray() + ['can_manage' => $company->owner_profile_id === $request->user()->id
            || $company->admins()->where('profile_id', $request->user()->id)->where('status', 'active')->where('role', 'admin')->exists()]]);
    }

    public function settings(Request $request)
    {
        return response()->json(['data' => $this->company($request)->only(['courier_share_bps', 'pickup_weight', 'transfer_weight', 'delivery_weight', 'cod_overdue_days', 'early_cashout_minimum_cents'])]);
    }

    public function updateSettings(Request $request)
    {
        $company = $this->company($request, true);
        $data = $request->validate(['courier_share_bps' => ['required', 'integer', 'between:0,10000'],
            'pickup_weight' => ['required', 'integer', 'between:0,100'], 'transfer_weight' => ['required', 'integer', 'between:0,100'],
            'delivery_weight' => ['required', 'integer', 'between:0,100'], 'cod_overdue_days' => ['required', 'integer', 'between:1,365'],
            'early_cashout_minimum_cents' => ['present', 'nullable', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000']]);
        if ($data['pickup_weight'] + $data['transfer_weight'] + $data['delivery_weight'] !== 100) {
            throw ValidationException::withMessages(['weights' => 'Task weights must total 100%.']);
        }
        DB::transaction(function () use ($company, $data, $request) {
            $company->update(collect($data)->except('reason')->all());
            $this->earnings->audit($company->id, $request->user()->id, $company->id, 'settings_changed', $data['reason'], $data);
        });

        return $this->settings($request);
    }

    public function adjustment(Request $request)
    {
        $company = $this->company($request, true);
        $data = $request->validate(['courier_id' => ['required', 'uuid'], 'type' => ['required', Rule::in(['bonus', 'deduction', 'tip'])],
            'amount_cents' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000'], 'parcel_id' => ['nullable', 'uuid']]);
        $this->companyCourier($company, $data['courier_id']);
        if (isset($data['parcel_id'])) {
            abort_unless(ParcelAssignment::whereKey($data['parcel_id'])->where('logistics_company_id', $company->id)->exists(), 404);
        }

        return response()->json(['data' => $this->earnings->adjustment($company, $data['courier_id'], $data['type'], $data['amount_cents'], trim($data['reason']), $request->user()->id, $data['parcel_id'] ?? null)], 201);
    }

    public function remittance(Request $request)
    {
        $company = $this->company($request, true);
        $data = $request->validate(['courier_id' => ['required', 'uuid'], 'amount_cents' => ['required', 'integer', 'min:1'], 'reference' => ['required', 'string', 'max:255']]);
        $this->companyCourier($company, $data['courier_id']);
        $this->earnings->remit($company, $data['courier_id'], $data['amount_cents'], $request->user()->id, $data['reference']);

        return response()->json(['message' => 'COD remittance recorded.']);
    }

    public function payouts(Request $request)
    {
        $request->validate(['courier_id' => ['nullable', 'uuid']]);
        $company = str_starts_with($request->path(), 'api/logistics/') ? $this->company($request) : null;
        $query = CourierPayout::with('courier:id,first_name,last_name')->when($company,
            fn ($q) => $q->where('company_id', $company->id)->when($request->courier_id, fn ($q) => $q->where('courier_id', $request->courier_id)),
            fn ($q) => $q->where('courier_id', $this->courier($request)));

        return response()->json(['data' => $query->latest()->orderByDesc('id')->paginate(25)]);
    }

    private function scopedPayout(Request $request, string $id, bool $write = false): CourierPayout
    {
        $company = str_starts_with($request->path(), 'api/logistics/') ? $this->company($request, $write) : null;

        return CourierPayout::when($company, fn ($q) => $q->where('company_id', $company->id),
            fn ($q) => $q->where('courier_id', $this->courier($request)))->findOrFail($id);
    }

    public function showPayout(Request $request, string $id)
    {
        return response()->json(['data' => $this->scopedPayout($request, $id)->load(['courier:id,first_name,last_name', 'lines.earning.order:id,order_number'])]);
    }

    public function createPayout(Request $request)
    {
        $company = $this->company($request, true);
        $data = $request->validate(['courier_id' => ['required', 'uuid'], 'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start', 'before_or_equal:'.now('Asia/Manila')->toDateString()]]);
        $this->companyCourier($company, $data['courier_id']);
        if (Carbon::parse($data['period_start'])->diffInDays(Carbon::parse($data['period_end'])) !== 6.0) {
            throw ValidationException::withMessages(['period_end' => 'Weekly statements must cover seven days.']);
        }

        return response()->json(['data' => $this->earnings->statement($company, $data['courier_id'], $request->user()->id, $data['period_start'], $data['period_end'])], 201);
    }

    public function payoutAction(Request $request, string $id, string $action)
    {
        abort_unless(in_array($action, ['approve', 'pay', 'cancel'], true), 404);
        $request->validate(['reference' => [$action === 'pay' ? 'required' : 'nullable', 'string', 'max:255']]);

        return response()->json(['data' => $this->earnings->payoutAction($this->scopedPayout($request, $id, true), $action, $request->user()->id, $request->reference)]);
    }

    public function earlyCashout(Request $request)
    {
        $id = $this->courier($request);
        $company = CourierDetail::with('logisticsCompany')->findOrFail($id)->logisticsCompany;
        abort_unless($company && $company->account_status === 'active', 403);

        return response()->json(['data' => $this->earnings->statement($company, $id, $id, now('Asia/Manila')->startOfWeek()->toDateString(), now('Asia/Manila')->toDateString(), true)], 201);
    }
}
