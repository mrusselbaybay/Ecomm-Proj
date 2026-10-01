<?php

namespace App\Services\Coupons;

use App\Models\BuyerCoupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCoupon;
use App\Models\Profile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Seller-funded, product-level coupons: create (in batches), claim into a
 * buyer's wallet, quote/auto-pick the best one per checkout line, redeem
 * inside the checkout transaction, and release on cancellation.
 */
class CouponService
{
    /**
     * @param  list<array{code?: ?string, discount_type: string, discount_value: float|int|string, max_discount?: mixed, usage_limit?: mixed, expires_at: string}>  $rows
     * @return Collection<int, ProductCoupon>
     */
    public function createMany(Profile $seller, Product $product, array $rows): Collection
    {
        $this->assertOwns($seller, $product);

        return DB::transaction(function () use ($seller, $product, $rows) {
            $codes = [];

            return collect($rows)->map(function (array $row, int $i) use ($seller, $product, &$codes) {
                $code = $this->resolveCode($seller, $row['code'] ?? null, $i);

                if (in_array($code, $codes, true)) {
                    throw ValidationException::withMessages(["coupons.{$i}.code" => "Code {$code} is used twice in this batch."]);
                }
                $codes[] = $code;

                return ProductCoupon::create([
                    'product_id' => $product->id,
                    'seller_id' => $seller->id,
                    'code' => $code,
                    'status' => ProductCoupon::STATUS_ACTIVE,
                    ...$this->terms($row, $product, "coupons.{$i}"),
                ]);
            });
        });
    }

    public function update(Profile $seller, ProductCoupon $coupon, array $row): ProductCoupon
    {
        $this->assertOwns($seller, $coupon->product);

        $code = isset($row['code']) && trim((string) $row['code']) !== ''
            ? $this->resolveCode($seller, $row['code'], 0, $coupon->id)
            : $coupon->code;

        $terms = $this->terms($row, $coupon->product, 'coupon');

        if ($terms['usage_limit'] !== null && $terms['usage_limit'] < $coupon->used_count) {
            throw ValidationException::withMessages(['coupon.usage_limit' => "Usage limit can't be below the {$coupon->used_count} already redeemed."]);
        }

        // New terms apply to future use, including coupons already sitting
        // in wallets; a renewed expiry or raised limit re-activates it.
        $coupon->fill(['code' => $code, ...$terms])->fill([
            'status' => $terms['expires_at']->isFuture() ? ProductCoupon::STATUS_ACTIVE : ProductCoupon::STATUS_EXPIRED,
        ])->save();

        if ($coupon->isUsable()) {
            BuyerCoupon::where('product_coupon_id', $coupon->id)
                ->where('status', BuyerCoupon::STATUS_EXPIRED)
                ->update(['status' => BuyerCoupon::STATUS_AVAILABLE]);
        }

        return $coupon;
    }

    /** Soft delete: wallets show it as unusable; past orders keep their recorded discount. */
    public function delete(Profile $seller, ProductCoupon $coupon): void
    {
        $this->assertOwns($seller, $coupon->product);

        DB::transaction(function () use ($coupon) {
            BuyerCoupon::where('product_coupon_id', $coupon->id)
                ->where('status', BuyerCoupon::STATUS_AVAILABLE)
                ->update(['status' => BuyerCoupon::STATUS_EXPIRED]);
            $coupon->delete();
        });
    }

    public function claim(Profile $buyer, string $couponId): BuyerCoupon
    {
        return DB::transaction(function () use ($buyer, $couponId) {
            $coupon = ProductCoupon::query()->lockForUpdate()->find($couponId);

            if (! $coupon || ! $coupon->isUsable()) {
                throw ValidationException::withMessages(['coupon' => 'This coupon has expired or is no longer available.']);
            }

            $exists = BuyerCoupon::where('buyer_profile_id', $buyer->id)
                ->where('product_coupon_id', $coupon->id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages(['coupon' => 'You already claimed this coupon.']);
            }

            return BuyerCoupon::create([
                'buyer_profile_id' => $buyer->id,
                'product_coupon_id' => $coupon->id,
                'status' => BuyerCoupon::STATUS_AVAILABLE,
                'claimed_at' => now(),
            ])->setRelation('coupon', $coupon);
        });
    }

