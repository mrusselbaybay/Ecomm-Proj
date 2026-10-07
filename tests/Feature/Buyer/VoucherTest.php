<?php

use App\Models\BuyerVoucher;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturnRequest;
use App\Models\Product;
use App\Models\Profile;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Payments\MockPaymentService;
use App\Services\Payments\OrderReceiptService;
use App\Services\Payments\PaymentSplitter;
use App\Services\Vouchers\VoucherService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

const VC_ORIGIN = '92000000-0000-0000-0000-000000000001';
const VC_LAST_MILE = '92000000-0000-0000-0000-000000000002';

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

/** Request body for POST /api/seller/vouchers. */
function voucherBody(array $o = []): array
{
    return array_merge([
        'type' => 'discount', 'scope' => 'shop', 'discount_type' => 'percentage', 'discount_value' => 10,
        'max_discount' => 100, 'min_spend' => 0, 'starts_at' => now()->toIso8601String(),
        'expires_at' => now()->addDays(30)->toIso8601String(), 'usage_limit' => 100, 'per_user_limit' => 1,
        'budget_cap' => 10000, 'stackable' => true,
    ], $o);
}

/** @param  list<Product>  $products  product scope when given */
function makeVoucher(Profile $seller, array $o = [], array $products = []): Voucher
{
    $v = Voucher::create(array_merge([
        'seller_id' => $seller->id, 'code' => strtoupper(Str::random(8)),
        'type' => 'discount', 'scope' => $products ? 'product' : 'shop',
        'discount_type' => 'percentage', 'discount_value' => 10, 'max_discount' => 1000, 'min_spend' => 0,
        'starts_at' => now()->subMinute(), 'expires_at' => now()->addDays(30),
        'usage_limit' => 100, 'per_user_limit' => 1, 'budget_cap' => 100000, 'stackable' => true,
    ], $o));

    foreach ($products as $p) {
        DB::table('voucher_products')->insert(['voucher_id' => $v->id, 'product_id' => $p->id]);
    }

    return $v;
}

function claimV(Profile $buyer, Voucher $v): BuyerVoucher
{
    return app(VoucherService::class)->claim($buyer, $v->id);
}

function checkoutBody(Product $product, array $item = [], array $vouchers = []): array
{
    return [
        'items' => [array_merge(['product_id' => $product->id, 'quantity' => 1], $item)],
        'delivery_address' => ['recipient_name' => 'Juan', 'address' => '1 Street'],
        'shipping_method' => 'standard',
        'payment_method' => 'cod',
        ...($vouchers ? ['vouchers' => [array_merge(['seller_id' => $product->seller_id], $vouchers)]] : []),
    ];
}

