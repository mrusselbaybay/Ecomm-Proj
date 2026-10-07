<?php

use App\Models\CourierDetail;
use App\Models\CourierEarning;
use App\Models\CourierPayout;
use App\Models\LogisticsCompany;
use App\Models\OrderReturnRequest;
use App\Models\ParcelAssignment;
use App\Services\CourierEarningService;
use App\Services\Payments\MockPaymentService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoCourierEarningsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T04:00:00Z'));
    if (! Schema::hasTable('courier_details')) {
        Schema::create('courier_details', function (Blueprint $table) {
            $table->uuid('profile_id')->primary();
            $table->uuid('logistics_company_id')->nullable();
            $table->timestamps();
        });
    }
    if (! Schema::hasTable('logistics_admin_details')) {
        Schema::create('logistics_admin_details', function (Blueprint $table) {
            $table->uuid('profile_id')->primary();
            $table->uuid('logistics_company_id');
            $table->string('role')->default('admin');
            $table->string('status')->default('active');
        });
    }
    if (! Schema::hasTable('logistics_companies')) {
        Schema::create('logistics_companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_profile_id');
            $table->string('company_name');
            $table->string('status')->default('approved');
            $table->string('account_status')->default('active');
            $table->unsignedSmallInteger('courier_share_bps')->default(8000);
            $table->unsignedSmallInteger('pickup_weight')->default(30);
            $table->unsignedSmallInteger('transfer_weight')->default(10);
            $table->unsignedSmallInteger('delivery_weight')->default(60);
            $table->unsignedSmallInteger('cod_overdue_days')->default(2);
            $table->unsignedBigInteger('early_cashout_minimum_cents')->nullable();
            $table->timestamps();
        });
    }
});

function earningFixture(): array
{
    $owner = makeLogistics();
    $courier = makeCourier();
    $company = LogisticsCompany::create(['id' => (string) Str::uuid(), 'owner_profile_id' => $owner->id, 'company_name' => 'Earnings Logistics', 'status' => 'approved', 'account_status' => 'active']);
    CourierDetail::create(['profile_id' => $courier->id, 'logistics_company_id' => $company->id]);
    [$order] = makeOrder(makeBuyer(), makeSeller(), ['status' => 'In Transit', 'shipping_fee' => '10.01', 'total' => '110.01']);
    $leg = ParcelAssignment::create(['order_id' => $order->id, 'logistics_company_id' => $company->id, 'rider_profile_id' => $courier->id,
        'picked_up_by' => $courier->id, 'status' => 'handed_off', 'received_at' => now()]);

    return [$company->refresh(), $courier, $order, $leg, $owner];
}

function earningAdjustment($company, $courier, int $amount, string $type = 'bonus'): CourierEarning
{
    return app(CourierEarningService::class)->adjustment($company, $courier->id, $type, $amount, 'Test adjustment', $company->owner_profile_id);
}

function earningActAs($profile): void
{
    fakeApiTokens(fn ($token) => str_starts_with($token, 'earning-test:') ? substr($token, 13) : null);
    test()->withHeader('Authorization', 'Bearer earning-test:'.$profile->id);
}

it('rescales missing tasks and conserves centavos', function () {
    expect(CourierEarningService::taskAmounts(10001, 8000, ['pickup' => 30, 'delivery' => 60]))->toBe(['pickup' => 2667, 'delivery' => 5334])
        ->and(CourierEarningService::taskAmounts(10001, 8000, ['pickup' => 30]))->toBe(['pickup' => 8001]);
});

it('records tasks once and snapshots settings before making final earnings available', function () {
    [$company, $courier, $order, $leg] = earningFixture();
    $service = app(CourierEarningService::class);
    $pickup = $service->record($leg, $courier->id, 'pickup', 'pickup.jpg');
    $service->record($leg, $courier->id, 'pickup', 'pickup.jpg');
    expect(CourierEarning::count())->toBe(1)->and($pickup->status)->toBe('pending');
    $company->update(['courier_share_bps' => 5000, 'pickup_weight' => 100, 'delivery_weight' => 0, 'transfer_weight' => 0]);
    $leg->update(['delivered_at' => now()]);
    $service->record($leg->fresh(), $courier->id, 'delivery', 'delivered.jpg');
    $order->update(['status' => 'Delivered']);
    $payments = app(MockPaymentService::class);
    $payments->chargeBuyer($order->id, $order->total);
    $payments->releaseEscrow($order->id);
    expect(CourierEarning::where('status', 'available')->count())->toBe(2)
        ->and((int) CourierEarning::sum('amount_cents'))->toBe(761)
        ->and(CourierEarning::where('type', 'delivery')->first()->rate_bps)->toBe(8000);
    $payout = $service->statement($company, $courier->id, $company->owner_profile_id, now()->subDays(6)->toDateString(), now()->toDateString());
    $service->payoutAction($payout, 'approve', $company->owner_profile_id);
    $service->payoutAction($payout, 'pay', $company->owner_profile_id, 'BANK-001');
    expect(CourierEarning::where('status', 'paid')->count())->toBe(2);
    $payments->settleRefundOnly($order->id, (string) Str::uuid(), 10000);
    expect(CourierEarning::where('status', 'paid')->count())->toBe(2);
});

