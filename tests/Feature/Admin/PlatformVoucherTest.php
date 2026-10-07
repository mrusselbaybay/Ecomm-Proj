<?php

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Profile;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Payments\MockPaymentService;
use App\Services\Payments\OrderReceiptService;
use App\Services\Vouchers\PlatformVoucherService;
use App\Services\Vouchers\VoucherService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });
    }
});

function platformBody(array $o = []): array
{
    return array_merge([
        'type' => 'discount', 'scope' => 'platform', 'eligibility' => 'all', 'distribution' => 'auto_apply',
        'discount_type' => 'percentage', 'discount_value' => 10, 'max_discount' => 500, 'min_spend' => 0,
        'starts_at' => now()->toIso8601String(), 'expires_at' => now()->addDays(30)->toIso8601String(),
        'usage_limit' => 100, 'per_user_limit' => 1, 'budget_cap' => 50000, 'stackable' => true,
    ], $o);
}

function makePlatformVoucher(array $o = [], array $categories = []): Voucher
{
    $v = Voucher::create(array_merge([
        'source' => 'platform', 'funding_source' => 'platform', 'seller_id' => null,
        'code' => 'BTW'.strtoupper(Str::random(7)), 'type' => 'discount', 'scope' => $categories ? 'category' : 'platform',
        'discount_type' => 'percentage', 'discount_value' => 10, 'max_discount' => 1000, 'min_spend' => 0,
        'starts_at' => now()->subMinute(), 'expires_at' => now()->addDays(30),
        'usage_limit' => 100, 'per_user_limit' => 1, 'budget_cap' => 100000, 'stackable' => true,
        'eligibility' => 'all', 'distribution' => 'auto_apply',
    ], $o));

    foreach ($categories as $c) {
        DB::table('voucher_categories')->insert(['voucher_id' => $v->id, 'category' => $c]);
    }

    return $v;
}

function sellerVoucherFor(Profile $seller, array $o = []): Voucher
{
    return Voucher::create(array_merge([
        'seller_id' => $seller->id, 'code' => strtoupper(Str::random(8)), 'type' => 'discount', 'scope' => 'shop',
        'discount_type' => 'fixed', 'discount_value' => 100, 'min_spend' => 0,
        'starts_at' => now()->subMinute(), 'expires_at' => now()->addDays(30),
        'usage_limit' => 100, 'per_user_limit' => 1, 'budget_cap' => 100000, 'stackable' => true,
    ], $o));
}

/** Two seller orders: ₱500 (Electronics) + ₱300 (Pet Supplies). */
function twoSellerCart(): array
{
    $a = makeSeller();
    $b = makeSeller();

    return [
        makeProduct($a, ['price' => 500, 'category' => 'Electronics and Gadgets']),
        makeProduct($b, ['price' => 300, 'category' => 'Pet Supplies']),
    ];
}

function cartBody(array $products, array $extra = []): array
{
    return array_merge([
        'items' => array_map(fn (Product $p) => ['product_id' => $p->id, 'quantity' => 1], $products),
        'delivery_address' => ['recipient_name' => 'Juan', 'address' => '1 Street'],
        'shipping_method' => 'standard',
        'payment_method' => 'cod',
    ], $extra);
}

function platformLedgerBalanced(): bool
{
    return (int) LedgerEntry::sum('debit_cents') === (int) LedgerEntry::sum('credit_cents');
}

// ─── Admin ────────────────────────────────────────────────────────────

it('lets only admins manage platform vouchers', function () {
    actingAsProfile(makeSeller());
    $this->getJson('/api/admin/vouchers')->assertForbidden();

    actingAsProfile(makeAdmin());
    $this->getJson('/api/admin/vouchers')->assertOk()->assertJsonPath('data.counts', ['active' => 0, 'inactive' => 0]);
});

