<?php

namespace App\Services\Vouchers;

use App\Models\BuyerVoucher;
use App\Models\Order;
use App\Models\Profile;
use App\Models\Voucher;
use App\Support\CategoryFieldConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Platform-funded vouchers: admin creation, buyer eligibility, and the
 * cart-wide checkout pass that runs after seller vouchers.
 *
 * Per checkout: at most 1 platform discount + 1 platform shipping voucher
 * on top of each seller order's own (1 seller discount + 1 shipping).
 * A platform discount is valued on the eligible lines' price after seller
 * discounts and split across seller orders by that base. Platform free
 * shipping covers orders that have no seller shipping voucher. Orders whose
 * seller voucher is non-stackable are left out; a non-stackable platform
 * voucher must be the only voucher in the whole checkout.
 *
 * Context shape used below (one entry per seller order):
 *   seller id => [
 *     'lines' => list<['product_id', 'category', 'subtotal', 'seller_discount']>,
 *     'seller_shipping' => bool,  // a seller shipping voucher is applied
 *     'exclusive' => bool,        // a non-stackable seller voucher is applied
 *     'any_seller_voucher' => bool,
 *   ]
 */
class PlatformVoucherService
{
    public function __construct(private VoucherService $vouchers) {}

    // ─── Admin ─────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $data  validated by the controller */
    public function create(Profile $admin, array $data): Voucher
    {
        $type = $data['type'];
        $categories = $data['scope'] === Voucher::SCOPE_CATEGORY ? $this->validCategories($data['categories'] ?? []) : [];

        if ($type === Voucher::TYPE_SHIPPING) {
            $data = [...$data, 'discount_type' => Voucher::DISCOUNT_PERCENTAGE, 'discount_value' => 100, 'max_discount' => null];
        }
        $isPct = $type === Voucher::TYPE_DISCOUNT && $data['discount_type'] === Voucher::DISCOUNT_PERCENTAGE;

        if ($isPct && (float) $data['discount_value'] > 100) {
            throw ValidationException::withMessages(['discount_value' => 'Percentage must be between 1 and 100.']);
        }
        if ((int) $data['per_user_limit'] > (int) $data['usage_limit']) {
            throw ValidationException::withMessages(['per_user_limit' => "Per-buyer limit can't be more than the total usage limit."]);
        }

        return DB::transaction(function () use ($admin, $data, $type, $categories, $isPct) {
            $voucher = Voucher::create([
                'source' => Voucher::SOURCE_PLATFORM,
                'funding_source' => Voucher::SOURCE_PLATFORM,
                'seller_id' => null,
                'code' => $this->vouchers->uniquePlatformCode(),
                'type' => $type,
                'scope' => $data['scope'],
                'discount_type' => $data['discount_type'],
                'discount_value' => (float) $data['discount_value'],
                'max_discount' => $isPct ? (float) $data['max_discount'] : null,
                'min_spend' => (float) ($data['min_spend'] ?? 0),
                'starts_at' => Carbon::parse($data['starts_at']),
                'expires_at' => Carbon::parse($data['expires_at']),
                'usage_limit' => (int) $data['usage_limit'],
                'per_user_limit' => (int) $data['per_user_limit'],
                'budget_cap' => (float) $data['budget_cap'],
                'stackable' => (bool) $data['stackable'],
                'eligibility' => $data['eligibility'],
                'eligibility_meta' => $this->eligibilityMeta($data),
                'distribution' => $data['distribution'],
                'created_by' => $admin->id,
            ]);

            if ($categories !== []) {
                DB::table('voucher_categories')->insert(
                    array_map(fn ($c) => ['voucher_id' => $voucher->id, 'category' => $c], $categories),
                );
            }

            return $voucher;
        });
    }