it('allocates company shares equally over physical parcels rather than company transfer rows', function () {
    [$company, $courier, $order, $leg] = earningFixture();
    $other = ParcelAssignment::create(['order_id' => $order->id, 'logistics_company_id' => $company->id, 'picked_up_by' => $courier->id, 'received_at' => now()]);
    $service = app(CourierEarningService::class);
    $a = $service->record($leg, $courier->id, 'pickup', 'a.jpg');
    $b = $service->record($other, $courier->id, 'pickup', 'b.jpg');
    $service->settle($order->id);
    expect($a->fresh()->source_leg_cents + $b->fresh()->source_leg_cents)->toBe(951)
        ->and(abs($a->fresh()->source_leg_cents - $b->fresh()->source_leg_cents))->toBeLessThanOrEqual(1);
});

it('caps failed-attempt pay once per parcel and preserves it through refunds', function () {
    [$company, $courier, $order, $leg] = earningFixture();
    $service = app(CourierEarningService::class);
    $leg->update(['status' => 'failed_attempt']);
    $pickup = $service->record($leg, $courier->id, 'pickup', 'pickup.jpg');
    $first = $service->record($leg, $courier->id, 'failed_attempt', 'attempt.jpg');
    $second = $service->record($leg, makeCourier()->id, 'failed_attempt', 'retry.jpg');
    expect($first->id)->toBe($second->id)->and($first->amount_cents)->toBe(101);
    $order->update(['status' => 'Delivered']);
    $payments = app(MockPaymentService::class);
    $payments->chargeBuyer($order->id, $order->total);
    $payments->settleRefundOnly($order->id, (string) Str::uuid(), 10000);
    expect($first->fresh()->status)->toBe('available')->and($pickup->fresh()->amount_cents)->toBe(254);
});

it('nets COD into a linked remittance once and blocks overdue debt', function () {
    [$company, $courier, $order, $leg] = earningFixture();
    earningAdjustment($company, $courier, 20000);
    $order->update(['payment_method' => 'COD']);
    $service = app(CourierEarningService::class);
    $service->collectCod($leg->fresh(), $courier->id);
    $service->collectCod($leg->fresh(), $courier->id);
    expect(DB::table('courier_cod_collections')->count())->toBe(1);
    $payout = $service->statement($company, $courier->id, $company->owner_profile_id, now()->subDays(6)->toDateString(), now()->toDateString());
    expect($payout->net_cents)->toBe(8999);
    $service->payoutAction($payout, 'approve', $company->owner_profile_id);
    $service->payoutAction($payout, 'pay', $company->owner_profile_id, 'COD-OFFSET');
    expect((int) DB::table('courier_cod_collections')->sum('remaining_cents'))->toBe(0)
        ->and((int) DB::table('courier_cod_remittances')->where('payout_id', $payout->id)->sum('amount_cents'))->toBe(11001);
    earningAdjustment($company, $courier, 1000);
    $next = $service->statement($company, $courier->id, $company->owner_profile_id, now()->subDays(6)->toDateString(), now()->toDateString());
    expect($next->net_cents)->toBe(1000);
    $service->payoutAction($next, 'cancel', $company->owner_profile_id);
    DB::table('courier_cod_collections')->update(['remaining_cents' => 1, 'collected_at' => now()->subDays(3)]);
    expect(fn () => $service->statement($company, $courier->id, $company->owner_profile_id, now()->subDays(6)->toDateString(), now()->toDateString()))->toThrow(ValidationException::class);
});