it('creates platform-wide, category and free-shipping vouchers with audit and funding', function () {
    $admin = makeAdmin();
    actingAsProfile($admin);

    $this->postJson('/api/admin/vouchers', platformBody())->assertCreated()
        ->assertJsonPath('data.scope', 'platform')
        ->assertJsonPath('data.fundingSource', 'platform')
        ->assertJsonPath('data.createdBy', $admin->full_name);

    $this->postJson('/api/admin/vouchers', platformBody(['scope' => 'category', 'categories' => ['Pet Supplies']]))
        ->assertCreated()->assertJsonPath('data.categories', ['Pet Supplies']);

    $this->postJson('/api/admin/vouchers', platformBody(['type' => 'shipping', 'discount_type' => null, 'discount_value' => null, 'max_discount' => null]))
        ->assertCreated()->assertJsonPath('data.label', 'Free Shipping');

    $this->postJson('/api/admin/vouchers', platformBody(['scope' => 'category', 'categories' => ['Not A Category']]))->assertJsonValidationErrors('categories');
    $this->postJson('/api/admin/vouchers', platformBody(['eligibility' => 'region']))->assertJsonValidationErrors('regions');
    $this->postJson('/api/admin/vouchers', platformBody(['max_discount' => null]))->assertJsonValidationErrors('max_discount');

    expect(Voucher::where('source', 'platform')->whereNull('seller_id')->count())->toBe(3);
});

it('blocks overlapping category vouchers unless confirmed', function () {
    makePlatformVoucher([], ['Pet Supplies']);
    actingAsProfile(makeAdmin());

    $body = platformBody(['scope' => 'category', 'categories' => ['Pet Supplies', 'Kids and Baby']]);
    $this->postJson('/api/admin/vouchers', $body)->assertStatus(409)->assertJsonPath('conflicts.0.categories', ['Pet Supplies']);
    $this->postJson('/api/admin/vouchers', [...$body, 'confirm_overlap' => true])->assertCreated();
});

it('tabs: unavailable stays active, deactivated moves to inactive', function () {
    makeProduct(makeSeller(), ['category' => 'Pet Supplies', 'stock' => 0]);
    $empty = makePlatformVoucher([], ['Pet Supplies']);
    makePlatformVoucher();
    $off = makePlatformVoucher(['deactivated_at' => now()]);
    actingAsProfile(makeAdmin());

    expect($empty->status())->toBe('unavailable');
    $this->getJson('/api/admin/vouchers')->assertJsonPath('data.counts', ['active' => 2, 'inactive' => 1]);
    $this->getJson('/api/admin/vouchers?status=unavailable')
        ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.status', 'unavailable');
    $this->getJson('/api/admin/vouchers?tab=inactive')->assertJsonPath('data.items.0.id', $off->id);

    $this->postJson("/api/admin/vouchers/{$empty->id}/deactivate")->assertOk()->assertJsonPath('data.status', 'deactivated');
    expect($empty->fresh()->deactivated_by)->not->toBeNull();
});

// ─── Eligibility ──────────────────────────────────────────────────────

it('applies eligibility: new, lapsed and region', function () {
    $service = app(PlatformVoucherService::class);
    $buyer = makeBuyer();
    $new = makePlatformVoucher(['eligibility' => 'new']);
    $lapsed = makePlatformVoucher(['eligibility' => 'lapsed', 'eligibility_meta' => ['lapsed_days' => 60]]);
    $region = makePlatformVoucher(['eligibility' => 'region', 'eligibility_meta' => ['regions' => ['NCR', 'National Capital Region']]]);

    expect($service->isEligible($buyer, $new, null))->toBeTrue()
        ->and($service->isEligible($buyer, $lapsed, null))->toBeFalse()
        ->and($service->isEligible($buyer, $region, 'national capital region'))->toBeTrue()
        ->and($service->isEligible($buyer, $region, 'Region VII'))->toBeFalse();

    [$order] = makeOrder($buyer, makeSeller());
    $order->forceFill(['placed_at' => now()->subDays(90)])->save();
    expect($service->isEligible($buyer, $new, null))->toBeFalse()
        ->and($service->isEligible($buyer, $lapsed, null))->toBeTrue();
});

// ─── Checkout ─────────────────────────────────────────────────────────

