<?php

use App\Models\BuyerCoupon;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturnRequest;
use App\Models\Product;
use App\Models\ProductCoupon;
use App\Models\Profile;
use App\Services\Coupons\CouponService;
use App\Services\Payments\MockPaymentService;
use App\Services\Payments\OrderReceiptService;
use App\Services\Payments\PaymentSplitter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

const CP_ORIGIN = '92000000-0000-0000-0000-000000000001';
const CP_LAST_MILE = '92000000-0000-0000-0000-000000000002';

beforeEach(function () {
    // Same shim as CheckoutMessagingTest: public.addresses is Supabase-managed.
    if (! Schema::hasTable('addresses')) {
        Schema::create('addresses', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('owner_kind');
            $table->string('profile_id')->nullable();
            $table->string('region_name')->nullable();
            $table->string('province_code')->nullable();
            $table->string('province_name')->nullable();
            $table->string('municipality_name')->nullable();
            $table->string('barangay')->nullable();
        });
    }
});

function couponRow(array $o = []): array
{
    return array_merge([
        'discount_type' => 'percentage', 'discount_value' => 10,
        'expires_at' => now()->addDays(30)->toIso8601String(),
    ], $o);
}

function makeCoupon(Product $product, array $o = []): ProductCoupon
{
    return ProductCoupon::create(array_merge([
        'product_id' => $product->id, 'seller_id' => $product->seller_id,
        'code' => strtoupper(Str::random(8)), 'discount_type' => 'percentage', 'discount_value' => 10,
        'expires_at' => now()->addDays(30), 'status' => 'active',
    ], $o));
}

function claimFor(Profile $buyer, ProductCoupon $coupon): BuyerCoupon
{
    return app(CouponService::class)->claim($buyer, $coupon->id);
}

function checkoutWith(Product $product, array $item = []): array
{
    return [
        'items' => [array_merge(['product_id' => $product->id, 'quantity' => 1], $item)],
        'delivery_address' => ['recipient_name' => 'Juan', 'address' => '1 Street'],
        'shipping_method' => 'standard',
        'payment_method' => 'cod',
    ];
}

