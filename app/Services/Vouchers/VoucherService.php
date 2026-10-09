<?php

namespace App\Services\Vouchers;

use App\Models\BuyerVoucher;
use App\Models\Order;
use App\Models\Product;
use App\Models\Profile;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Seller vouchers end to end: seller lifecycle (create, deactivate,
 * reactivate, add stock, delete), buyer claim/wallet, per-seller-order
 * quoting (best discount + shipping combination), atomic redemption
 * inside the checkout transaction, and release on cancellation.
 *
 * Rules: max 1 discount + 1 shipping voucher per seller order; a
 * non-stackable voucher must be the only voucher on its order; when
 * several apply, the buyer gets the best value. Cancelled orders give
 * usage, budget and the per-user count back; refunds/returns don't.
 */
class VoucherService
{
    // No 0/O/1/I so codes read cleanly.
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    // ─── Seller ────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $data  validated by the controller */
    public function create(Profile $seller, array $data): Voucher
    {
        $type = $data['type'];
        $scope = $data['scope'];
        $productIds = $scope === Voucher::SCOPE_PRODUCT ? $this->ownedProductIds($seller, $data['product_ids'] ?? []) : [];

        // Shipping vouchers waive the whole delivery fee (free shipping).
        if ($type === Voucher::TYPE_SHIPPING) {
            $data = [...$data, 'discount_type' => Voucher::DISCOUNT_PERCENTAGE, 'discount_value' => 100, 'max_discount' => null];
        }
        $isPct = $data['discount_type'] === Voucher::DISCOUNT_PERCENTAGE && $type === Voucher::TYPE_DISCOUNT;

        if ($isPct && (float) $data['discount_value'] > 100) {
            throw ValidationException::withMessages(['discount_value' => 'Percentage must be between 1 and 100.']);
        }
        if ((int) $data['per_user_limit'] > (int) $data['usage_limit']) {
            throw ValidationException::withMessages(['per_user_limit' => "Per-buyer limit can't be more than the total usage limit."]);
        }

        return DB::transaction(function () use ($seller, $data, $type, $scope, $productIds, $isPct) {
            $voucher = Voucher::create([
                'seller_id' => $seller->id,
                'code' => $this->uniqueCode($seller),
                'type' => $type,
                'scope' => $scope,
                'discount_type' => $data['discount_type'],
                'discount_value' => (float) $data['discount_value'],
                'max_discount' => $isPct ? (float) $data['max_discount'] : null, // shipping: null = full fee
                'min_spend' => (float) ($data['min_spend'] ?? 0),
                'starts_at' => Carbon::parse($data['starts_at']),
                'expires_at' => Carbon::parse($data['expires_at']),
                'usage_limit' => (int) $data['usage_limit'],
                'per_user_limit' => (int) $data['per_user_limit'],
                'budget_cap' => (float) $data['budget_cap'],
                'stackable' => (bool) $data['stackable'],
            ]);

            if ($productIds !== []) {
                DB::table('voucher_products')->insert(
                    array_map(fn ($id) => ['voucher_id' => $voucher->id, 'product_id' => $id], $productIds),
                );
            }

            return $voucher;
        });
    }

