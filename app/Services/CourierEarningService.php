<?php

namespace App\Services;

use App\Models\CourierEarning;
use App\Models\CourierPayout;
use App\Models\EscrowTransaction;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\ParcelAssignment;
use App\Services\Payments\Money;
use App\Services\Payments\PaymentSplitter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CourierEarningService
{
    private array $contexts = [];

    public function preparePreviews($assignments): void
    {
        $ids = $assignments->pluck('order_id')->unique();
        $legs = ParcelAssignment::whereIn('order_id', $ids)->orderBy('created_at')->orderBy('id')->get()
            ->groupBy(fn ($leg) => $leg->order_id.':'.($leg->return_request_id ?? 'forward'));
        $transactions = EscrowTransaction::whereIn('order_id', $ids)->whereIn('type', [EscrowTransaction::TYPE_RELEASE, EscrowTransaction::TYPE_RETURN])->get();
        $snapshots = CourierEarning::whereIn('order_id', $ids)->whereNotNull('task_weights')->oldest()->get()->groupBy('leg_id');
        foreach ($assignments as $assignment) {
            $key = $assignment->order_id.':'.($assignment->return_request_id ?? 'forward');
            $release = $assignment->isReturn()
                ? $transactions->firstWhere('idempotency_key', 'return:'.$assignment->return_request_id)
                : $transactions->where('order_id', $assignment->order_id)->firstWhere('type', EscrowTransaction::TYPE_RELEASE);
            $this->contexts[$key] = ['order' => $assignment->order, 'legs' => $legs->get($key, collect()), 'release' => $release, 'snapshots' => $snapshots];
        }
    }

    public static function taskAmounts(int $legCents, int $rateBps, array $weights): array
    {
        return Money::allocate(Money::percent($legCents, $rateBps), $weights);
    }

    private function context(ParcelAssignment $assignment): array
    {
        $key = $assignment->order_id.':'.($assignment->return_request_id ?? 'forward');

        return $this->contexts[$key] ??= [
            'order' => $assignment->order ?? Order::findOrFail($assignment->order_id),
            'legs' => ParcelAssignment::where('order_id', $assignment->order_id)
                ->where('return_request_id', $assignment->return_request_id)->orderBy('created_at')->orderBy('id')->get(),
            'release' => EscrowTransaction::where('order_id', $assignment->order_id)
                ->where('type', $assignment->isReturn() ? EscrowTransaction::TYPE_RETURN : EscrowTransaction::TYPE_RELEASE)
                ->when($assignment->isReturn(), fn ($q) => $q->where('idempotency_key', 'return:'.$assignment->return_request_id))->first(),
            'snapshots' => CourierEarning::where('order_id', $assignment->order_id)->whereNotNull('task_weights')->oldest()->get()->groupBy('leg_id'),
        ];
    }

    private function parcelRoot(ParcelAssignment $leg, $legs): string
    {
        $seen = [];
        while ($leg->previous_assignment_id && ! isset($seen[$leg->id])) {
            $seen[$leg->id] = true;
            $previous = $legs->firstWhere('id', $leg->previous_assignment_id);
            if (! $previous) {
                break;
            }
            $leg = $previous;
        }

        return $leg->id;
    }

    private function calculation(ParcelAssignment $assignment, string $task, ?CourierEarning $snapshot = null, bool $final = false): array
    {
        $context = $this->context($assignment);
        $order = $context['order'];
        $legs = $context['legs'];
        $company = $assignment->logisticsCompany ?? LogisticsCompany::findOrFail($assignment->logistics_company_id);
        $weights = $snapshot?->task_weights ?? ['pickup' => (int) ($company->pickup_weight ?? 30), 'transfer' => (int) ($company->transfer_weight ?? 10), 'delivery' => (int) ($company->delivery_weight ?? 60)];
        $rate = $snapshot?->rate_bps ?? (int) ($company->courier_share_bps ?? 8000);
        $shares = $context['release']?->meta[$assignment->isReturn() ? 'return_shares' : 'shares'] ?? null;
        if ($shares === null) {
            $shipping = $assignment->isReturn()
                ? Money::toCents($assignment->returnRequest?->return_shipping_fee ?? $order->shipping_fee)
                : Money::toCents($order->shipping_fee);
            $chain = $legs->pluck('logistics_company_id')->unique()->values()->all();
            // Before settlement the dispatch chain may still change. Final amounts use its recorded shares.
            $card = Money::allocate($shipping, array_fill_keys($chain, 1));
            $shares = PaymentSplitter::split(0, $shipping, $order->seller_id, $chain, $card);
        }
        $companyCents = array_sum(array_column(array_filter($shares, fn ($s) => $s['party_id'] === $company->id && str_ends_with($s['account'], '_logistics')), 'cents'));
        $roots = [];
        foreach ($legs->where('logistics_company_id', $company->id) as $leg) {
            $roots[$this->parcelRoot($leg, $legs)] = 1;
        }
        ksort($roots);
        $parcel = $this->parcelRoot($assignment, $legs);
        $source = Money::allocate($companyCents, $roots)[$parcel] ?? 0;
        $active = [];
        if ($assignment->picked_up_by || (! $final && ! $assignment->previous_assignment_id)) {
            $active['pickup'] = $weights['pickup'];
        }
        if ($assignment->transferred_at || (! $final && ($assignment->transfer_to_company_id || $assignment->is_transfer))) {
            $active['transfer'] = $weights['transfer'];
        }
        if ($assignment->delivered_at || $task === 'failed_attempt'
            || in_array($assignment->status, [ParcelAssignment::STATUS_FAILED_ATTEMPT, ParcelAssignment::STATUS_NEEDS_DISPATCHER_REVIEW], true)
            || (! $final && ! $assignment->transfer_to_company_id && ! $assignment->is_transfer)) {
            $active['delivery'] = $weights['delivery'];
        }
        $payTask = $task === 'failed_attempt' ? 'delivery' : $task;
        $active[$payTask] ??= $weights[$payTask];
        $amounts = self::taskAmounts($source, $rate, $active);
        $cents = $amounts[$payTask] ?? 0;
        if ($task === 'failed_attempt') {
            $cents = Money::percent($cents, 2000);
        }

        return ['amount_cents' => $cents, 'remaining_cents' => $cents, 'source_leg_cents' => $source, 'rate_bps' => $rate,
            'task_weights' => $weights, 'weight_numerator' => $active[$payTask], 'weight_denominator' => array_sum($active), 'parcel_id' => $parcel];
    }

    public function preview(ParcelAssignment $assignment, string $task): int
    {
        if (! $assignment->isReturn() && $assignment->order?->delivery_return_flagged_at) {
            return 0;
        }
        $snapshot = $this->context($assignment)['snapshots']->get($assignment->id)?->first();

        return $this->calculation($assignment, $task, $snapshot)['amount_cents'];
    }

    public function record(ParcelAssignment $assignment, string $courierId, string $task, string $proof, ?string $reason = null): ?CourierEarning
    {
        if (! $assignment->isReturn() && $assignment->order?->delivery_return_flagged_at && $task !== 'failed_attempt') {
            return null;
        }

        return DB::transaction(function () use ($assignment, $courierId, $task, $proof, $reason) {
            Order::whereKey($assignment->order_id)->lockForUpdate()->firstOrFail();
            $this->contexts = [];
            $direction = $assignment->isReturn() ? 'return' : 'forward';
            $snapshot = CourierEarning::where('leg_id', $assignment->id)->where('direction', $direction)->whereNotNull('task_weights')->oldest()->first();
            $calc = $this->calculation($assignment, $task, $snapshot);
            // A failed attempt is capped across all companies/legs of this physical parcel.
            if ($task === 'failed_attempt' && ($existing = CourierEarning::where('failed_parcel_key', $calc['parcel_id'])->first())) {
                return $existing;
            }
            $entry = CourierEarning::firstOrCreate(['parcel_id' => $calc['parcel_id'], 'type' => $task, 'leg_id' => $assignment->id, 'direction' => $direction],
                $calc + ['courier_id' => $courierId, 'company_id' => $assignment->logistics_company_id, 'order_id' => $assignment->order_id,
                    'proof_path' => $proof, 'reason' => $reason, 'failed_parcel_key' => $task === 'failed_attempt' ? $calc['parcel_id'] : null, 'status' => 'pending']);
            $context = $this->context($assignment);
            if ($context['release']) {
                $this->settle($assignment->order_id, $assignment->return_request_id);
                $entry->refresh();
            }

            return $entry;
        });
    }

    public function settle(string $orderId, ?string $returnRequestId = null): void
    {
        if (! CourierEarning::where('order_id', $orderId)->where('status', 'pending')->exists()) {
            return;
        }
        $this->contexts = [];
        $legs = ParcelAssignment::with(['order', 'logisticsCompany', 'returnRequest'])->where('order_id', $orderId)
            ->where('return_request_id', $returnRequestId)->get()->keyBy('id');
        $entries = CourierEarning::where('order_id', $orderId)->where('status', 'pending')
            ->where('direction', $returnRequestId ? 'return' : 'forward')->lockForUpdate()->get();
        foreach ($entries as $entry) {
            $leg = $legs->get($entry->leg_id);
            $values = $leg && in_array($entry->type, ['pickup', 'transfer', 'delivery', 'failed_attempt'], true)
                ? $this->calculation($leg, $entry->type, $entry, true) : [];
            $entry->update($values + ['status' => 'available', 'available_at' => now()]);
        }
    }

    public function adjustment(LogisticsCompany $company, string $courier, string $type, int $cents, string $reason, string $actor, ?string $parcel = null): CourierEarning
    {
        return DB::transaction(function () use ($company, $courier, $type, $cents, $reason, $actor, $parcel) {
            $this->lockCompany($company->id);
            $amount = $type === 'deduction' ? -$cents : $cents;
            $entry = CourierEarning::create(['company_id' => $company->id, 'courier_id' => $courier, 'type' => $type,
                'amount_cents' => $amount, 'remaining_cents' => $amount, 'reason' => $reason, 'parcel_id' => $parcel,
                'status' => 'available', 'available_at' => now(), 'created_by' => $actor]);
            $this->audit($company->id, $actor, $entry->id, 'adjustment_created', $reason, $entry->toArray());

            return $entry;
        });
    }

    public function collectCod(ParcelAssignment $assignment, string $courier): void
    {
        $order = $assignment->order;
        if ($assignment->isReturn() || ! in_array(strtolower((string) $order->payment_method), ['cod', 'cash on delivery', 'cash_on_delivery'], true)) {
            return;
        }
        $this->lockCompany($assignment->logistics_company_id);
        $legs = ParcelAssignment::where('order_id', $order->id)->whereNull('return_request_id')->get();
        $roots = [];
        foreach ($legs as $leg) {
            $roots[$this->parcelRoot($leg, $legs)] = 1;
        }
        ksort($roots);
        $parcel = $this->parcelRoot($assignment, $legs);
        $amount = Money::allocate(Money::toCents($order->total), $roots)[$parcel];
        DB::table('courier_cod_collections')->insertOrIgnore(['id' => (string) Str::uuid(), 'courier_id' => $courier,
            'company_id' => $assignment->logistics_company_id, 'order_id' => $order->id, 'parcel_id' => $parcel,
            'amount_cents' => $amount, 'remaining_cents' => $amount, 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function summary(string $courier, ?LogisticsCompany $company = null): array
    {
        $query = CourierEarning::where('courier_id', $courier)->when($company, fn ($q) => $q->where('company_id', $company->id));
        $totals = (clone $query)->selectRaw('status, SUM(remaining_cents) as remaining, SUM(amount_cents - remaining_cents) as settled')->groupBy('status')->get()->keyBy('status');
        $owed = (int) DB::table('courier_cod_collections')->where('courier_id', $courier)->when($company, fn ($q) => $q->where('company_id', $company->id))->sum('remaining_cents');
        $available = (int) ($totals->get('available')?->remaining ?? 0);

        return ['pending_cents' => (int) ($totals->get('pending')?->remaining ?? 0), 'available_cents' => $available,
            'paid_cents' => (int) $totals->sum('settled'), 'cod_owed_cents' => $owed, 'net_cents' => max(0, $available - $owed),
            'early_cashout_minimum_cents' => $company?->early_cashout_minimum_cents];
    }

    private function lockCompany(string $id): LogisticsCompany
    {
        return LogisticsCompany::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function collections(string $company, string $courier)
    {
        return DB::table('courier_cod_collections')->where('company_id', $company)->where('courier_id', $courier)
            ->where('remaining_cents', '>', 0)->orderBy('collected_at')->orderBy('id');
    }

    private function assertNotOverdue(LogisticsCompany $company, string $courier): void
    {
        if ($this->collections($company->id, $courier)->where('collected_at', '<', now()->subDays($company->cod_overdue_days ?? 2))->exists()) {
            throw ValidationException::withMessages(['cod' => 'Overdue COD cash must be handed over before payout.']);
        }
    }

    public function statement(LogisticsCompany $company, string $courier, string $actor, string $start, string $end, bool $early = false): CourierPayout
    {
        return DB::transaction(function () use ($company, $courier, $actor, $start, $end, $early) {
            $company = $this->lockCompany($company->id);
            $this->assertNotOverdue($company, $courier);
            if (CourierPayout::where('company_id', $company->id)->where('courier_id', $courier)->whereIn('status', ['draft', 'approved'])->exists()) {
                throw ValidationException::withMessages(['payout' => 'Review or cancel the existing unpaid statement first.']);
            }
            $entries = CourierEarning::where('company_id', $company->id)->where('courier_id', $courier)->where('status', 'available')
                ->whereNull('payout_id')->where('available_at', '<=', CarbonImmutable::parse($end, 'Asia/Manila')->endOfDay()->utc())
                ->orderBy('available_at')->orderBy('id')->lockForUpdate()->get();
            $positive = (int) $entries->where('remaining_cents', '>', 0)->sum('remaining_cents');
            $debt = -(int) $entries->where('remaining_cents', '<', 0)->sum('remaining_cents');
            $earnings = max(0, $positive - $debt);
            $offset = min($earnings, (int) $this->collections($company->id, $courier)->sum('remaining_cents'));
            if ($early && ($company->early_cashout_minimum_cents === null || $earnings - $offset < $company->early_cashout_minimum_cents)) {
                throw ValidationException::withMessages(['balance' => 'Early cash-out is disabled or your net balance is below the company minimum.']);
            }
            if ($positive === 0) {
                throw ValidationException::withMessages(['balance' => 'No available earnings to include.']);
            }
            $payout = CourierPayout::create(['company_id' => $company->id, 'courier_id' => $courier, 'created_by' => $actor,
                'period_start' => $start, 'period_end' => $end, 'kind' => $early ? 'early' : 'weekly',
                'earnings_cents' => $earnings, 'cod_offset_cents' => $offset, 'net_cents' => $earnings - $offset]);
            $deductible = $positive;
            foreach ($entries as $entry) {
                $amount = $entry->remaining_cents;
                if ($amount < 0) {
                    $amount = -min(-$amount, $deductible);
                    $deductible += $amount;
                }
                if ($amount === 0) {
                    continue;
                }
                $payout->lines()->create(['earning_id' => $entry->id, 'amount_cents' => $amount]);
                $entry->update(['payout_id' => $payout->id]);
            }
            $this->audit($company->id, $actor, $payout->id, 'statement_created', 'Payout statement prepared', $payout->toArray());

            return $payout->load('lines.earning');
        });
    }

    public function payoutAction(CourierPayout $payout, string $action, string $actor, ?string $reference = null): CourierPayout
    {
        return DB::transaction(function () use ($payout, $action, $actor, $reference) {
            $company = $this->lockCompany($payout->company_id);
            $payout = CourierPayout::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($action === 'cancel' && in_array($payout->status, ['draft', 'approved'], true)) {
                CourierEarning::where('payout_id', $payout->id)->update(['payout_id' => null]);
                $payout->update(['status' => 'cancelled']);
            } else {
                $this->assertNotOverdue($company, $payout->courier_id);
                $offset = min($payout->earnings_cents, (int) $this->collections($company->id, $payout->courier_id)->sum('remaining_cents'));
                if ($offset !== $payout->cod_offset_cents) {
                    throw ValidationException::withMessages(['payout' => 'COD balance changed. Cancel this statement and prepare a new one.']);
                }
                if ($action === 'approve' && $payout->status === 'draft') {
                    $payout->update(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now()]);
                } elseif ($action === 'pay' && $payout->status === 'approved' && filled($reference)) {
                    if ($offset > 0) {
                        $this->remit($company, $payout->courier_id, $offset, $actor, $reference, $payout->id);
                    }
                    foreach ($payout->load('lines.earning')->lines as $line) {
                        $entry = $line->earning;
                        $remaining = $entry->remaining_cents - $line->amount_cents;
                        $entry->update(['remaining_cents' => $remaining, 'status' => $remaining === 0 ? 'paid' : 'available', 'payout_id' => null]);
                    }
                    $payout->update(['status' => 'paid', 'paid_by' => $actor, 'paid_at' => now(), 'reference' => $reference]);
                } else {
                    throw ValidationException::withMessages(['payout' => 'This payout action is not valid from its current status.']);
                }
            }
            $this->audit($company->id, $actor, $payout->id, 'payout_'.$action, $reference ?? $action, $payout->toArray());

            return $payout;
        });
    }

    public function remit(LogisticsCompany $company, string $courier, int $amount, string $actor, string $reference, ?string $payoutId = null): void
    {
        DB::transaction(function () use ($company, $courier, $amount, $actor, $reference, $payoutId) {
            $this->lockCompany($company->id);
            $collections = $this->collections($company->id, $courier)->lockForUpdate()->get();
            if ($amount <= 0 || $amount > (int) $collections->sum('remaining_cents')) {
                throw ValidationException::withMessages(['amount_cents' => 'Remittance must be positive and cannot exceed COD owed.']);
            }
            foreach ($collections as $collection) {
                $part = min($amount, $collection->remaining_cents);
                DB::table('courier_cod_remittances')->insert(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'courier_id' => $courier,
                    'collection_id' => $collection->id, 'payout_id' => $payoutId, 'amount_cents' => $part,
                    'recorded_by' => $actor, 'reference' => $reference, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('courier_cod_collections')->where('id', $collection->id)->update(['remaining_cents' => $collection->remaining_cents - $part, 'updated_at' => now()]);
                $amount -= $part;
                if ($amount === 0) {
                    break;
                }
            }
            $this->audit($company->id, $actor, $payoutId ?? $collection->id, 'cod_remitted', $reference, ['courier_id' => $courier]);
        });
    }

    public function audit(string $company, string $actor, string $entity, string $action, string $reason, array $snapshot): void
    {
        DB::table('courier_earning_audits')->insert(['company_id' => $company, 'actor_id' => $actor, 'entity_id' => $entity,
            'action' => $action, 'reason' => $reason, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