/** Delivered, received order: one item, ₱60 shipping over a two-company chain. */
function couponedOrder(Profile $buyer, Profile $seller, float $price, float $discount): Order
{
    $orderId = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $orderId, 'order_number' => 'SN-'.Str::upper(Str::random(6)),
        'seller_id' => $seller->id, 'buyer_profile_id' => $buyer->id, 'recipient_name' => 'Buyer',
        'status' => 'Delivered', 'subtotal' => $price, 'shipping_fee' => 60, 'tax' => 0,
        'discount' => $discount, 'total' => $price + 60 - $discount,
        'placed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([CP_ORIGIN, CP_LAST_MILE] as $i => $company) {
        DB::table('parcel_assignments')->insert([
            'id' => (string) Str::uuid(), 'order_id' => $orderId, 'logistics_company_id' => $company,
            'status' => 'delivered', 'received_at' => now(),
            'created_at' => now()->addSeconds($i), 'updated_at' => now(),
        ]);
    }
    DB::table('order_items')->insert([
        'id' => (string) Str::uuid(), 'order_id' => $orderId, 'product_name' => 'Earbuds', 'quantity' => 1,
        'unit_price' => $price, 'subtotal' => $price, 'coupon_code' => 'SAVE10', 'coupon_discount' => $discount,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return Order::find($orderId);
}

function couponLedgerBalanced(): bool
{
    return (int) LedgerEntry::sum('debit_cents') === (int) LedgerEntry::sum('credit_cents');
}

it('1. seller creates three coupons on one product at once', function () {
    $seller = makeSeller();
    $product = makeProduct($seller, ['price' => 500]);
    actingAsSeller($seller);

    $this->postJson("/api/seller/products/{$product->id}/coupons", ['coupons' => [
        couponRow(['code' => 'save10']),
        couponRow(['discount_type' => 'fixed', 'discount_value' => 50, 'usage_limit' => 100]),
        couponRow(['discount_value' => 20, 'max_discount' => 30]),
    ]])->assertCreated()->assertJsonCount(3, 'data')->assertJsonPath('data.0.code', 'SAVE10');

    expect(ProductCoupon::where('product_id', $product->id)->count())->toBe(3);

    // Someone else's product is off limits.
    actingAsSeller(makeSeller());
    $this->postJson("/api/seller/products/{$product->id}/coupons", ['coupons' => [couponRow()]])->assertNotFound();
});

it('2. product page lists claimable coupons with the price after discount', function () {
    $product = makeProduct(makeSeller(), ['price' => 500]);
    makeCoupon($product);
    makeCoupon($product, ['expires_at' => now()->subDay()]);
    makeCoupon($product, ['usage_limit' => 5, 'used_count' => 5]);

    $this->getJson("/api/products/{$product->id}/coupons")
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.label', '10% OFF')
        ->assertJsonPath('data.0.discount', 50);
});

it('3 & 10. claiming adds to the wallet once only', function () {
    $buyer = makeBuyer();
    $coupon = makeCoupon(makeProduct(makeSeller(), ['price' => 500]));
    actingAsBuyer($buyer);

    $this->postJson("/api/buyer/coupons/{$coupon->id}/claim")->assertCreated()->assertJsonPath('data.status', 'available');
    $this->postJson("/api/buyer/coupons/{$coupon->id}/claim")->assertStatus(422)
        ->assertJsonPath('message', 'You already claimed this coupon.');
    $this->getJson('/api/buyer/coupons')->assertOk()->assertJsonCount(1, 'data');
});

it('4. applying a claimed coupon discounts one unit and marks it used', function () {
    $buyer = makeBuyer();
    $product = makeProduct(makeSeller(), ['price' => 500]);
    $coupon = makeCoupon($product, ['usage_limit' => 10]);
    $bc = claimFor($buyer, $coupon);
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', checkoutWith($product, ['quantity' => 2, 'coupon_id' => $bc->id]))->assertCreated();

    $order = Order::where('buyer_profile_id', $buyer->id)->first();
    expect((float) $order->subtotal)->toBe(1000.0)
        ->and((float) $order->discount)->toBe(50.0)           // one unit, not the line
        ->and((float) $order->total)->toBe(1010.0)
        ->and($bc->fresh()->status)->toBe('used')
        ->and($coupon->fresh()->used_count)->toBe(1)
        ->and(OrderItem::where('order_id', $order->id)->value('coupon_code'))->toBe($coupon->code);

    // Same coupon can't be used twice.
    $this->postJson('/api/buyer/checkout', checkoutWith($product, ['coupon_id' => $bc->id]))->assertStatus(422);
});

it('5. auto-apply picks the highest discount, ties go to the soonest expiry', function () {
    $buyer = makeBuyer();
    $product = makeProduct(makeSeller(), ['price' => 500]);
    claimFor($buyer, makeCoupon($product, ['discount_value' => 10]));                                   // 50
    $later = claimFor($buyer, makeCoupon($product, ['discount_type' => 'fixed', 'discount_value' => 80, 'expires_at' => now()->addDays(20)]));
    $sooner = claimFor($buyer, makeCoupon($product, ['discount_type' => 'fixed', 'discount_value' => 80, 'expires_at' => now()->addDays(5)]));
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/coupons/quote', ['lines' => [['key' => 'a', 'product_id' => $product->id]]])
        ->assertOk()
        ->assertJsonPath('data.a.best', $sooner->id)
        ->assertJsonPath('data.a.options.1.id', $later->id)
        ->assertJsonCount(3, 'data.a.options');
});

it('6 & 7. caps: percentage max cap and never more than the price', function () {
    $product = makeProduct(makeSeller());
    expect(makeCoupon($product, ['max_discount' => 30])->discountFor(500))->toBe(30.0)
        ->and(makeCoupon($product, ['discount_type' => 'fixed', 'discount_value' => 50])->discountFor(30))->toBe(30.0);
});

it('8. expired coupons cannot be claimed or used', function () {
    $buyer = makeBuyer();
    $product = makeProduct(makeSeller(), ['price' => 500]);
    actingAsBuyer($buyer);

    $expired = makeCoupon($product, ['expires_at' => now()->subMinute()]);
    $this->postJson("/api/buyer/coupons/{$expired->id}/claim")->assertStatus(422);

    $coupon = makeCoupon($product);
    $bc = claimFor($buyer, $coupon);
    $coupon->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->postJson('/api/buyer/checkout', checkoutWith($product, ['coupon_id' => $bc->id]))->assertStatus(422);
});

it('11. usage limit counts redemptions; once hit the coupon reads as expired', function () {
    $product = makeProduct(makeSeller(), ['price' => 500]);
    $coupon = makeCoupon($product, ['usage_limit' => 1]);
    $first = makeBuyer();
    $second = makeBuyer();
    claimFor($first, $coupon);
    $late = claimFor($second, $coupon); // more claims than the limit is fine

    actingAsBuyer($first);
    $this->postJson('/api/buyer/checkout', checkoutWith($product, ['coupon_id' => BuyerCoupon::where('buyer_profile_id', $first->id)->value('id')]))->assertCreated();

    actingAsBuyer($second);
    $this->postJson('/api/buyer/checkout', checkoutWith($product, ['coupon_id' => $late->id]))->assertStatus(422);
    $this->postJson("/api/buyer/coupons/{$coupon->id}/claim")->assertStatus(422);
    $this->getJson('/api/buyer/coupons')->assertJsonPath('data.0.status', 'expired');
});

it('shows the "X left" warning at ≤ 20% remaining only', function () {
    $product = makeProduct(makeSeller());
    expect(makeCoupon($product, ['usage_limit' => 100, 'used_count' => 85])->isRunningLow())->toBeTrue()
        ->and(makeCoupon($product, ['usage_limit' => 100, 'used_count' => 20])->isRunningLow())->toBeFalse()
        ->and(makeCoupon($product, ['usage_limit' => null, 'used_count' => 999])->isRunningLow())->toBeFalse();
});

it('12. checkout without the coupon charges full price and keeps it in the wallet', function () {
    $buyer = makeBuyer();
    $product = makeProduct(makeSeller(), ['price' => 500]);
    $bc = claimFor($buyer, makeCoupon($product));
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', checkoutWith($product))->assertCreated();

    expect((float) Order::where('buyer_profile_id', $buyer->id)->value('total'))->toBe(560.0)
        ->and($bc->fresh()->status)->toBe('available');
});

it('cancelling an order returns the coupon to the wallet', function () {
    $buyer = makeBuyer();
    $product = makeProduct(makeSeller(), ['price' => 500]);
    $coupon = makeCoupon($product, ['usage_limit' => 5]);
    $bc = claimFor($buyer, $coupon);
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', checkoutWith($product, ['coupon_id' => $bc->id]))->assertCreated();
    app(CouponService::class)->releaseForOrder(Order::where('buyer_profile_id', $buyer->id)->first());

    expect($bc->fresh()->status)->toBe('available')->and($coupon->fresh()->used_count)->toBe(0);
});

it('splits a couponed order exactly as the spec example (₱510 ledger)', function () {
    $shares = collect(PaymentSplitter::split(50000, 6000, 'seller', [CP_ORIGIN, CP_LAST_MILE], [], 5000))
        ->pluck('cents', 'account');

    expect($shares->all())->toBe([
        'seller' => 42500, 'origin_logistics' => 3420, 'last_mile_logistics' => 2280, 'platform' => 2800,
    ])->and($shares->sum())->toBe(51000);
});

it('releases escrow on a couponed order with the seller absorbing the discount', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    app(OrderReceiptService::class)->confirm(couponedOrder($buyer, $seller, 500, 50));

    expect(LedgerEntry::balance('buyer', $buyer->id))->toBe(-51000)
        ->and(LedgerEntry::balance('seller', $seller->id))->toBe(42500)
        ->and(LedgerEntry::balance('origin_logistics', CP_ORIGIN))->toBe(3420)
        ->and(LedgerEntry::balance('last_mile_logistics', CP_LAST_MILE))->toBe(2280)
        ->and(LedgerEntry::balance('platform'))->toBe(2800)
        ->and(couponLedgerBalanced())->toBeTrue();
});