it('splits a platform discount across seller orders on top of seller vouchers', function () {
    [$p1, $p2] = twoSellerCart();
    $buyer = makeBuyer();
    $shop = sellerVoucherFor(Profile::find($p1->seller_id));                       // ₱100 off order 1
    app(VoucherService::class)->claim($buyer, $shop->id);
    $platform = makePlatformVoucher(['discount_value' => 10]);                      // 10% of (400 + 300)
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', cartBody([$p1, $p2], [
        'vouchers' => [['seller_id' => $p1->seller_id, 'discount_voucher_id' => $shop->id]],
        'platform_vouchers' => ['discount_voucher_id' => $platform->id],
    ]))->assertCreated();

    $o1 = Order::where('seller_id', $p1->seller_id)->first();
    $o2 = Order::where('seller_id', $p2->seller_id)->first();
    $platform->refresh();

    expect((float) $o1->discount)->toBe(100.0)
        ->and((float) $o1->platform_discount)->toBe(40.0)     // 70 × 400/700
        ->and((float) $o2->platform_discount)->toBe(30.0)
        ->and((float) $o1->total)->toBe(420.0)               // 500 + 60 − 100 − 40
        ->and($platform->used_count)->toBe(1)                 // one use for the whole checkout
        ->and((float) $platform->budget_used)->toBe(70.0)
        ->and(VoucherRedemption::where('voucher_id', $platform->id)->count())->toBe(2);

    // Per-user limit 1 counts the checkout, not the orders.
    $this->postJson('/api/buyer/checkout', cartBody([$p2], ['platform_vouchers' => ['discount_voucher_id' => $platform->id]]))->assertStatus(422);
});

it('free shipping covers orders without a seller shipping voucher; category scope respected', function () {
    [$p1, $p2] = twoSellerCart();
    $buyer = makeBuyer();
    $ship = makePlatformVoucher(['type' => 'shipping', 'discount_value' => 100, 'max_discount' => null], ['Pet Supplies']);
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', cartBody([$p1, $p2], ['platform_vouchers' => ['shipping_voucher_id' => $ship->id]]))->assertCreated();

    expect((float) Order::where('seller_id', $p1->seller_id)->value('platform_discount'))->toBe(0.0)
        ->and((float) Order::where('seller_id', $p2->seller_id)->value('shipping_discount'))->toBe(60.0)
        ->and((float) Order::where('seller_id', $p2->seller_id)->value('total'))->toBe(300.0);
});

it('rejects a non-stackable platform voucher next to seller vouchers, and claim-only vouchers until claimed', function () {
    [$p1] = twoSellerCart();
    $buyer = makeBuyer();
    $shop = sellerVoucherFor(Profile::find($p1->seller_id));
    app(VoucherService::class)->claim($buyer, $shop->id);
    $solo = makePlatformVoucher(['stackable' => false]);
    $claimOnly = makePlatformVoucher(['distribution' => 'claim']);
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', cartBody([$p1], [
        'vouchers' => [['seller_id' => $p1->seller_id, 'discount_voucher_id' => $shop->id]],
        'platform_vouchers' => ['discount_voucher_id' => $solo->id],
    ]))->assertStatus(422);

    $this->postJson('/api/buyer/checkout', cartBody([$p1], ['platform_vouchers' => ['discount_voucher_id' => $claimOnly->id]]))->assertStatus(422);
    app(VoucherService::class)->claim($buyer, $claimOnly->id);
    $this->postJson('/api/buyer/checkout', cartBody([$p1], ['platform_vouchers' => ['discount_voucher_id' => $claimOnly->id]]))->assertCreated();
});

it('quote returns platform options and the best pick on top of seller picks', function () {
    [$p1, $p2] = twoSellerCart();
    $buyer = makeBuyer();
    $platform = makePlatformVoucher(['discount_type' => 'fixed', 'discount_value' => 80, 'max_discount' => null]);
    makePlatformVoucher(['min_spend' => 5000]);
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/vouchers/quote', ['lines' => [
        ['key' => 'a', 'product_id' => $p1->id, 'quantity' => 1],
        ['key' => 'b', 'product_id' => $p2->id, 'quantity' => 1],
    ]])->assertOk()
        ->assertJsonPath('data.platform.best.discountId', $platform->id)
        ->assertJsonPath('data.platform.best.savings', 80)
        ->assertJsonCount(2, 'data.platform.discount')
        ->assertJsonPath('data.platform.discount.1.applicable', false);
});