it('carries deductions forward and prevents overlapping statements', function () {
    [$company, $courier] = earningFixture();
    earningAdjustment($company, $courier, 1000);
    $deduction = earningAdjustment($company, $courier, 1600, 'deduction');
    $service = app(CourierEarningService::class);
    $payout = $service->statement($company, $courier->id, $company->owner_profile_id, now()->subDays(6)->toDateString(), now()->toDateString());
    expect($payout->net_cents)->toBe(0);
    expect(fn () => $service->statement($company, $courier->id, $company->owner_profile_id, now()->subDays(6)->toDateString(), now()->toDateString()))->toThrow(ValidationException::class);
    $service->payoutAction($payout, 'approve', $company->owner_profile_id);
    $service->payoutAction($payout, 'pay', $company->owner_profile_id, 'ZERO');
    expect($deduction->fresh()->remaining_cents)->toBe(-600)->and($deduction->fresh()->status)->toBe('available');
    earningAdjustment($company, $courier, 1000);
    expect($service->statement($company, $courier->id, $company->owner_profile_id, now()->subDays(6)->toDateString(), now()->toDateString())->net_cents)->toBe(400);
});

it('keeps early cash-out off until a minimum is configured', function () {
    [$company, $courier] = earningFixture();
    earningAdjustment($company, $courier, 60000);
    $service = app(CourierEarningService::class);
    expect(fn () => $service->statement($company, $courier->id, $company->owner_profile_id, now()->toDateString(), now()->toDateString(), true))->toThrow(ValidationException::class);
    $company->update(['early_cashout_minimum_cents' => 50000]);
    expect($service->statement($company, $courier->id, $company->owner_profile_id, now()->toDateString(), now()->toDateString(), true)->kind)->toBe('early');
});

it('pays approved return legs and does not pay RTS legs', function () {
    [$company, $courier, $order, $leg] = earningFixture();
    $order->update(['delivery_return_flagged_at' => now()]);
    $service = app(CourierEarningService::class);
    expect($service->record($leg->fresh(), $courier->id, 'transfer', 'rts.jpg'))->toBeNull();
    $request = OrderReturnRequest::create(['order_id' => $order->id, 'buyer_profile_id' => $order->buyer_profile_id,
        'seller_id' => $order->seller_id, 'request_type' => 'return_and_refund', 'reason' => 'damaged', 'details' => 'Damaged item', 'quantity' => 1,
        'estimated_amount' => '100.00', 'status' => 'approved', 'return_shipping_fee' => '10.01']);
    $return = ParcelAssignment::create(['order_id' => $order->id, 'logistics_company_id' => $company->id, 'return_request_id' => $request->id,
        'picked_up_by' => $courier->id, 'rider_profile_id' => $courier->id, 'received_at' => now(), 'delivered_at' => now()]);
    $a = $service->record($return, $courier->id, 'pickup', 'return-pickup.jpg');
    $b = $service->record($return, $courier->id, 'delivery', 'return-delivery.jpg');
    expect($a->status)->toBe('pending');
    $order->update(['status' => 'Delivered']);
    app(MockPaymentService::class)->settleReturn($order->id, $request->id, 10000, 0, 1001, [$company->id]);
    expect($a->fresh()->status)->toBe('available')->and($b->fresh()->status)->toBe('available')
        ->and($a->fresh()->source_leg_cents)->toBe(951);
});

it('scopes the courier ledger and statements and enforces owner and company-admin access', function () {
    [$company, $courier, , , $owner] = earningFixture();
    $entry = earningAdjustment($company, $courier, 60000);
    actingAsDriver($courier);
    $this->getJson('/api/courier/earnings')->assertOk()->assertJsonPath('data.data.0.id', $entry->id);
    $this->getJson('/api/courier/earnings/summary')->assertOk()->assertJsonPath('data.available_cents', 60000);
    $this->postJson('/api/courier/earnings/early-cashout')->assertUnprocessable();
    earningActAs($owner);
    $this->getJson('/api/logistics/earnings/couriers')->assertOk()->assertJsonPath('data.can_manage', true);
    $statement = $this->postJson('/api/logistics/earnings/payouts', ['courier_id' => $courier->id,
        'period_start' => now()->subDays(6)->toDateString(), 'period_end' => now()->toDateString()])->assertCreated()->json('data.id');
    $this->postJson('/api/logistics/earnings/payouts/'.$statement.'/pay', ['reference' => 'INVALID'])->assertUnprocessable();
    $this->postJson('/api/logistics/earnings/payouts/'.$statement.'/approve')->assertOk();
    $this->postJson('/api/logistics/earnings/payouts/'.$statement.'/pay', ['reference' => 'BANK-API'])->assertOk()->assertJsonPath('data.status', 'paid');
    actingAsDriver(makeCourier());
    $this->getJson('/api/courier/payouts/'.$statement)->assertNotFound();
    $this->getJson('/api/courier/earnings')->assertOk()->assertJsonCount(0, 'data.data');
    $member = makeLogistics();
    DB::table('logistics_admin_details')->insert(['profile_id' => $member->id, 'logistics_company_id' => $company->id, 'role' => 'operator', 'status' => 'active']);
    earningActAs($member);
    $this->postJson('/api/logistics/earnings/adjustments', ['courier_id' => $courier->id, 'type' => 'bonus', 'amount_cents' => 100, 'reason' => 'Unauthorized'])->assertForbidden();
    DB::table('logistics_admin_details')->where('profile_id', $member->id)->update(['role' => 'admin']);
    $this->postJson('/api/logistics/earnings/adjustments', ['courier_id' => $courier->id, 'type' => 'tip', 'amount_cents' => 100, 'reason' => 'Customer tip'])->assertCreated()->assertJsonPath('data.amount_cents', 100);
    expect(DB::table('courier_earning_audits')->where('action', 'adjustment_created')->count())->toBe(2);
});