    /**
     * Live product discount vouchers that already target any of these
     * products with overlapping dates (creation is blocked unless confirmed).
     *
     * @return Collection<int, Voucher> with the overlapping products loaded
     */
    public function overlaps(Profile $seller, array $productIds, Carbon $startsAt, Carbon $expiresAt): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        return Voucher::query()
            ->where('seller_id', $seller->id)
            ->where('type', Voucher::TYPE_DISCOUNT)
            ->where('scope', Voucher::SCOPE_PRODUCT)
            ->whereNull('deactivated_at')
            ->where('expires_at', '>', max(now(), $startsAt))
            ->where('starts_at', '<', $expiresAt)
            ->whereHas('products', fn ($q) => $q->whereIn('products.id', $productIds))
            ->with(['products' => fn ($q) => $q->whereIn('products.id', $productIds)->select('products.id', 'products.name')])
            ->get()
            ->reject(fn (Voucher $v) => $v->isBudgetExhausted())
            ->values();
    }

    public function deactivate(Voucher $voucher): Voucher
    {
        $allowed = [Voucher::STATUS_ACTIVE, Voucher::STATUS_SCHEDULED, Voucher::STATUS_FULLY_REDEEMED, Voucher::STATUS_UNAVAILABLE];
        if (! in_array($voucher->status(), $allowed, true)) {
            throw ValidationException::withMessages(['voucher' => 'Only live vouchers can be deactivated.']);
        }

        $voucher->forceFill(['deactivated_at' => now()])->save();

        return $voucher;
    }

    public function reactivate(Voucher $voucher): Voucher
    {
        if ($voucher->status() !== Voucher::STATUS_DEACTIVATED) {
            throw ValidationException::withMessages(['voucher' => $voucher->status() === Voucher::STATUS_EXPIRED
                ? "This voucher has expired or used up its budget and can't be reactivated."
                : 'This voucher is not deactivated.']);
        }

        $voucher->forceFill(['deactivated_at' => null])->save();

        return $voucher;
    }

    /** Fully Redeemed only: raise the total usage limit, which reactivates it. */
    public function addStock(Voucher $voucher, int $usageLimit): Voucher
    {
        return DB::transaction(function () use ($voucher, $usageLimit) {
            $voucher = Voucher::query()->lockForUpdate()->findOrFail($voucher->id);

            if ($voucher->status() !== Voucher::STATUS_FULLY_REDEEMED) {
                throw ValidationException::withMessages(['usage_limit' => 'Stock can only be added to fully redeemed vouchers.']);
            }
            if ($usageLimit <= (int) $voucher->usage_limit) {
                throw ValidationException::withMessages(['usage_limit' => "New limit must be more than {$voucher->usage_limit}."]);
            }

            $voucher->forceFill(['usage_limit' => $usageLimit, 'deactivated_at' => null])->save();

            return $voucher;
        });
    }

    /** Permanent; only for vouchers that were never redeemed (even if since cancelled). */
    public function delete(Voucher $voucher): void
    {
        DB::transaction(function () use ($voucher) {
            if (VoucherRedemption::where('voucher_id', $voucher->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['voucher' => 'This voucher has been redeemed and cannot be deleted. You can deactivate it instead.']);
            }

            DB::table('voucher_products')->where('voucher_id', $voucher->id)->delete();
            DB::table('voucher_categories')->where('voucher_id', $voucher->id)->delete();
            BuyerVoucher::where('voucher_id', $voucher->id)->delete();
            $voucher->delete();
        });
    }

    /** @return array<string, mixed> */
    public function presentForSeller(Voucher $v): array
    {
        $status = $v->status();

        return [
            ...$this->presentTerms($v),
            'status' => $status,
            'expiredOn' => $status === Voucher::STATUS_EXPIRED ? $v->expiredOn()->toIso8601String() : null,
            'usedCount' => $v->used_count,
            'budgetCap' => $v->budget_cap !== null ? (float) $v->budget_cap : null,
            'budgetUsed' => (float) $v->budget_used,
            'productsCount' => (int) ($v->products_count ?? 0),
            'unavailableCount' => (int) ($v->unavailable_products_count ?? 0),
            'productPreview' => $v->relationLoaded('products') ? $v->products->pluck('name')->all() : [],
            'canDelete' => (int) ($v->redemptions_count ?? 1) === 0,
            'createdAt' => $v->created_at?->toIso8601String(),
        ];
    }

    // ─── Buyer: discovery, claim, wallet ───────────────────────────────

    /**
     * Claimable vouchers shown on a product page: product vouchers that
     * include it, then the shop's discount and shipping vouchers. Nothing
     * when the product itself can't be bought.
     *
     * @return Collection<int, Voucher>
     */
    public function claimableForProduct(Product $product): Collection
    {
        if ($product->status !== 'active' || (int) $product->stock <= 0) {
            return collect();
        }

        return $this->live()
            ->with('seller.sellerDetail')
            ->where('seller_id', $product->seller_id)
            ->where(fn ($q) => $q->where('scope', Voucher::SCOPE_SHOP)
                ->orWhereHas('products', fn ($p) => $p->where('products.id', $product->id)))
            ->get()
            ->filter(fn (Voucher $v) => $v->isUsable())
            ->sortBy(fn (Voucher $v) => [$this->rank($v), $v->expires_at->timestamp])
            ->values();
    }

    /** @return Collection<int, Voucher> Active shop-wide vouchers for a storefront. */
    public function claimableForStore(string $sellerId): Collection
    {
        return $this->live()
            ->with('seller.sellerDetail')
            ->where('seller_id', $sellerId)
            ->where('scope', Voucher::SCOPE_SHOP)
            ->orderBy('expires_at')
            ->get()
            ->filter(fn (Voucher $voucher) => $voucher->isUsable())
            ->values();
    }

    public function claim(Profile $buyer, string $voucherId): BuyerVoucher
    {
        return DB::transaction(function () use ($buyer, $voucherId) {
            $voucher = Voucher::query()->lockForUpdate()->find($voucherId);

            if (! $voucher || ! $voucher->isUsable()) {
                throw ValidationException::withMessages(['voucher' => 'This voucher has expired or is no longer available.']);
            }

            $exists = BuyerVoucher::where('buyer_profile_id', $buyer->id)->where('voucher_id', $voucher->id)->exists();
            if ($exists) {
                throw ValidationException::withMessages(['voucher' => 'You already claimed this voucher.']);
            }

            return BuyerVoucher::create([
                'buyer_profile_id' => $buyer->id,
                'voucher_id' => $voucher->id,
                'claimed_at' => now(),
            ])->setRelation('voucher', $voucher);
        });
    }

    /** @return Collection<int, BuyerVoucher> newest first, with voucher, shop and first product */
    public function wallet(Profile $buyer): Collection
    {
        return BuyerVoucher::with([
            'voucher' => fn ($q) => $q->withCount('products'),
            'voucher.seller.sellerDetail',
            'voucher.products' => fn ($q) => $q->select('products.id', 'products.name')->limit(1),
        ])
            ->where('buyer_profile_id', $buyer->id)
            ->whereHas('voucher')
            ->latest('claimed_at')
            ->limit(200)
            ->get();
    }

    /** @return array<string, int> voucher id => this buyer's live (non-cancelled) redemptions */
    public function usageByBuyer(Profile $buyer, array $voucherIds): array
    {
        if ($voucherIds === []) {
            return [];
        }

        return VoucherRedemption::query()
            ->where('buyer_profile_id', $buyer->id)
            ->whereIn('voucher_id', $voucherIds)
            ->whereNull('released_at')
            ->groupBy('voucher_id')
            ->selectRaw('voucher_id, COUNT(DISTINCT COALESCE(checkout_id, order_id)) as uses') // a platform voucher split over seller orders is one use
            ->pluck('uses', 'voucher_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    public function walletStatus(Voucher $v, int $usedByBuyer): string
    {
        return match (true) {
            $usedByBuyer >= $v->per_user_limit => BuyerVoucher::STATUS_USED,
            $v->isUsable() => BuyerVoucher::STATUS_AVAILABLE,
            default => BuyerVoucher::STATUS_EXPIRED,
        };
    }

    /** Claimable voucher (product page). Keeps the legacy coupon keys for the mobile app. */
    public function presentClaimable(Voucher $v, ?Product $product = null): array
    {
        return [
            ...$this->presentTerms($v),
            'productId' => $v->isProductScoped() ? $product?->id : null,
            'shopName' => $v->shopName(),
            'usedCount' => $v->used_count,
            'status' => $v->isUsable() ? 'active' : 'expired',
            // Discount vouchers preview the saving on one unit of this product.
            'discount' => $product && $v->type === Voucher::TYPE_DISCOUNT ? $v->discountOn((float) $product->price) : null,
        ];
    }

    /** Wallet entry: `id` is the buyer_vouchers id (what legacy checkout payloads send). */
    public function presentWalletEntry(BuyerVoucher $bc, int $usedByBuyer = 0): array
    {
        $v = $bc->voucher;
        $first = $v->relationLoaded('products') ? $v->products->first() : null;

        return [
            ...$this->presentTerms($v),
            'id' => $bc->id,
            'voucherId' => $v->id,
            'couponId' => $v->id,
            'sellerId' => $v->seller_id,
            'shopName' => $v->shopName(),
            'productId' => $v->isProductScoped() ? $first?->id : null,
            'productName' => $v->isProductScoped() ? $first?->name : null,
            'productsCount' => (int) ($v->products_count ?? 0),
            'status' => $this->walletStatus($v, $usedByBuyer),
            'claimedAt' => $bc->claimed_at?->toIso8601String(),
        ];
    }

    // ─── Buyer: checkout quote & redemption ────────────────────────────

    /**
     * Applicable wallet vouchers per seller order, valued against the cart,
     * plus the best combination (auto-applied).
     *
     * @param  list<array{key: string, product_id: string, seller_id: string, subtotal: float}>  $lines
     * @return array<string, array<string, mixed>> seller id => quote
     */
    public function quote(Profile $buyer, array $lines, float $shippingFee): array
    {
        $bySeller = collect($lines)->groupBy('seller_id');
        $vouchers = $this->walletVouchers($buyer, $bySeller->keys()->all());
        $eligible = $this->eligibleProducts($vouchers, array_column($lines, 'product_id'));

        $result = [];
        foreach ($bySeller as $sellerId => $sellerLines) {
            $evaluation = $this->evaluate($vouchers->where('seller_id', $sellerId), $sellerLines->all(), $shippingFee, $eligible);
            $best = $this->pickBest($evaluation, (float) $sellerLines->sum('subtotal'));

            $present = fn (array $o) => [
                ...$this->presentTerms($o['voucher']),
                'amount' => $o['amount'],
                'applicable' => $o['amount'] > 0,
                'reason' => $o['reason'],
            ];

            $result[$sellerId] = [
                'lineKeys' => $sellerLines->pluck('key')->values()->all(),
                'discount' => array_map($present, $evaluation['discount']),
                'shipping' => array_map($present, $evaluation['shipping']),
                'best' => [
                    'discountId' => $best['discount']['voucher']->id ?? null,
                    'shippingId' => $best['shipping']['voucher']->id ?? null,
                    'savings' => $best['total'],
                ],
            ];
        }

        return $result;
    }

    /**
     * Inside the checkout transaction: lock and validate the vouchers the
     * buyer chose for one seller order and compute the applied amounts.
     *
     * @param  list<array{product_id: string, subtotal: float}>  $lines  indexed like the order lines
     * @return array{discount: ?array{voucher: Voucher, amount: float, allocations: array<int, float>}, shipping: ?array{voucher: Voucher, amount: float}}
     */
    public function applySelection(Profile $buyer, string $sellerId, array $lines, float $shippingFee, ?string $discountId, ?string $shippingId): array
    {
        $ids = array_values(array_filter([$discountId, $shippingId]));
        if ($ids === []) {
            return ['discount' => null, 'shipping' => null];
        }

        $vouchers = Voucher::query()->lockForUpdate()->whereIn('id', $ids)->get()->keyBy('id');
        $claimed = BuyerVoucher::where('buyer_profile_id', $buyer->id)->whereIn('voucher_id', $ids)->pluck('voucher_id')->all();
        $used = $this->usageByBuyer($buyer, $ids);

        foreach (['discount' => $discountId, 'shipping' => $shippingId] as $slot => $id) {
            if ($id === null) {
                continue;
            }
            $v = $vouchers->get($id);
            if (! $v || $v->seller_id !== $sellerId || $v->type !== $slot || ! in_array($id, $claimed, true)) {
                throw ValidationException::withMessages(['vouchers' => 'A selected voucher is not in your wallet for this shop.']);
            }
            if (! $v->isUsable()) {
                throw ValidationException::withMessages(['vouchers' => "Voucher {$v->code} has expired or run out. Remove it and try again."]);
            }
            if (($used[$id] ?? 0) >= $v->per_user_limit) {
                throw ValidationException::withMessages(['vouchers' => "You've already used voucher {$v->code}."]);
            }
        }

        $discount = $discountId ? $vouchers[$discountId] : null;
        $shipping = $shippingId ? $vouchers[$shippingId] : null;
        if ($discount && $shipping && (! $discount->stackable || ! $shipping->stackable)) {
            $solo = ! $discount->stackable ? $discount : $shipping;
            throw ValidationException::withMessages(['vouchers' => "Voucher {$solo->code} can't be combined with other vouchers."]);
        }

        $evaluation = $this->evaluate($vouchers, $lines, $shippingFee, $this->eligibleProducts($vouchers, array_column($lines, 'product_id')));
        $subtotal = (float) array_sum(array_column($lines, 'subtotal'));
        $result = ['discount' => null, 'shipping' => null];

        if ($discount) {
            $option = collect($evaluation['discount'])->firstWhere('voucher.id', $discount->id);
            if (! $option || $option['amount'] <= 0) {
                throw ValidationException::withMessages(['vouchers' => "Voucher {$discount->code} doesn't apply to this order".(($option['reason'] ?? null) ? " ({$option['reason']})." : '.')]);
            }
            $result['discount'] = [
                'voucher' => $discount,
                'amount' => $option['amount'],
                'allocations' => $this->allocate($option['amount'], $lines, $option['lines']),
            ];
        }

        if ($shipping) {
            $option = collect($evaluation['shipping'])->firstWhere('voucher.id', $shipping->id);
            // Total discount never exceeds the goods amount (see PaymentSplitter).
            $amount = round(min($option['amount'] ?? 0, $subtotal - ($result['discount']['amount'] ?? 0)), 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['vouchers' => "Voucher {$shipping->code} doesn't apply to this order".(($option['reason'] ?? null) ? " ({$option['reason']})." : '.')]);
            }
            $result['shipping'] = ['voucher' => $shipping, 'amount' => $amount];
        }

        return $result;
    }

    /** Counts one redemption against the (already locked) voucher. Idempotent per order. */
    public function redeem(Order $order, Profile $buyer, Voucher $voucher, float $amount, ?string $checkoutId = null): void
    {
        // A platform voucher spread over several seller orders of one
        // checkout counts as a single use; budget counts every share.
        $firstOfCheckout = $checkoutId === null || ! VoucherRedemption::where('voucher_id', $voucher->id)
            ->where('checkout_id', $checkoutId)->exists();

        $created = VoucherRedemption::firstOrCreate(
            ['order_id' => $order->id, 'voucher_id' => $voucher->id],
            ['buyer_profile_id' => $buyer->id, 'amount' => $amount, 'checkout_id' => $checkoutId],
        )->wasRecentlyCreated;

        if ($created) {
            $voucher->forceFill([
                'used_count' => $voucher->used_count + ($firstOfCheckout ? 1 : 0),
                'budget_used' => round((float) $voucher->budget_used + $amount, 2),
            ])->save();
        }
    }

    /** Cancelled order: give back usage, budget and the buyer's per-user count. */
    public function releaseForOrder(Order $order): void
    {
        VoucherRedemption::query()->lockForUpdate()
            ->where('order_id', $order->id)
            ->whereNull('released_at')
            ->get()
            ->each(function (VoucherRedemption $r) {
                $voucher = Voucher::query()->lockForUpdate()->find($r->voucher_id);
                $r->forceFill(['released_at' => now()])->save();

                // The use comes back only once every order of that checkout is cancelled.
                $useReturned = $r->checkout_id === null || ! VoucherRedemption::where('voucher_id', $r->voucher_id)
                    ->where('checkout_id', $r->checkout_id)->whereNull('released_at')->exists();

                $voucher?->forceFill([
                    'used_count' => max(0, $voucher->used_count - ($useReturned ? 1 : 0)),
                    'budget_used' => max(0.0, round((float) $voucher->budget_used - (float) $r->amount, 2)),
                ])->save();
            });
    }

    // ─── Legacy coupon API (mobile app, one release cycle) ─────────────

    /**
     * Per-line product-voucher options (old /coupons/quote shape). `id` is
     * the wallet entry id; at most one auto-pick per seller order.
     *
     * @param  list<array{key: string, product_id: string, seller_id: string, unit_price: float}>  $lines
     */
    public function legacyQuote(Profile $buyer, array $lines): array
    {
        $vouchers = $this->walletVouchers($buyer, array_unique(array_column($lines, 'seller_id')))
            ->where('type', Voucher::TYPE_DISCOUNT)->where('scope', Voucher::SCOPE_PRODUCT);
        $eligible = $this->eligibleProducts($vouchers, array_column($lines, 'product_id'));
        $wallet = BuyerVoucher::where('buyer_profile_id', $buyer->id)->whereIn('voucher_id', $vouchers->keys())
            ->get()->keyBy('voucher_id');

        $sellersTaken = [];
        $result = [];
        foreach ($lines as $line) {
            $options = $vouchers
                ->filter(fn (Voucher $v) => in_array($line['product_id'], $eligible[$v->id] ?? [], true))
                ->map(fn (Voucher $v) => [
                    ...$this->presentWalletEntry($wallet[$v->id]->setRelation('voucher', $v)),
                    'productId' => $line['product_id'],
                    'discount' => $v->discountOn((float) $line['unit_price']),
                ])
                ->sort(fn ($a, $b) => [$b['discount'], $a['expiresAt']] <=> [$a['discount'], $b['expiresAt']])
                ->values()->all();

            $best = isset($sellersTaken[$line['seller_id']]) ? null : collect($options)->first(fn ($o) => $o['discount'] > 0);
            if ($best) {
                $sellersTaken[$line['seller_id']] = true;
            }

            $result[$line['key']] = ['best' => $best['id'] ?? null, 'options' => $options];
        }

        return $result;
    }

    /** @return array<string, string> voucher id => seller id, for legacy items.*.coupon_id wallet ids */
    public function vouchersForWalletIds(Profile $buyer, array $walletIds): array
    {
        if ($walletIds === []) {
            return [];
        }

        return BuyerVoucher::with('voucher:id,seller_id')
            ->where('buyer_profile_id', $buyer->id)
            ->whereIn('id', $walletIds)
            ->get()
            ->filter(fn (BuyerVoucher $bc) => $bc->voucher)
            ->mapWithKeys(fn (BuyerVoucher $bc) => [$bc->voucher->id => $bc->voucher->seller_id])
            ->all();
    }

    // ─── Internals ─────────────────────────────────────────────────────

    /** Fields every presentation shares. */
    public function presentTerms(Voucher $v): array
    {
        return [
            'id' => $v->id,
            'code' => $v->code,
            'type' => $v->type,
            'scope' => $v->scope,
            'label' => $v->label(),
            'discountType' => $v->discount_type,
            'discountValue' => (float) $v->discount_value,
            'maxDiscount' => $v->max_discount !== null ? (float) $v->max_discount : null,
            'minSpend' => (float) $v->min_spend,
            'startsAt' => $v->starts_at->toIso8601String(),
            'expiresAt' => $v->expires_at->toIso8601String(),
            'usageLimit' => $v->usage_limit,
            'perUserLimit' => $v->per_user_limit,
            'remaining' => $v->remaining(),
            'runningLow' => $v->isRunningLow(),
            'stackable' => $v->stackable,
        ];
    }

    /** Not past the end date, started and not deactivated (usage/budget checked in PHP). */
    private function live()
    {
        return Voucher::query()
            ->whereNull('deactivated_at')
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>', now());
    }

    /** Product voucher → shop discount → shipping (checkout priority order). */
    private function rank(Voucher $v): int
    {
        return match (true) {
            $v->type === Voucher::TYPE_SHIPPING => 2,
            $v->isProductScoped() => 0,
            default => 1,
        };
    }

    /** @return Collection<string, Voucher> usable vouchers the buyer claimed and can still use */
    private function walletVouchers(Profile $buyer, array $sellerIds): Collection
    {
        $vouchers = $this->live()
            ->with('seller.sellerDetail')
            ->whereIn('seller_id', $sellerIds)
            ->whereIn('id', BuyerVoucher::where('buyer_profile_id', $buyer->id)->select('voucher_id'))
            ->get()
            ->filter(fn (Voucher $v) => $v->isUsable())
            ->keyBy('id');

        $used = $this->usageByBuyer($buyer, $vouchers->keys()->all());

        return $vouchers->filter(fn (Voucher $v) => ($used[$v->id] ?? 0) < $v->per_user_limit);
    }

    /** @return array<string, list<string>> product-voucher id => the given products it covers */
    private function eligibleProducts(Collection $vouchers, array $productIds): array
    {
        $ids = $vouchers->filter(fn (Voucher $v) => $v->isProductScoped())->keys()->all();
        if ($ids === []) {
            return [];
        }

        return DB::table('voucher_products')
            ->whereIn('voucher_id', $ids)
            ->whereIn('product_id', array_unique($productIds))
            ->get()
            ->groupBy('voucher_id')
            ->map(fn ($rows) => $rows->pluck('product_id')->all())
            ->all();
    }

    /**
     * Values each voucher against one seller order's lines. Product
     * vouchers with no matching line are left out entirely.
     *
     * @return array{discount: list<array>, shipping: list<array>}
     */
    private function evaluate(Collection $vouchers, array $lines, float $shippingFee, array $eligible): array
    {
        $subtotal = round((float) array_sum(array_column($lines, 'subtotal')), 2);
        $out = ['discount' => [], 'shipping' => []];

        foreach ($vouchers as $v) {
            $lineIdx = array_keys($lines);
            if ($v->isProductScoped()) {
                $covered = $eligible[$v->id] ?? [];
                $lineIdx = array_keys(array_filter($lines, fn ($l) => in_array($l['product_id'], $covered, true)));
                if ($lineIdx === []) {
                    continue;
                }
            }

            $spend = round((float) array_sum(array_map(fn ($i) => $lines[$i]['subtotal'], $lineIdx)), 2);
            $short = round((float) $v->min_spend - $spend, 2);
            $base = $v->type === Voucher::TYPE_SHIPPING ? $shippingFee : $spend;
            $amount = $short > 0 ? 0.0 : $v->discountOn($base);

            $out[$v->type][] = [
                'voucher' => $v,
                'amount' => $v->type === Voucher::TYPE_SHIPPING ? min($amount, $subtotal) : $amount,
                'lines' => $lineIdx,
                'reason' => $short > 0 ? 'Spend ₱'.number_format($short, 2).' more' : null,
            ];
        }

        foreach ($out as &$options) {
            usort($options, fn ($a, $b) => [$b['amount'], $this->rank($a['voucher']), $a['voucher']->expires_at->timestamp]
                <=> [$a['amount'], $this->rank($b['voucher']), $b['voucher']->expires_at->timestamp]);
        }

        return $out;
    }

    /** Best value for the buyer within the 1 discount + 1 shipping and stacking rules. */
    private function pickBest(array $evaluation, float $subtotal): array
    {
        $discounts = array_values(array_filter($evaluation['discount'], fn ($o) => $o['amount'] > 0));
        $shipping = array_values(array_filter($evaluation['shipping'], fn ($o) => $o['amount'] > 0));

        $candidates = [['discount' => null, 'shipping' => null]];
        foreach ($discounts as $d) {
            $candidates[] = ['discount' => $d, 'shipping' => null];
        }
        foreach ($shipping as $s) {
            $candidates[] = ['discount' => null, 'shipping' => $s];
            foreach ($discounts as $d) {
                if ($d['voucher']->stackable && $s['voucher']->stackable) {
                    $candidates[] = ['discount' => $d, 'shipping' => $s];
                }
            }
        }

        $best = null;
        foreach ($candidates as $c) {
            $d = $c['discount']['amount'] ?? 0.0;
            $c['total'] = round($d + min($c['shipping']['amount'] ?? 0.0, $subtotal - $d), 2);
            if ($best === null || $c['total'] > $best['total']) {
                $best = $c;
            }
        }

        return $best;
    }

    /** Splits $amount over the given lines by subtotal; the last line takes the rounding. */
    private function allocate(float $amount, array $lines, array $lineIdx): array
    {
        $base = array_sum(array_map(fn ($i) => $lines[$i]['subtotal'], $lineIdx));
        $out = [];
        $left = $amount;

        foreach (array_values($lineIdx) as $n => $i) {
            $share = $n === count($lineIdx) - 1 ? round($left, 2) : round($amount * $lines[$i]['subtotal'] / $base, 2);
            $out[$i] = $share;
            $left -= $share;
        }

        return $out;
    }

    /** @return list<string> */
    private function ownedProductIds(Profile $seller, array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            throw ValidationException::withMessages(['product_ids' => 'Select at least one product.']);
        }
        if (count($ids) > Voucher::MAX_PRODUCTS) {
            throw ValidationException::withMessages(['product_ids' => 'A product voucher can cover at most '.Voucher::MAX_PRODUCTS.' products.']);
        }
        if (Product::where('seller_id', $seller->id)->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['product_ids' => 'Some selected products are not in your shop.']);
        }

        return $ids;
    }

    private function uniqueCode(Profile $seller): string
    {
        do {
            $code = $this->randomCode();
        } while (Voucher::where('seller_id', $seller->id)->where('code', $code)->exists());

        return $code;
    }

    /** "BTW" + 7 chars; unique among platform vouchers (seller_id is null there). */
    public function uniquePlatformCode(): string
    {
        do {
            $code = 'BTW'.substr($this->randomCode(), 0, 7);
        } while (Voucher::where('source', Voucher::SOURCE_PLATFORM)->where('code', $code)->exists());

        return $code;
    }

    private function randomCode(): string
    {
        return implode('', array_map(fn ($b) => self::CODE_ALPHABET[ord($b) % strlen(self::CODE_ALPHABET)], str_split(random_bytes(8))));
    }
}