    /**
     * Usable wallet coupons per checkout line, best first: highest
     * discount on one unit, ties broken by soonest expiry. `best` is the
     * auto-apply pick; the same coupon is never auto-applied to two lines.
     *
     * @param  list<array{key: string, product_id: string, unit_price: float}>  $lines
     * @return array<string, array{best: ?string, options: list<array<string, mixed>>}>
     */
    public function quote(Profile $buyer, array $lines): array
    {
        $wallet = BuyerCoupon::with('coupon.product:id,name')
            ->where('buyer_profile_id', $buyer->id)
            ->where('status', BuyerCoupon::STATUS_AVAILABLE)
            ->whereHas('coupon', fn ($q) => $q->whereIn('product_id', array_column($lines, 'product_id')))
            ->get()
            ->filter(fn (BuyerCoupon $bc) => $bc->coupon->isUsable());

        $taken = [];
        $result = [];

        foreach ($lines as $line) {
            $options = $wallet
                ->filter(fn (BuyerCoupon $bc) => $bc->coupon->product_id === $line['product_id'])
                ->map(fn (BuyerCoupon $bc) => [
                    ...$this->presentWalletEntry($bc),
                    'discount' => $bc->coupon->discountFor((float) $line['unit_price']),
                ])
                ->sort(fn ($a, $b) => [$b['discount'], $a['expiresAt']] <=> [$a['discount'], $b['expiresAt']])
                ->values()
                ->all();

            $best = collect($options)->first(fn ($o) => $o['discount'] > 0 && ! in_array($o['id'], $taken, true));
            if ($best) {
                $taken[] = $best['id'];
            }

            $result[$line['key']] = ['best' => $best['id'] ?? null, 'options' => $options];
        }

        return $result;
    }

    /**
     * Locks and validates the coupons chosen for a checkout (inside the
     * caller's transaction). Returns buyer_coupon id => locked rows.
     *
     * @param  list<string>  $buyerCouponIds
     * @param  array<string, string>  $productByCoupon  buyer_coupon id => product id of the line it's applied to
     * @return Collection<string, BuyerCoupon>
     */
    public function lockForRedemption(Profile $buyer, array $productByCoupon): Collection
    {
        $ids = array_keys($productByCoupon);

        if ($ids === []) {
            return collect();
        }

        $wallet = BuyerCoupon::query()->lockForUpdate()->whereIn('id', $ids)->get()->keyBy('id');
        $coupons = ProductCoupon::withTrashed()->lockForUpdate()
            ->whereIn('id', $wallet->pluck('product_coupon_id'))->get()->keyBy('id');

        foreach ($productByCoupon as $id => $productId) {
            $bc = $wallet->get($id);
            $coupon = $bc ? $coupons->get($bc->product_coupon_id) : null;

            if (! $bc || $bc->buyer_profile_id !== $buyer->id || ! $coupon) {
                throw ValidationException::withMessages(['items' => 'A selected coupon is not in your wallet.']);
            }
            if ($coupon->product_id !== $productId) {
                throw ValidationException::withMessages(['items' => "Coupon {$coupon->code} doesn't apply to that item."]);
            }
            if ($bc->status !== BuyerCoupon::STATUS_AVAILABLE) {
                throw ValidationException::withMessages(['items' => "Coupon {$coupon->code} has already been used."]);
            }
            if (! $coupon->isUsable()) {
                throw ValidationException::withMessages(['items' => "Coupon {$coupon->code} has expired or run out. Remove it and try again."]);
            }

            $bc->setRelation('coupon', $coupon);
        }

        return $wallet;
    }

    /** Marks wallet coupons used and counts the redemption. Caller holds the locks. */
    public function markRedeemed(BuyerCoupon $bc, string $orderItemId): void
    {
        $bc->forceFill([
            'status' => BuyerCoupon::STATUS_USED,
            'order_item_id' => $orderItemId,
            'used_at' => now(),
        ])->save();
        $bc->coupon->increment('used_count');
    }

    /** Cancelled order: give the coupons back and free the redemptions. */
    public function releaseForOrder(Order $order): void
    {
        $itemIds = $order->items()->whereNotNull('buyer_coupon_id')->pluck('id');

        BuyerCoupon::query()->lockForUpdate()
            ->whereIn('order_item_id', $itemIds)
            ->where('status', BuyerCoupon::STATUS_USED)
            ->get()
            ->each(function (BuyerCoupon $bc) {
                $coupon = ProductCoupon::withTrashed()->lockForUpdate()->find($bc->product_coupon_id);
                if ($coupon && $coupon->used_count > 0) {
                    $coupon->decrement('used_count');
                }

                $bc->forceFill([
                    'status' => $coupon?->isUsable() ? BuyerCoupon::STATUS_AVAILABLE : BuyerCoupon::STATUS_EXPIRED,
                    'order_item_id' => null,
                    'used_at' => null,
                ])->save();
            });
    }

