<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Services\Vouchers\PlatformVoucherService;
use App\Services\Vouchers\VoucherService;
use App\Support\CategoryFieldConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Platform vouchers (Admin Panel → Vouchers modal). Platform-funded only. */
class VoucherController extends Controller
{
    public function __construct(
        private VoucherService $vouchers,
        private PlatformVoucherService $platform,
    ) {}

    /**
     * GET /api/admin/vouchers?tab=active|inactive&scope=&type=&status=&page=
     * Active tab: active/scheduled/fully redeemed/unavailable; Inactive:
     * deactivated/expired. 5 per page, plus per-tab counts.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['active', 'inactive'])],
            'scope' => ['nullable', Rule::in([Voucher::SCOPE_PLATFORM, Voucher::SCOPE_CATEGORY])],
            'type' => ['nullable', Rule::in([Voucher::TYPE_DISCOUNT, Voucher::TYPE_SHIPPING])],
            'status' => ['nullable', Rule::in([
                Voucher::STATUS_ACTIVE, Voucher::STATUS_FULLY_REDEEMED, Voucher::STATUS_UNAVAILABLE,
                Voucher::STATUS_DEACTIVATED, Voucher::STATUS_EXPIRED,
            ])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $base = Voucher::where('source', Voucher::SOURCE_PLATFORM)
            ->when($filters['scope'] ?? null, fn ($q, $scope) => $q->where('scope', $scope))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type));
        $tab = ($filters['tab'] ?? 'active') === 'inactive' ? 'inactive' : 'live';

        $page = (clone $base)
            ->whereStatus($tab)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->whereStatus($status))
            ->withAvailability()
            ->withCount('redemptions')
            ->with(['creator', 'deactivator'])
            ->latest()
            ->paginate(5);

        $categories = DB::table('voucher_categories')->whereIn('voucher_id', collect($page->items())->pluck('id'))
            ->orderBy('category')->get()->groupBy('voucher_id');

        return response()->json(['data' => [
            'items' => collect($page->items())->map(fn (Voucher $v) => $this->platform->presentForAdmin(
                $v,
                $categories->get($v->id, collect())->pluck('category')->all(),
            )),
            'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
            'counts' => [
                'active' => (clone $base)->whereStatus('live')->count(),
                'inactive' => (clone $base)->whereStatus('inactive')->count(),
            ],
            'spend' => $this->spend(),
        ]]);
    }

    /** GET /api/admin/vouchers/options — what the create flow can pick from. */
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'categories' => CategoryFieldConfig::categories(),
            'maxCategories' => Voucher::MAX_CATEGORIES,
            'defaultLapsedDays' => Voucher::DEFAULT_LAPSED_DAYS,
        ]]);
    }

    /** GET /api/admin/vouchers/{id} — full detail, for cloning. */
    public function show(string $id): JsonResponse
    {
        $voucher = $this->voucher($id)->loadCount('redemptions');

        return response()->json(['data' => $this->platform->presentForAdmin($voucher, $voucher->categoryNames())]);
    }

    /**
     * POST /api/admin/vouchers. A category voucher overlapping another of
     * the same type on a category and dates → 409 with the conflicts;
     * resend with confirm_overlap=true to create anyway.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([Voucher::TYPE_DISCOUNT, Voucher::TYPE_SHIPPING])],
            'scope' => ['required', Rule::in([Voucher::SCOPE_PLATFORM, Voucher::SCOPE_CATEGORY])],
            'categories' => ['nullable', 'array', 'max:'.Voucher::MAX_CATEGORIES],
            'categories.*' => ['string', 'max:100'],
            'eligibility' => ['required', Rule::in([Voucher::ELIGIBILITY_ALL, Voucher::ELIGIBILITY_NEW, Voucher::ELIGIBILITY_LAPSED, Voucher::ELIGIBILITY_REGION])],
            'lapsed_days' => ['required_if:eligibility,lapsed', 'nullable', 'integer', 'min:7', 'max:730'],
            'regions' => ['required_if:eligibility,region', 'nullable', 'array', 'max:20'],
            'regions.*' => ['string', 'max:150'],
            'discount_type' => ['required_if:type,discount', 'nullable', Rule::in([Voucher::DISCOUNT_PERCENTAGE, Voucher::DISCOUNT_FIXED])],
            'discount_value' => ['required_if:type,discount', 'nullable', 'numeric', 'gt:0', 'max:1000000'],
            'max_discount' => [Rule::requiredIf(fn () => $request->input('type') === 'discount' && $request->input('discount_type') === 'percentage'), 'nullable', 'numeric', 'gt:0', 'max:1000000'],
            'min_spend' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after:starts_at', 'after:now'],
            'usage_limit' => ['required', 'integer', 'min:1', 'max:10000000'],
            'per_user_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'budget_cap' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'distribution' => ['required', Rule::in([Voucher::DISTRIBUTION_CLAIM, Voucher::DISTRIBUTION_AUTO_CLAIM, Voucher::DISTRIBUTION_PUSH, Voucher::DISTRIBUTION_AUTO_APPLY])],
            'stackable' => ['required', 'boolean'],
            'confirm_overlap' => ['sometimes', 'boolean'],
        ], [
            'max_discount.required' => 'Set a max discount for percentage vouchers.',
            'regions.required_if' => 'Pick at least one region.',
            'expires_at.after' => 'End date must be after the start date and in the future.',
        ]);

        if ($data['scope'] === Voucher::SCOPE_CATEGORY && ! ($data['confirm_overlap'] ?? false)) {
            $conflicts = $this->platform->overlaps(
                $data['type'],
                array_values(array_unique($data['categories'] ?? [])),
                Carbon::parse($data['starts_at']),
                Carbon::parse($data['expires_at']),
            );

            if ($conflicts->isNotEmpty()) {
                return response()->json([
                    'message' => 'Some categories already have a platform voucher of this type during these dates.',
                    'conflicts' => $conflicts->map(fn (array $c) => [
                        'id' => $c['voucher']->id,
                        'code' => $c['voucher']->code,
                        'label' => $c['voucher']->label(),
                        'categories' => $c['categories'],
                    ]),
                ], 409);
            }
        }

        $voucher = $this->platform->create($request->user(), $data)->loadCount('redemptions');

        return response()->json(['data' => $this->platform->presentForAdmin($voucher, $voucher->categoryNames())], 201);
    }

    /** POST /api/admin/vouchers/{id}/deactivate */
    public function deactivate(Request $request, string $id): JsonResponse
    {
        $voucher = $this->vouchers->deactivate($this->voucher($id));
        $voucher->forceFill(['deactivated_by' => $request->user()->id])->save();

        return $this->respond($voucher);
    }

    /** POST /api/admin/vouchers/{id}/reactivate */
    public function reactivate(string $id): JsonResponse
    {
        return $this->respond($this->vouchers->reactivate($this->voucher($id)));
    }

    /** POST /api/admin/vouchers/{id}/stock — { usage_limit } (Fully Redeemed only) */
    public function addStock(Request $request, string $id): JsonResponse
    {
        $limit = (int) $request->validate(['usage_limit' => ['required', 'integer', 'min:1', 'max:10000000']])['usage_limit'];

        return $this->respond($this->vouchers->addStock($this->voucher($id), $limit));
    }

    /** DELETE /api/admin/vouchers/{id} — only if never redeemed. */
    public function destroy(string $id): JsonResponse
    {
        $this->vouchers->delete($this->voucher($id));

        return response()->json(['data' => ['id' => $id]]);
    }

    /**
     * What platform vouchers have cost so far (live redemptions, i.e. not
     * cancelled): this month and all time, plus the vouchered orders.
     *
     * @return array{month: float, allTime: float, orders: int}
     */
    private function spend(): array
    {
        $row = DB::table('voucher_redemptions as r')
            ->join('vouchers as v', 'v.id', '=', 'r.voucher_id')
            ->where('v.source', Voucher::SOURCE_PLATFORM)
            ->whereNull('r.released_at')
            ->selectRaw('COALESCE(SUM(r.amount), 0) AS all_time')
            ->selectRaw('COALESCE(SUM(CASE WHEN r.created_at >= ? THEN r.amount ELSE 0 END), 0) AS month', [now()->startOfMonth()])
            ->selectRaw('COUNT(DISTINCT r.order_id) AS orders')
            ->first();

        return ['month' => round((float) $row->month, 2), 'allTime' => round((float) $row->all_time, 2), 'orders' => (int) $row->orders];
    }

    private function respond(Voucher $voucher): JsonResponse
    {
        $voucher->loadCount('redemptions');

        return response()->json(['data' => $this->platform->presentForAdmin($voucher, $voucher->categoryNames())]);
    }

    private function voucher(string $id): Voucher
    {
        return Voucher::where('source', Voucher::SOURCE_PLATFORM)->findOrFail($id);
    }
}