it('9. full refund (refund-only) on a couponed item returns what the buyer paid', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $order = couponedOrder($buyer, $seller, 500, 50);
    app(OrderReceiptService::class)->confirm($order);

    app(MockPaymentService::class)->refundBuyer($order->id, 510, 'full');

    expect(LedgerEntry::balance('buyer', $buyer->id))->toBe(0)
        ->and(LedgerEntry::balance('seller', $seller->id))->toBe(0)
        ->and(LedgerEntry::balance('platform'))->toBe(0)
        ->and(couponLedgerBalanced())->toBeTrue();
});

it('9. return of a couponed item: buyer gets ₱450, seller keeps the coupon cost', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $order = couponedOrder($buyer, $seller, 500, 50);
    app(OrderReceiptService::class)->confirm($order);

    // Item back with no shipping refund / return shipping, to isolate the item math.
    app(MockPaymentService::class)->settleReturn($order->id, (string) Str::uuid(), 45000, 0, 0, [], 5000);

    expect(LedgerEntry::balance('buyer', $buyer->id))->toBe(-51000 + 45000)
        ->and(LedgerEntry::balance('seller', $seller->id))->toBe(0)        // 425 earned − 425 clawed
        ->and(LedgerEntry::balance('platform'))->toBe(2800 - 2500)          // keeps 5% of shipping only
        ->and(couponLedgerBalanced())->toBeTrue();
});