    /**
     * Idempotent sweep: past-expiry or exhausted coupons → expired, and
     * the still-available wallet copies of them follow.
     *
     * @return array{coupons: int, wallet: int}
     */
    public function expireStale(): array
    {
        $now = now();

        $coupons = ProductCoupon::where('status', ProductCoupon::STATUS_ACTIVE)
            ->where(fn ($q) => $q->where('expires_at', '<', $now)
                ->orWhere(fn ($q) => $q->whereNotNull('usage_limit')->whereColumn('used_count', '>=', 'usage_limit')))
            ->update(['status' => ProductCoupon::STATUS_EXPIRED, 'updated_at' => $now]);

        $wallet = BuyerCoupon::where('status', BuyerCoupon::STATUS_AVAILABLE)
            ->whereIn('product_coupon_id', ProductCoupon::withTrashed()
                ->where(fn ($q) => $q->where('status', ProductCoupon::STATUS_EXPIRED)->orWhereNotNull('deleted_at'))
                ->select('id'))
            ->update(['status' => BuyerCoupon::STATUS_EXPIRED, 'updated_at' => $now]);

        return ['coupons' => $coupons, 'wallet' => $wallet];
    }

    /** @return array<string, mixed> */
    public function presentCoupon(ProductCoupon $c, ?float $unitPrice = null): array
    {
        return [
            'id' => $c->id,
            'productId' => $c->product_id,
            'code' => $c->code,
            'discountType' => $c->discount_type,
            'discountValue' => (float) $c->discount_value,
            'maxDiscount' => $c->max_discount !== null ? (float) $c->max_discount : null,
            'usageLimit' => $c->usage_limit,
            'usedCount' => $c->used_count,
            'remaining' => $c->remaining(),
            'runningLow' => $c->isRunningLow(),
            'label' => $c->label(),
            'expiresAt' => $c->expires_at->toIso8601String(),
            'status' => $c->isUsable() ? 'active' : 'expired',
            'discount' => $unitPrice !== null ? $c->discountFor($unitPrice) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function presentWalletEntry(BuyerCoupon $bc): array
    {
        $c = $bc->coupon;

        return [
            'id' => $bc->id,
            'couponId' => $c->id,
            'productId' => $c->product_id,
            'productName' => $c->product?->name,
            'code' => $c->code,
            'label' => $c->label(),
            'discountType' => $c->discount_type,
            'discountValue' => (float) $c->discount_value,
            'maxDiscount' => $c->max_discount !== null ? (float) $c->max_discount : null,
            'expiresAt' => $c->expires_at->toIso8601String(),
            'remaining' => $c->remaining(),
            'runningLow' => $c->isRunningLow(),
            'status' => $bc->effectiveStatus(),
            'claimedAt' => $bc->claimed_at?->toIso8601String(),
            'usedAt' => $bc->used_at?->toIso8601String(),
        ];
    }

    private function assertOwns(Profile $seller, ?Product $product): void
    {
        if (! $product || $product->seller_id !== $seller->id) {
            abort(404, 'Product not found.');
        }
    }

    private function resolveCode(Profile $seller, ?string $code, int $i, ?string $ignoreId = null): string
    {
        $code = strtoupper(trim((string) $code));

        if ($code === '') {
            do {
                $code = strtoupper(Str::random(8));
            } while (ProductCoupon::withTrashed()->where('seller_id', $seller->id)->where('code', $code)->exists());

            return $code;
        }

        if (! preg_match('/^[A-Z0-9_-]{3,32}$/', $code)) {
            throw ValidationException::withMessages(["coupons.{$i}.code" => 'Codes are 3–32 letters, numbers, - or _.']);
        }

        $taken = ProductCoupon::withTrashed()->where('seller_id', $seller->id)->where('code', $code)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();

        if ($taken) {
            throw ValidationException::withMessages(["coupons.{$i}.code" => "You already have a coupon with code {$code}."]);
        }

        return $code;
    }

    /** @return array{discount_type: string, discount_value: float, max_discount: ?float, usage_limit: ?int, expires_at: \Illuminate\Support\Carbon} */
    private function terms(array $row, Product $product, string $field): array
    {
        $type = $row['discount_type'];
        $value = (float) $row['discount_value'];

        if ($type === ProductCoupon::TYPE_PERCENTAGE && ($value <= 0 || $value > 100)) {
            throw ValidationException::withMessages(["{$field}.discount_value" => 'Percentage must be between 1 and 100.']);
        }
        if ($type === ProductCoupon::TYPE_FIXED && $value <= 0) {
            throw ValidationException::withMessages(["{$field}.discount_value" => 'Amount must be more than ₱0.']);
        }

        return [
            'discount_type' => $type,
            'discount_value' => $value,
            'max_discount' => $type === ProductCoupon::TYPE_PERCENTAGE && filled($row['max_discount'] ?? null)
                ? (float) $row['max_discount'] : null,
            'usage_limit' => filled($row['usage_limit'] ?? null) ? (int) $row['usage_limit'] : null,
            'expires_at' => \Illuminate\Support\Carbon::parse($row['expires_at']),
        ];
    }
}
