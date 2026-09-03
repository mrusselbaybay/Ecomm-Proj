<?php

use App\Models\Order;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Covers the parcel confirmation token minted when a seller dispatches an
 * order from Prepare Orders (status -> 'In Transit'), and its exposure in
 * the order-detail payload the seller SPA renders the QR from.
 *
 * The `logistics_companies` / `addresses` tables are Supabase-managed with
 * no Laravel migration — SellerOrderController eager-loads seller/buyer
 * addresses on every status update, so `addresses` just has to exist. Same
 * ad hoc fixture the Logistics*ApiTest / SellerHandoverParcelIntakeTest
 * files use.
 */
beforeEach(function () {
    if (! Schema::hasTable('logistics_companies')) {
        Schema::create('logistics_companies', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('owner_profile_id');
            $table->string('company_name');
            $table->string('status')->default('approved');
            $table->string('account_status')->default('active');
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('addresses')) {
        Schema::create('addresses', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('profile_id');
            $table->string('owner_kind');
        });
    }
});

it('mints a confirmation token when the seller dispatches, and returns its QR payload', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    [$order] = makeOrder($buyer, $seller, ['status' => 'Packed']);

    expect($order->confirmation_token)->toBeNull();

    actingAsSeller($seller);

    $response = $this->putJson("/api/seller/orders/{$order->order_number}/status", [
        'status' => 'In Transit',
        'tracking_number' => 'NXM-55501',
        'shipping_carrier' => 'Some Courier',
        'shipping_service' => 'Standard',
    ])->assertOk();

    $order->refresh();

    expect($order->confirmation_token)->not->toBeNull()
        ->and($order->confirmation_token)->toMatch('/^[0-9a-f]{40}$/');

    $response->assertJsonPath('data.dispatch.confirmationToken', $order->confirmation_token)
        ->assertJsonPath('data.dispatch.qrPayload', 'NXP:'.$order->confirmation_token);
});

it('keeps the same token if the order is dispatched again (idempotent status write)', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    [$order] = makeOrder($buyer, $seller, ['status' => 'In Transit']);
    $order->forceFill(['confirmation_token' => Order::generateConfirmationToken()])->save();
    $original = $order->confirmation_token;

    actingAsSeller($seller);

    // Same status in -> no-op, token untouched.
    $this->putJson("/api/seller/orders/{$order->order_number}/status", [
        'status' => 'In Transit',
    ])->assertOk()
        ->assertJsonPath('data.dispatch.qrPayload', 'NXP:'.$original);

    expect($order->fresh()->confirmation_token)->toBe($original);
});

it('assigns the token and a generated tracking number on demand, before dispatch', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    [$order] = makeOrder($buyer, $seller, ['status' => 'Packed']);

    actingAsSeller($seller);

    $first = $this->postJson("/api/seller/orders/{$order->order_number}/dispatch-prep")
        ->assertOk()
        ->json('data');

    expect($first['confirmationToken'])->toMatch('/^[0-9a-f]{40}$/')
        ->and($first['qrPayload'])->toBe('NXP:'.$first['confirmationToken'])
        ->and($first['trackingNumber'])->toMatch('/^TRK-\d{8}-\d{4}$/');

    // Idempotent — a second call returns the same identifiers, and
    // dispatching later reuses them rather than generating new ones.
    $this->postJson("/api/seller/orders/{$order->order_number}/dispatch-prep")
        ->assertOk()
        ->assertJsonPath('data.confirmationToken', $first['confirmationToken'])
        ->assertJsonPath('data.trackingNumber', $first['trackingNumber']);

    $this->putJson("/api/seller/orders/{$order->order_number}/status", ['status' => 'In Transit'])
        ->assertOk()
        ->assertJsonPath('data.dispatch.qrPayload', $first['qrPayload'])
        ->assertJsonPath('data.shipping.trackingNumber', $first['trackingNumber']);
});

it('generates a tracking number at dispatch when the seller sent none', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    [$order] = makeOrder($buyer, $seller, ['status' => 'Packed']);

    actingAsSeller($seller);

    $this->putJson("/api/seller/orders/{$order->order_number}/status", ['status' => 'In Transit'])
        ->assertOk()
        ->assertJsonPath(
            'data.shipping.trackingNumber',
            fn ($tn) => is_string($tn) && preg_match('/^TRK-\d{8}-\d{4}$/', $tn) === 1,
        );

    expect($order->fresh()->tracking_number)->toMatch('/^TRK-\d{8}-\d{4}$/');
});

it('numbers same-day tracking numbers in sequence', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    $date = now()->format('Ymd');

    expect(\App\Models\Order::generateTrackingNumber())->toBe("TRK-{$date}-0001");

    \App\Models\Order::query()->whereKey(makeOrder($buyer, $seller)[0]->id)
        ->update(['tracking_number' => "TRK-{$date}-0001"]);

    expect(\App\Models\Order::generateTrackingNumber())->toBe("TRK-{$date}-0002");
});

it('refuses to prep a cancelled order for dispatch', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    [$order] = makeOrder($buyer, $seller, ['status' => 'Cancelled']);

    actingAsSeller($seller);

    $this->postJson("/api/seller/orders/{$order->order_number}/dispatch-prep")
        ->assertStatus(422);

    expect($order->fresh()->confirmation_token)->toBeNull()
        ->and($order->fresh()->tracking_number)->toBeNull();
});

it('does not expose a QR payload for an order that has not shipped', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    [$order] = makeOrder($buyer, $seller, ['status' => 'Confirmed']);

    actingAsSeller($seller);

    $this->getJson("/api/seller/orders/{$order->order_number}")
        ->assertOk()
        ->assertJsonPath('data.dispatch.confirmationToken', null)
        ->assertJsonPath('data.dispatch.qrPayload', null);
});