/** Delivered, received order: one item, ₱60 shipping over a two-company chain. */
function voucheredOrder(Profile $buyer, Profile $seller, float $price, float $discount, int $qty = 1): Order
{
    $orderId = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $orderId, 'order_number' => 'SN-'.Str::upper(Str::random(6)),
        'seller_id' => $seller->id, 'buyer_profile_id' => $buyer->id, 'recipient_name' => 'Buyer',
        'status' => 'Delivered', 'subtotal' => $price * $qty, 'shipping_fee' => 60, 'tax' => 0,
        'discount' => $discount, 'total' => $price * $qty + 60 - $discount,
        'placed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([VC_ORIGIN, VC_LAST_MILE] as $i => $company) {
        DB::table('parcel_assignments')->insert([
            'id' => (string) Str::uuid(), 'order_id' => $orderId, 'logistics_company_id' => $company,
            'status' => 'delivered', 'received_at' => now(),
            'created_at' => now()->addSeconds($i), 'updated_at' => now(),
        ]);
    }
    DB::table('order_items')->insert([
        'id' => (string) Str::uuid(), 'order_id' => $orderId, 'product_name' => 'Earbuds', 'quantity' => $qty,
        'unit_price' => $price, 'subtotal' => $price * $qty, 'voucher_code' => 'SAVE10', 'voucher_discount' => $discount,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return Order::find($orderId);
}

function voucherLedgerBalanced(): bool
{
    return (int) LedgerEntry::sum('debit_cents') === (int) LedgerEntry::sum('credit_cents');
}

// ─── Seller: creation ─────────────────────────────────────────────────

it('creates shop, product and shipping vouchers', function () {
    $seller = makeSeller();
    $a = makeProduct($seller);
    $b = makeProduct($seller);
    actingAsSeller($seller);

    $this->postJson('/api/seller/vouchers', voucherBody())->assertCreated()
        ->assertJsonPath('data.scope', 'shop')->assertJsonPath('data.status', 'active');
    $this->postJson('/api/seller/vouchers', voucherBody(['scope' => 'product', 'product_ids' => [$a->id, $b->id]]))
        ->assertCreated()->assertJsonPath('data.productsCount', 2);
    // Shipping is always free shipping; shop-wide or per product.
    $this->postJson('/api/seller/vouchers', voucherBody(['type' => 'shipping', 'discount_type' => null, 'discount_value' => null, 'max_discount' => null]))
        ->assertCreated()->assertJsonPath('data.scope', 'shop')->assertJsonPath('data.label', 'Free Shipping');
    $this->postJson('/api/seller/vouchers', voucherBody(['type' => 'shipping', 'scope' => 'product', 'product_ids' => [$a->id], 'discount_type' => null, 'discount_value' => null, 'max_discount' => null]))
        ->assertCreated()->assertJsonPath('data.scope', 'product');
});

it('validates required rules conditionally', function () {
    $seller = makeSeller();
    actingAsSeller($seller);

    $this->postJson('/api/seller/vouchers', voucherBody(['max_discount' => null]))->assertJsonValidationErrors('max_discount');
    $this->postJson('/api/seller/vouchers', voucherBody(['min_spend' => null]))->assertJsonValidationErrors('min_spend');
    $this->postJson('/api/seller/vouchers', voucherBody(['discount_type' => 'fixed', 'discount_value' => 50, 'max_discount' => null]))->assertCreated();
    $this->postJson('/api/seller/vouchers', voucherBody(['per_user_limit' => 5, 'usage_limit' => 2]))->assertJsonValidationErrors('per_user_limit');
    $this->postJson('/api/seller/vouchers', voucherBody(['scope' => 'product', 'product_ids' => []]))->assertJsonValidationErrors('product_ids');

    // Someone else's product is off limits; more than 500 products is rejected.
    $foreign = makeProduct(makeSeller());
    $this->postJson('/api/seller/vouchers', voucherBody(['scope' => 'product', 'product_ids' => [$foreign->id]]))->assertJsonValidationErrors('product_ids');
    $ids = array_map(fn () => (string) Str::uuid(), range(1, 501));
    $this->postJson('/api/seller/vouchers', voucherBody(['scope' => 'product', 'product_ids' => $ids]))->assertJsonValidationErrors('product_ids');
});

it('blocks overlapping product vouchers unless confirmed', function () {
    $seller = makeSeller();
    $product = makeProduct($seller, ['name' => 'Earbuds']);
    makeVoucher($seller, [], [$product]);
    actingAsSeller($seller);

    $body = voucherBody(['scope' => 'product', 'product_ids' => [$product->id]]);
    $this->postJson('/api/seller/vouchers', $body)->assertStatus(409)->assertJsonPath('conflicts.0.products.0', 'Earbuds');
    $this->postJson('/api/seller/vouchers', [...$body, 'confirm_overlap' => true])->assertCreated();

    // Non-overlapping dates go straight through.
    $this->postJson('/api/seller/vouchers', voucherBody([
        'scope' => 'product', 'product_ids' => [$product->id],
        'starts_at' => now()->addDays(40)->toIso8601String(), 'expires_at' => now()->addDays(50)->toIso8601String(),
    ]))->assertCreated();
});

// ─── Seller: lifecycle ────────────────────────────────────────────────

it('derives status from facts', function () {
    $seller = makeSeller();

    expect(makeVoucher($seller)->status())->toBe('active')
        ->and(makeVoucher($seller, ['starts_at' => now()->addDay()])->status())->toBe('scheduled')
        ->and(makeVoucher($seller, ['deactivated_at' => now()])->status())->toBe('deactivated')
        ->and(makeVoucher($seller, ['usage_limit' => 2, 'used_count' => 2])->status())->toBe('fully_redeemed')
        ->and(makeVoucher($seller, ['budget_cap' => 50, 'budget_used' => 50])->status())->toBe('expired')
        ->and(makeVoucher($seller, ['expires_at' => now()->subMinute(), 'deactivated_at' => now()])->status())->toBe('expired');
});

it('deactivates, reactivates and adds stock', function () {
    $seller = makeSeller();
    $voucher = makeVoucher($seller, ['usage_limit' => 1, 'used_count' => 1]);
    actingAsSeller($seller);

    $this->postJson("/api/seller/vouchers/{$voucher->id}/stock", ['usage_limit' => 1])->assertJsonValidationErrors('usage_limit');
    $this->postJson("/api/seller/vouchers/{$voucher->id}/stock", ['usage_limit' => 5])->assertOk()->assertJsonPath('data.status', 'active');

    $this->postJson("/api/seller/vouchers/{$voucher->id}/deactivate")->assertOk()->assertJsonPath('data.status', 'deactivated');
    $this->postJson("/api/seller/vouchers/{$voucher->id}/stock", ['usage_limit' => 9])->assertStatus(422);
    $this->postJson("/api/seller/vouchers/{$voucher->id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');

    // Budget exhausted → expired; can't be reactivated.
    $spent = makeVoucher($seller, ['budget_cap' => 10, 'budget_used' => 10, 'deactivated_at' => now()]);
    $this->postJson("/api/seller/vouchers/{$spent->id}/reactivate")->assertStatus(422);
});

it('deletes only never-redeemed vouchers', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    $fresh = makeVoucher($seller);
    claimV($buyer, $fresh);
    $used = makeVoucher($seller);
    VoucherRedemption::create(['voucher_id' => $used->id, 'buyer_profile_id' => $buyer->id, 'order_id' => (string) Str::uuid(), 'amount' => 5, 'released_at' => now()]);
    actingAsSeller($seller);

    $this->deleteJson("/api/seller/vouchers/{$fresh->id}")->assertOk();
    expect(Voucher::find($fresh->id))->toBeNull()->and(BuyerVoucher::count())->toBe(0);

    $this->deleteJson("/api/seller/vouchers/{$used->id}")->assertStatus(422);
    $this->getJson('/api/seller/vouchers')->assertOk()->assertJsonPath('data.items.0.canDelete', false);
});

it('flags unavailable products on product vouchers', function () {
    $seller = makeSeller();
    $voucher = makeVoucher($seller, [], [makeProduct($seller), makeProduct($seller, ['stock' => 0])]);
    actingAsSeller($seller);

    $this->getJson('/api/seller/vouchers')->assertOk()
        ->assertJsonPath('data.items.0.id', $voucher->id)
        ->assertJsonPath('data.items.0.productsCount', 2)
        ->assertJsonPath('data.items.0.unavailableCount', 1);
});

it('paginates 5 per tab; deactivated and expired sit in Inactive', function () {
    $seller = makeSeller();
    foreach (range(1, 6) as $i) {
        makeVoucher($seller);
    }
    makeVoucher($seller, ['usage_limit' => 1, 'used_count' => 1]);      // fully redeemed → active tab
    $off = makeVoucher($seller, ['deactivated_at' => now()]);
    makeVoucher($seller, ['expires_at' => now()->subDay()]);
    makeVoucher($seller, ['budget_cap' => 5, 'budget_used' => 5]);
    actingAsSeller($seller);

    $this->getJson('/api/seller/vouchers')->assertOk()
        ->assertJsonCount(5, 'data.items')
        ->assertJsonPath('data.meta.lastPage', 2)
        ->assertJsonPath('data.counts', ['active' => 7, 'inactive' => 3]);
    $this->getJson('/api/seller/vouchers?tab=active&page=2')->assertJsonCount(2, 'data.items');
    $this->getJson('/api/seller/vouchers?tab=inactive&status=deactivated')
        ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $off->id);
    $this->getJson('/api/seller/vouchers?tab=active&status=fully_redeemed')->assertJsonCount(1, 'data.items');
});