it('return requests on a couponed line refund the discounted unit first', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $order = couponedOrder($buyer, $seller, 500, 50);
    $order->forceFill(['received_at' => now()])->save();
    $item = OrderItem::where('order_id', $order->id)->first();
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/returns', [
        'order_item_id' => $item->id, 'request_type' => 'refund_only', 'reason' => 'damaged',
        'details' => 'Cracked on arrival, does not work.', 'quantity' => 1, 'evidence' => ['https://x.test/a.jpg'],
    ])->assertCreated();

    $request = OrderReturnRequest::where('order_item_id', $item->id)->first();
    expect((float) $request->estimated_amount)->toBe(450.0)->and((float) $request->coupon_discount)->toBe(50.0);
});

it('expiry worker is idempotent and expires wallet copies', function () {
    $buyer = makeBuyer();
    $coupon = makeCoupon(makeProduct(makeSeller()));
    $bc = claimFor($buyer, $coupon);
    $coupon->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->artisan('coupons:expire')->assertSuccessful();
    $this->artisan('coupons:expire')->assertSuccessful();

    expect($coupon->fresh()->status)->toBe('expired')->and($bc->fresh()->status)->toBe('expired')
        ->and(app(CouponService::class)->expireStale())->toBe(['coupons' => 0, 'wallet' => 0]);
});

it('deleting a coupon makes wallet copies unusable', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    $coupon = makeCoupon(makeProduct($seller));
    $bc = claimFor($buyer, $coupon);
    actingAsSeller($seller);

    $this->deleteJson("/api/seller/coupons/{$coupon->id}")->assertOk();

    expect($bc->fresh()->status)->toBe('expired');
});