    /**
     * Live platform vouchers of the same type targeting any of these
     * categories with overlapping dates (creation blocked unless confirmed).
     *
     * @return Collection<int, array{voucher: Voucher, categories: list<string>}>
     */
    public function overlaps(string $type, array $categories, Carbon $startsAt, Carbon $expiresAt): Collection
    {
        if ($categories === []) {
            return collect();
        }

        $vouchers = Voucher::query()
            ->where('source', Voucher::SOURCE_PLATFORM)
            ->where('type', $type)
            ->where('scope', Voucher::SCOPE_CATEGORY)
            ->whereNull('deactivated_at')
            ->where('expires_at', '>', max(now(), $startsAt))
            ->where('starts_at', '<', $expiresAt)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('voucher_categories as vc')
                ->whereColumn('vc.voucher_id', 'vouchers.id')->whereIn('vc.category', $categories))
            ->get()
            ->reject(fn (Voucher $v) => $v->isBudgetExhausted());

        $shared = DB::table('voucher_categories')->whereIn('voucher_id', $vouchers->pluck('id'))
            ->whereIn('category', $categories)->get()->groupBy('voucher_id');

        return $vouchers->map(fn (Voucher $v) => [
            'voucher' => $v,
            'categories' => $shared->get($v->id, collect())->pluck('category')->all(),
        ])->values();
    }

    /** @return array<string, mixed> */
    public function presentForAdmin(Voucher $v, array $categories = []): array
    {
        $status = $v->status();

        return [
            ...$this->vouchers->presentTerms($v),
            'status' => $status,
            'expiredOn' => $status === Voucher::STATUS_EXPIRED ? $v->expiredOn()->toIso8601String() : null,
            'usedCount' => $v->used_count,
            'budgetCap' => $v->budget_cap !== null ? (float) $v->budget_cap : null,
            'budgetUsed' => (float) $v->budget_used,
            'categories' => $categories,
            'eligibility' => $v->eligibility,
            'eligibilityMeta' => $v->eligibility_meta ?? (object) [],
            'distribution' => $v->distribution,
            'fundingSource' => $v->funding_source,
            'canDelete' => (int) ($v->redemptions_count ?? 1) === 0,
            'createdAt' => $v->created_at?->toIso8601String(),
            'createdBy' => $v->creator?->full_name,
            'deactivatedBy' => $v->deactivated_at ? $v->deactivator?->full_name : null,
        ];
    }

    // ─── Buyer: eligibility & availability ─────────────────────────────

    /** Can this buyer use $v when shipping to $region? */
    public function isEligible(Profile $buyer, Voucher $v, ?string $region): bool
    {
        return match ($v->eligibility) {
            Voucher::ELIGIBILITY_NEW => ! $this->placedOrders($buyer)->exists(),
            Voucher::ELIGIBILITY_LAPSED => $this->placedOrders($buyer)->exists()
                && ! $this->placedOrders($buyer)->where('placed_at', '>=', now()->subDays($this->lapsedDays($v)))->exists(),
            Voucher::ELIGIBILITY_REGION => $region !== null && in_array(
                mb_strtolower(trim($region)),
                array_map(fn ($r) => mb_strtolower(trim($r)), $v->eligibility_meta['regions'] ?? []),
                true,
            ),
            default => true,
        };
    }

    /**
     * Platform vouchers this buyer can use now: live, eligible, per-user
     * uses left, and claimed — unless distributed without a claim step
     * (auto-claim, push and auto-apply reach eligible buyers directly).
     *
     * @return Collection<string, Voucher>
     */
    public function usableFor(Profile $buyer, ?string $region, ?array $onlyIds = null, bool $lock = false): Collection
    {
        $claimed = BuyerVoucher::where('buyer_profile_id', $buyer->id)->select('voucher_id');

        $vouchers = Voucher::query()
            ->where('source', Voucher::SOURCE_PLATFORM)
            ->whereNull('deactivated_at')
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>', now())
            ->when($onlyIds !== null, fn ($q) => $q->whereIn('id', $onlyIds))
            ->where(fn ($q) => $q->where('distribution', '!=', Voucher::DISTRIBUTION_CLAIM)->orWhereIn('id', $claimed))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get()
            ->filter(fn (Voucher $v) => $v->isUsable() && $this->isEligible($buyer, $v, $region))
            ->keyBy('id');

        $used = $this->vouchers->usageByBuyer($buyer, $vouchers->keys()->all());

        return $vouchers->filter(fn (Voucher $v) => ($used[$v->id] ?? 0) < $v->per_user_limit);
    }

    // ─── Buyer: quote & checkout ───────────────────────────────────────

    /**
     * Builds the per-seller-order context (see class docblock) from each
     * order's lines and the seller vouchers applied to it.
     *
     * @param  array<string, list<array{product_id: string, category: ?string, subtotal: float}>>  $linesBySeller
     * @param  array<string, array{discount: ?array, shipping: ?array}>  $sellerApplied
     */
    public static function context(array $linesBySeller, array $sellerApplied): array
    {
        $context = [];
        foreach ($linesBySeller as $sellerId => $lines) {
            $applied = $sellerApplied[$sellerId] ?? ['discount' => null, 'shipping' => null];
            $context[$sellerId] = [
                'lines' => array_map(fn (array $l, int $i) => [
                    ...$l,
                    'seller_discount' => $applied['discount']['allocations'][$i] ?? 0.0,
                ], array_values($lines), array_keys(array_values($lines))),
                'seller_shipping' => $applied['shipping'] !== null,
                'exclusive' => collect([$applied['discount'], $applied['shipping']])->filter()->contains(fn ($u) => ! $u['voucher']->stackable),
                'any_seller_voucher' => $applied['discount'] !== null || $applied['shipping'] !== null,
            ];
        }

        return $context;
    }

    /**
     * Platform options valued against the cart (stackable ones on top of
     * the seller picks in $context; non-stackable ones alone), plus the best
     * pick. `best.exclusive` means the seller vouchers must be dropped.
     */
    public function quote(Profile $buyer, ?string $region, array $context, array $exclusiveContext, float $shippingFee, float $sellerSavings): array
    {
        $vouchers = $this->usableFor($buyer, $region);
        $categories = $this->categoriesFor($vouchers);

        $options = ['discount' => [], 'shipping' => []];
        foreach ($vouchers as $v) {
            $valued = $this->value($v, $v->stackable ? $context : $exclusiveContext, $shippingFee, $categories[$v->id] ?? []);
            $options[$v->type][] = $valued;
        }

        foreach ($options as &$list) {
            usort($list, fn ($a, $b) => [$b['amount'], $a['voucher']->expires_at->timestamp] <=> [$a['amount'], $b['voucher']->expires_at->timestamp]);
        }
        unset($list);

        $best = $this->pickBest($options, $sellerSavings);
        $present = fn (array $o) => [
            ...$this->vouchers->presentTerms($o['voucher']),
            'amount' => $o['amount'],
            'applicable' => $o['amount'] > 0,
            'reason' => $o['reason'],
            'categories' => $categories[$o['voucher']->id] ?? [],
        ];

        return [
            'discount' => array_map($present, $options['discount']),
            'shipping' => array_map($present, $options['shipping']),
            'best' => $best,
        ];
    }

    /**
     * Inside the checkout transaction (after seller vouchers are applied):
     * lock and validate the chosen platform vouchers and split them per
     * seller order.
     *
     * @return array{vouchers: list<Voucher>, orders: array<string, array{discount: float, shipping: float, allocations: array<int, float>, discount_voucher: ?Voucher, shipping_voucher: ?Voucher}>}
     */
    public function applySelection(Profile $buyer, ?string $region, array $context, float $shippingFee, ?string $discountId, ?string $shippingId): array
    {
        $ids = array_values(array_filter([$discountId, $shippingId]));
        $result = ['vouchers' => [], 'orders' => []];
        if ($ids === []) {
            return $result;
        }

        $usable = $this->usableFor($buyer, $region, $ids, lock: true);
        $chosen = [];
        foreach (['discount' => $discountId, 'shipping' => $shippingId] as $slot => $id) {
            if ($id === null) {
                continue;
            }
            $v = $usable->get($id);
            if (! $v || $v->type !== $slot) {
                throw ValidationException::withMessages(['platform_vouchers' => 'A selected BuyTheWay voucher is no longer available to you. Remove it and try again.']);
            }
            $chosen[$slot] = $v;
        }

        $anySeller = collect($context)->contains(fn ($c) => $c['any_seller_voucher']);
        foreach ($chosen as $v) {
            if (! $v->stackable && (count($chosen) > 1 || $anySeller)) {
                throw ValidationException::withMessages(['platform_vouchers' => "Voucher {$v->code} can't be combined with other vouchers."]);
            }
        }

        $categories = $this->categoriesFor(collect($chosen));
        foreach ($chosen as $slot => $v) {
            $valued = $this->value($v, $context, $shippingFee, $categories[$v->id] ?? []);
            if ($valued['amount'] <= 0) {
                throw ValidationException::withMessages(['platform_vouchers' => "Voucher {$v->code} doesn't apply to this order".($valued['reason'] ? " ({$valued['reason']})." : '.')]);
            }

            $result['vouchers'][] = $v;
            foreach ($valued['split'] as $sellerId => $share) {
                $order = $result['orders'][$sellerId] ??= ['discount' => 0.0, 'shipping' => 0.0, 'allocations' => [], 'discount_voucher' => null, 'shipping_voucher' => null];
                $order[$slot] = $share['amount'];
                $order["{$slot}_voucher"] = $v;
                if ($slot === 'discount') {
                    $order['allocations'] = $share['allocations'];
                }
                $result['orders'][$sellerId] = $order;
            }
        }

        return $result;
    }

    // ─── Internals ─────────────────────────────────────────────────────

    /**
     * Values one platform voucher against the cart and splits it per seller
     * order (discount by eligible base, shipping by covered order fees),
     * capped by the remaining budget.
     *
     * @return array{voucher: Voucher, amount: float, reason: ?string, split: array<string, array{amount: float, allocations: array<int, float>}>}
     */
    private function value(Voucher $v, array $context, float $shippingFee, array $categories): array
    {
        $inScope = fn (array $line) => $v->scope !== Voucher::SCOPE_CATEGORY || in_array($line['category'], $categories, true);

        // Eligible net base per seller order and line.
        $bases = [];
        foreach ($context as $sellerId => $c) {
            if ($c['exclusive'] || ($v->type === Voucher::TYPE_SHIPPING && $c['seller_shipping'])) {
                continue;
            }
            foreach ($c['lines'] as $i => $line) {
                if ($inScope($line)) {
                    $bases[$sellerId][$i] = max(0.0, round($line['subtotal'] - $line['seller_discount'], 2));
                }
            }
        }

        $spend = round(array_sum(array_map('array_sum', $bases)), 2);
        $short = round((float) $v->min_spend - $spend, 2);
        $none = ['voucher' => $v, 'amount' => 0.0, 'split' => []];

        if ($bases === []) {
            return [...$none, 'reason' => $v->type === Voucher::TYPE_SHIPPING ? 'No eligible orders' : 'No eligible items'];
        }
        if ($short > 0) {
            return [...$none, 'reason' => 'Spend ₱'.number_format($short, 2).' more'];
        }

        if ($v->type === Voucher::TYPE_SHIPPING) {
            // Free shipping on every covered seller order, within budget.
            $weights = array_fill_keys(array_keys($bases), $shippingFee);
            $total = min($shippingFee * count($weights), $v->budgetRemaining() ?? INF);
            $split = array_map(fn ($amount) => ['amount' => $amount, 'allocations' => []], $this->allocate($total, $weights));
        } else {
            $total = $v->discountOn($spend);
            $perOrder = $this->allocate($total, array_map('array_sum', $bases));
            $split = [];
            foreach ($perOrder as $sellerId => $amount) {
                $split[$sellerId] = ['amount' => $amount, 'allocations' => $this->allocate($amount, $bases[$sellerId])];
            }
        }

        return ['voucher' => $v, 'amount' => round($total, 2), 'reason' => null, 'split' => $split];
    }

    /** Best platform pick vs. the seller savings it may have to replace. */
    private function pickBest(array $options, float $sellerSavings): array
    {
        $stackD = array_values(array_filter($options['discount'], fn ($o) => $o['amount'] > 0 && $o['voucher']->stackable));
        $stackS = array_values(array_filter($options['shipping'], fn ($o) => $o['amount'] > 0 && $o['voucher']->stackable));

        $best = [
            'discountId' => $stackD[0]['voucher']->id ?? null,
            'shippingId' => $stackS[0]['voucher']->id ?? null,
            'savings' => round(($stackD[0]['amount'] ?? 0) + ($stackS[0]['amount'] ?? 0), 2),
            'exclusive' => false,
        ];

        // A non-stackable voucher replaces everything, seller vouchers included.
        foreach ([...$options['discount'], ...$options['shipping']] as $o) {
            if (! $o['voucher']->stackable && $o['amount'] > $sellerSavings + $best['savings']) {
                $slot = $o['voucher']->type === Voucher::TYPE_SHIPPING ? 'shippingId' : 'discountId';
                $best = ['discountId' => null, 'shippingId' => null, $slot => $o['voucher']->id, 'savings' => $o['amount'], 'exclusive' => true];
            }
        }

        return $best;
    }

    /** @return array<string, list<string>> voucher id => categories */
    private function categoriesFor(Collection $vouchers): array
    {
        $ids = $vouchers->filter(fn (Voucher $v) => $v->scope === Voucher::SCOPE_CATEGORY)->pluck('id')->all();

        return $ids === [] ? [] : DB::table('voucher_categories')->whereIn('voucher_id', $ids)->get()
            ->groupBy('voucher_id')->map(fn ($rows) => $rows->pluck('category')->all())->all();
    }

    /** Splits $amount by $weights (same keys); the last key takes the rounding. */
    private function allocate(float $amount, array $weights): array
    {
        $sum = array_sum($weights);
        $keys = array_keys($weights);
        $out = [];
        $left = $amount;

        foreach ($keys as $n => $key) {
            $share = $n === count($keys) - 1 ? round($left, 2) : ($sum > 0 ? round($amount * $weights[$key] / $sum, 2) : 0.0);
            $out[$key] = $share;
            $left -= $share;
        }

        return $out;
    }

    private function placedOrders(Profile $buyer)
    {
        return Order::where('buyer_profile_id', $buyer->id)->where('status', '!=', 'Cancelled');
    }

    private function lapsedDays(Voucher $v): int
    {
        return (int) ($v->eligibility_meta['lapsed_days'] ?? Voucher::DEFAULT_LAPSED_DAYS);
    }

    /** @return list<string> */
    private function validCategories(array $categories): array
    {
        $categories = array_values(array_unique($categories));

        if ($categories === []) {
            throw ValidationException::withMessages(['categories' => 'Select at least one category.']);
        }
        if (count($categories) > Voucher::MAX_CATEGORIES) {
            throw ValidationException::withMessages(['categories' => 'A voucher can cover at most '.Voucher::MAX_CATEGORIES.' categories.']);
        }
        if (array_diff($categories, CategoryFieldConfig::categories()) !== []) {
            throw ValidationException::withMessages(['categories' => 'Some selected categories do not exist.']);
        }

        return $categories;
    }

    private function eligibilityMeta(array $data): ?array
    {
        return match ($data['eligibility']) {
            Voucher::ELIGIBILITY_LAPSED => ['lapsed_days' => (int) ($data['lapsed_days'] ?? Voucher::DEFAULT_LAPSED_DAYS)],
            Voucher::ELIGIBILITY_REGION => ['regions' => array_values(array_unique($data['regions'] ?? []))],
            default => null,
        };
    }
}