it('product-scoped free shipping applies only when the cart has a covered product', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $covered = makeProduct($seller, ['price' => 500]);
    $other = makeProduct($seller, ['price' => 500]);
    $voucher = makeVoucher($seller, ['type' => 'shipping', 'discount_value' => 100, 'max_discount' => null], [$covered]);
    claimV($buyer, $voucher);
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', checkoutBody($other, [], ['shipping_voucher_id' => $voucher->id]))->assertStatus(422);
    $this->postJson('/api/buyer/checkout', checkoutBody($covered, [], ['shipping_voucher_id' => $voucher->id]))->assertCreated();
    expect((float) Order::where('buyer_profile_id', $buyer->id)->value('shipping_discount'))->toBe(60.0);
});

// ─── Buyer: discovery & claim ─────────────────────────────────────────

it('product page lists product vouchers first, then shop and shipping', function () {
    $seller = makeSeller();
    $product = makeProduct($seller, ['price' => 500]);
    $shipping = makeVoucher($seller, ['type' => 'shipping', 'discount_type' => 'fixed', 'discount_value' => 30]);
    $shop = makeVoucher($seller);
    $own = makeVoucher($seller, [], [$product]);
    makeVoucher($seller, [], [makeProduct($seller)]);           // other product
    makeVoucher($seller, ['deactivated_at' => now()]);           // hidden
    makeVoucher($seller, ['usage_limit' => 1, 'used_count' => 1]); // hidden

    $this->getJson("/api/products/{$product->id}/vouchers")->assertOk()->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $own->id)->assertJsonPath('data.0.discount', 50)
        ->assertJsonPath('data.1.id', $shop->id)
        ->assertJsonPath('data.2.id', $shipping->id);

    // Out of stock → nothing to show.
    $product->forceFill(['stock' => 0])->save();
    $this->getJson("/api/products/{$product->id}/coupons")->assertOk()->assertJsonCount(0, 'data');
});