it('requires valid settings and preserves original ledger amounts on later setting changes', function () {
    [$company, $courier, , , $owner] = earningFixture();
    earningActAs($owner);
    $data = ['courier_share_bps' => 8000, 'pickup_weight' => 30, 'transfer_weight' => 10, 'delivery_weight' => 60,
        'cod_overdue_days' => 2, 'early_cashout_minimum_cents' => null, 'reason' => 'Company policy'];
    $this->putJson('/api/logistics/earnings/settings', $data)->assertOk()->assertJsonPath('data.courier_share_bps', 8000);
    $this->putJson('/api/logistics/earnings/settings', array_replace($data, ['pickup_weight' => 31]))->assertUnprocessable();
    $this->putJson('/api/logistics/earnings/settings', array_replace($data, ['courier_share_bps' => 10001]))->assertUnprocessable();
    $this->postJson('/api/logistics/earnings/adjustments', ['courier_id' => $courier->id, 'type' => 'deduction', 'amount_cents' => 100])->assertUnprocessable();
});

it('keeps COD debt beyond earnings and rejects statements whose COD offset has changed', function () {
    [$company, $courier, $order, $leg] = earningFixture();
    $service = app(CourierEarningService::class);
    earningAdjustment($company, $courier, 1000);
    $order->update(['payment_method' => 'COD']);
    $service->collectCod($leg->fresh(), $courier->id);
    $payout = $service->statement($company, $courier->id, $company->owner_profile_id, now()->subDays(6)->toDateString(), now()->toDateString());
    $service->payoutAction($payout, 'approve', $company->owner_profile_id);
    $service->payoutAction($payout, 'pay', $company->owner_profile_id, 'DEBT');
    expect((int) DB::table('courier_cod_collections')->sum('remaining_cents'))->toBe(10001);
    earningAdjustment($company, $courier, 20000);
    $next = $service->statement($company, $courier->id, $company->owner_profile_id, now()->subDays(6)->toDateString(), now()->toDateString());
    $service->remit($company, $courier->id, 100, $company->owner_profile_id, 'CASH');
    expect(fn () => $service->payoutAction($next, 'approve', $company->owner_profile_id))->toThrow(ValidationException::class);
});

it('seeds repeatable demo earnings, COD and payouts for existing demo couriers', function () {
    [, $courier] = earningFixture();
    $courier->update(['email' => 'dummy.courier.earnings@example.test']);
    $this->seed(DemoCourierEarningsSeeder::class);
    expect(CourierPayout::where('courier_id', $courier->id)->where('status', 'paid')->count())->toBe(1)
        ->and(DB::table('courier_cod_remittances')->count())->toBe(2)
        ->and(CourierEarning::where('courier_id', $courier->id)->where('status', 'pending')->count())->toBe(2);
    $count = CourierEarning::count();
    $this->seed(DemoCourierEarningsSeeder::class);
    expect(CourierEarning::count())->toBe($count);
});

it('loads task earning previews in batches rather than querying per task card', function () {
    [$company, $courier, $order, $leg] = earningFixture();
    $leg->update(['status' => 'assigned']);
    actingAsDriver($courier);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson('/api/driver/deliveries')->assertOk()->assertJsonCount(1, 'data');
    $single = count(DB::getQueryLog());
    for ($i = 0; $i < 12; $i++) {
        $copy = $order->replicate();
        $copy->order_number = 'PREVIEW-'.$i;
        $copy->save();
        ParcelAssignment::create(['order_id' => $copy->id, 'logistics_company_id' => $company->id,
            'rider_profile_id' => $courier->id, 'status' => 'assigned', 'received_at' => now()]);
    }
    DB::flushQueryLog();
    $this->getJson('/api/driver/deliveries')->assertOk()->assertJsonCount(13, 'data')->assertJsonStructure(['data' => [['earning_preview_cents']]]);
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($single + 2);
    DB::disableQueryLog();
});