it('cancelling one order returns its budget share; the use returns once all are cancelled', function () {
    [$p1, $p2] = twoSellerCart();
    $buyer = makeBuyer();
    $platform = makePlatformVoucher(['discount_value' => 10]);
    actingAsBuyer($buyer);
    $this->postJson('/api/buyer/checkout', cartBody([$p1, $p2], ['platform_vouchers' => ['discount_voucher_id' => $platform->id]]))->assertCreated();

    $service = app(VoucherService::class);
    $service->releaseForOrder(Order::where('seller_id', $p1->seller_id)->first());
    $platform->refresh();
    expect($platform->used_count)->toBe(1)->and((float) $platform->budget_used)->toBe(30.0);

    $service->releaseForOrder(Order::where('seller_id', $p2->seller_id)->first());
    $platform->refresh();
    expect($platform->used_count)->toBe(0)->and((float) $platform->budget_used)->toBe(0.0);
});

// ─── Ledger ───────────────────────────────────────────────────────────

it('platform-funded discount leaves the seller payout whole and books a platform subsidy', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    [$order] = makeOrder($buyer, $seller);
    $order->forceFill([
        'status' => 'Delivered', 'subtotal' => 500, 'shipping_fee' => 0, 'discount' => 0,
        'platform_discount' => 50, 'total' => 450,
    ])->save();

    app(OrderReceiptService::class)->confirm($order);

    expect(LedgerEntry::balance('buyer', $buyer->id))->toBe(-45000)
        ->and(LedgerEntry::balance('seller', $seller->id))->toBe(47500)   // 95% of ₱500, as with no voucher
        ->and(LedgerEntry::balance('platform'))->toBe(2500)
        ->and(LedgerEntry::balance(MockPaymentService::ACCOUNT_SUBSIDY))->toBe(-5000)
        ->and(platformLedgerBalanced())->toBeTrue();

    // Full refund: parties return what they got, the subsidy goes back to the platform.
    app(MockPaymentService::class)->refundBuyer($order->id, 450, 'full');
    expect(LedgerEntry::balance('buyer', $buyer->id))->toBe(0)
        ->and(LedgerEntry::balance('seller', $seller->id))->toBe(0)
        ->and(LedgerEntry::balance(MockPaymentService::ACCOUNT_SUBSIDY))->toBe(0)
        ->and(platformLedgerBalanced())->toBeTrue();
});

// ─── Reports ──────────────────────────────────────────────────────────

it('shows platform voucher cost to the platform admin and keeps seller revenue whole', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    [$order] = makeOrder($buyer, $seller);
    $order->forceFill([
        'status' => 'Delivered', 'subtotal' => 500, 'shipping_fee' => 0, 'discount' => 0,
        'platform_discount' => 50, 'total' => 450,
    ])->save();
    app(OrderReceiptService::class)->confirm($order);

    $voucher = makePlatformVoucher();
    VoucherRedemption::create(['voucher_id' => $voucher->id, 'buyer_profile_id' => $buyer->id, 'order_id' => $order->id, 'amount' => 50]);

    actingAsProfile(makeAdmin());
    $this->getJson('/api/admin/commissions/cash-flow')->assertOk()
        ->assertJsonPath('voucher_subsidy.spent', '50.00')
        ->assertJsonPath('net', '25.00')                 // 5% of ₱500
        ->assertJsonPath('net_after_vouchers', '-25.00');
    $this->getJson('/api/admin/commissions')->assertOk()
        ->assertJsonPath('summary.platform_vouchers', 50)
        ->assertJsonPath('orders.data.0.platform_discount', 50);
    $this->getJson('/api/admin/vouchers')->assertJsonPath('data.spend.allTime', 50)->assertJsonPath('data.spend.orders', 1);

    // Seller performance report: platform vouchers don't reduce seller revenue.
    actingAsSeller($seller);
    $this->getJson('/api/seller/reports/summary')->assertOk()->assertJsonPath('data.metrics.grossRevenue.value', 500);
});