it('claims once only', function () {
    $buyer = makeBuyer();
    $voucher = makeVoucher(makeSeller());
    actingAsBuyer($buyer);

    $this->postJson("/api/buyer/vouchers/{$voucher->id}/claim")->assertCreated()->assertJsonPath('data.status', 'available');
    $this->postJson("/api/buyer/vouchers/{$voucher->id}/claim")->assertStatus(422)->assertJsonPath('message', 'You already claimed this voucher.');
    $this->getJson('/api/buyer/vouchers')->assertOk()->assertJsonCount(1, 'data');

    $expired = makeVoucher(makeSeller(), ['expires_at' => now()->subMinute()]);
    $this->postJson("/api/buyer/coupons/{$expired->id}/claim")->assertStatus(422);
});

// ─── Checkout ─────────────────────────────────────────────────────────

it('applies a product voucher to its lines and records the redemption', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $product = makeProduct($seller, ['price' => 500]);
    $voucher = makeVoucher($seller, ['budget_cap' => 1000], [$product]);
    claimV($buyer, $voucher);
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', checkoutBody($product, ['quantity' => 2], ['discount_voucher_id' => $voucher->id]))->assertCreated();

    $order = Order::where('buyer_profile_id', $buyer->id)->first();
    $voucher->refresh();
    expect((float) $order->discount)->toBe(100.0)  // 10% of the ₱1000 line
        ->and((float) $order->total)->toBe(960.0)
        ->and($voucher->used_count)->toBe(1)
        ->and((float) $voucher->budget_used)->toBe(100.0)
        ->and(OrderItem::where('order_id', $order->id)->value('voucher_code'))->toBe($voucher->code);

    // Per-user limit 1: can't use it again.
    $this->postJson('/api/buyer/checkout', checkoutBody($product, [], ['discount_voucher_id' => $voucher->id]))->assertStatus(422);
    $this->getJson('/api/buyer/vouchers')->assertJsonPath('data.0.status', 'used');
});

it('enforces min spend and caps the discount at the remaining budget', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $product = makeProduct($seller, ['price' => 100]);
    $minSpend = makeVoucher($seller, ['min_spend' => 300]);
    $budget = makeVoucher($seller, ['discount_type' => 'fixed', 'discount_value' => 80, 'max_discount' => null, 'budget_cap' => 50]);
    claimV($buyer, $minSpend);
    claimV($buyer, $budget);
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', checkoutBody($product, [], ['discount_voucher_id' => $minSpend->id]))
        ->assertStatus(422);

    $this->postJson('/api/buyer/checkout', checkoutBody($product, [], ['discount_voucher_id' => $budget->id]))->assertCreated();
    expect((float) Order::where('buyer_profile_id', $buyer->id)->value('discount'))->toBe(50.0)
        ->and($budget->fresh()->status())->toBe('expired');
});

it('stacks one discount and one shipping voucher, never a non-stackable one', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $product = makeProduct($seller, ['price' => 500]);
    $discount = makeVoucher($seller);
    $shipping = makeVoucher($seller, ['type' => 'shipping', 'discount_type' => 'percentage', 'discount_value' => 100, 'max_discount' => 60]);
    $solo = makeVoucher($seller, ['type' => 'shipping', 'discount_type' => 'fixed', 'discount_value' => 20, 'max_discount' => null, 'stackable' => false]);
    foreach ([$discount, $shipping, $solo] as $v) {
        claimV($buyer, $v);
    }
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', checkoutBody($product, [], ['discount_voucher_id' => $discount->id, 'shipping_voucher_id' => $solo->id]))
        ->assertStatus(422);

    $this->postJson('/api/buyer/checkout', checkoutBody($product, [], ['discount_voucher_id' => $discount->id, 'shipping_voucher_id' => $shipping->id]))
        ->assertCreated();

    $order = Order::where('buyer_profile_id', $buyer->id)->first();
    expect((float) $order->discount)->toBe(110.0)
        ->and((float) $order->shipping_discount)->toBe(60.0)
        ->and((float) $order->total)->toBe(450.0)
        ->and(VoucherRedemption::where('order_id', $order->id)->count())->toBe(2);
});

it('quote picks the best combination for the buyer', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $product = makeProduct($seller, ['price' => 500]);
    $shop = makeVoucher($seller);                                                         // ₱50
    $prod = makeVoucher($seller, ['discount_type' => 'fixed', 'discount_value' => 70, 'max_discount' => null], [$product]); // ₱70
    $ship = makeVoucher($seller, ['type' => 'shipping', 'discount_type' => 'fixed', 'discount_value' => 40, 'max_discount' => null]);
    $exclusive = makeVoucher($seller, ['discount_type' => 'fixed', 'discount_value' => 100, 'max_discount' => null, 'stackable' => false]);
    $far = makeVoucher($seller, ['min_spend' => 9999]);
    foreach ([$shop, $prod, $ship, $exclusive, $far] as $v) {
        claimV($buyer, $v);
    }
    actingAsBuyer($buyer);

    // 70 + 40 = 110 beats the exclusive 100.
    $this->postJson('/api/buyer/vouchers/quote', ['lines' => [['key' => 'a', 'product_id' => $product->id, 'quantity' => 1]], 'shipping_method' => 'standard'])
        ->assertOk()
        ->assertJsonPath("data.sellers.{$seller->id}.best.discountId", $prod->id)
        ->assertJsonPath("data.sellers.{$seller->id}.best.shippingId", $ship->id)
        ->assertJsonPath("data.sellers.{$seller->id}.best.savings", 110)
        ->assertJsonCount(4, "data.sellers.{$seller->id}.discount")
        ->assertJsonPath("data.sellers.{$seller->id}.discount.3.applicable", false);
});

it('cancelling an order gives back usage, budget and the per-user count', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $product = makeProduct($seller, ['price' => 500]);
    $voucher = makeVoucher($seller);
    claimV($buyer, $voucher);
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', checkoutBody($product, [], ['discount_voucher_id' => $voucher->id]))->assertCreated();
    app(VoucherService::class)->releaseForOrder(Order::where('buyer_profile_id', $buyer->id)->first());

    $voucher->refresh();
    expect($voucher->used_count)->toBe(0)->and((float) $voucher->budget_used)->toBe(0.0);
    $this->getJson('/api/buyer/vouchers')->assertJsonPath('data.0.status', 'available');
});

it('usage limit is enforced across buyers', function () {
    $seller = makeSeller();
    $product = makeProduct($seller, ['price' => 500]);
    $voucher = makeVoucher($seller, ['usage_limit' => 1]);
    $first = makeBuyer();
    $second = makeBuyer();
    claimV($first, $voucher);
    claimV($second, $voucher);

    actingAsBuyer($first);
    $this->postJson('/api/buyer/checkout', checkoutBody($product, [], ['discount_voucher_id' => $voucher->id]))->assertCreated();

    actingAsBuyer($second);
    $this->postJson('/api/buyer/checkout', checkoutBody($product, [], ['discount_voucher_id' => $voucher->id]))->assertStatus(422);
    $this->getJson('/api/buyer/vouchers')->assertJsonPath('data.0.status', 'expired');
});

it('legacy coupon_id checkout and quote still work (mobile)', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $product = makeProduct($seller, ['price' => 500]);
    $voucher = makeVoucher($seller, [], [$product]);
    $bc = claimV($buyer, $voucher);
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/coupons/quote', ['lines' => [['key' => 'a', 'product_id' => $product->id]]])
        ->assertOk()->assertJsonPath('data.a.best', $bc->id)->assertJsonPath('data.a.options.0.discount', 50);

    $this->postJson('/api/buyer/checkout', checkoutBody($product, ['coupon_id' => $bc->id]))->assertCreated();
    expect((float) Order::where('buyer_profile_id', $buyer->id)->value('discount'))->toBe(50.0);
});

// ─── Payments & returns ───────────────────────────────────────────────

it('splits a vouchered order exactly as the spec example (₱510 ledger)', function () {
    $shares = collect(PaymentSplitter::split(50000, 6000, 'seller', [VC_ORIGIN, VC_LAST_MILE], [], 5000))
        ->pluck('cents', 'account');

    expect($shares->all())->toBe([
        'seller' => 42500, 'origin_logistics' => 3420, 'last_mile_logistics' => 2280, 'platform' => 2800,
    ])->and($shares->sum())->toBe(51000);
});

it('releases escrow with the seller absorbing the voucher', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    app(OrderReceiptService::class)->confirm(voucheredOrder($buyer, $seller, 500, 50));

    expect(LedgerEntry::balance('buyer', $buyer->id))->toBe(-51000)
        ->and(LedgerEntry::balance('seller', $seller->id))->toBe(42500)
        ->and(LedgerEntry::balance('platform'))->toBe(2800)
        ->and(voucherLedgerBalanced())->toBeTrue();
});

it('return of a vouchered item: buyer gets what they paid, seller keeps the voucher cost', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $order = voucheredOrder($buyer, $seller, 500, 50);
    app(OrderReceiptService::class)->confirm($order);

    app(MockPaymentService::class)->settleReturn($order->id, (string) Str::uuid(), 45000, 0, 0, [], 5000);

    expect(LedgerEntry::balance('buyer', $buyer->id))->toBe(-51000 + 45000)
        ->and(LedgerEntry::balance('seller', $seller->id))->toBe(0)
        ->and(voucherLedgerBalanced())->toBeTrue();
});

it('return requests spread the line voucher over its units', function () {
    $buyer = makeBuyer();
    $order = voucheredOrder($buyer, makeSeller(), 500, 100, 3);
    $order->forceFill(['received_at' => now()])->save();
    $item = OrderItem::where('order_id', $order->id)->first();
    actingAsBuyer($buyer);

    $request = fn (int $qty) => $this->postJson('/api/buyer/returns', [
        'order_item_id' => $item->id, 'request_type' => 'refund_only', 'reason' => 'damaged',
        'details' => 'Cracked on arrival, does not work.', 'quantity' => $qty, 'evidence' => ['https://x.test/a.jpg'],
    ])->assertCreated();

    $request(1);
    $first = OrderReturnRequest::where('order_item_id', $item->id)->first();
    expect((float) $first->voucher_discount)->toBe(33.33)->and((float) $first->estimated_amount)->toBe(466.67);

    $first->forceFill(['status' => 'completed'])->save();
    $request(2);
    $last = OrderReturnRequest::where('order_item_id', $item->id)->where('id', '!=', $first->id)->first();
    expect((float) $last->voucher_discount)->toBe(66.67);
});
