<?php

use App\Models\ParcelAssignment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Covers the seller "Order Progression" timeline (SellerOrderController::
 * transformDetail / timelineRows) — the granular sorting-center vs
 * out-for-delivery split, and that "being prepared" (Processing) can only
 * be reached once the seller has actually accepted the order.
 */
beforeEach(function () {
    // 'addresses' is a Supabase-managed table with no Laravel migration —
    // SellerOrderController eager-loads seller.address/buyer.address on
    // every order fetch, so it just needs to exist (see the same stub in
    // SellerHandoverParcelIntakeTest).
    if (! Schema::hasTable('addresses')) {
        Schema::create('addresses', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('profile_id');
            $table->string('owner_kind');
        });
    }
});

function makeParcelAssignment(\App\Models\Order $order, array $overrides = []): ParcelAssignment
{
    return ParcelAssignment::create(array_merge([
        'order_id' => $order->id,
        'logistics_company_id' => (string) Str::uuid(),
        'status' => ParcelAssignment::STATUS_RECEIVED,
        'received_at' => now(),
    ], $overrides));
}

it('cannot jump straight from New to Processing — the seller must accept (Confirm) first', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    [$order] = makeOrder($buyer, $seller, ['status' => 'New']);

    actingAsSeller($seller);

    $this->putJson("/api/seller/orders/{$order->order_number}/status", [
        'status' => 'Processing',
    ])->assertStatus(422);

    expect($order->fresh()->status)->toBe('New');
});

it('still allows New -> Confirmed -> Processing', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    [$order] = makeOrder($buyer, $seller, ['status' => 'New']);

    actingAsSeller($seller);

    $this->putJson("/api/seller/orders/{$order->order_number}/status", ['status' => 'Confirmed'])
        ->assertOk();

    $this->putJson("/api/seller/orders/{$order->order_number}/status", ['status' => 'Processing'])
        ->assertOk();

    expect($order->fresh()->status)->toBe('Processing');
});

it('labels the shipped checkpoint "Parcel in Sorting Center" while no rider is assigned yet', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    // makeOrder() stamps the single status_history row with the order's
    // own (overridden) status, so passing 'In Transit' here already gives
    // us exactly one 'In Transit' row — no need to add another.
    [$order] = makeOrder($buyer, $seller, ['status' => 'In Transit']);

    makeParcelAssignment($order, ['status' => ParcelAssignment::STATUS_SORTED, 'rider_profile_id' => null]);

    actingAsSeller($seller);

    $timeline = $this->getJson("/api/seller/orders/{$order->order_number}?include_journey=0")
        ->assertOk()
        ->json('data.timeline');

    $labels = array_column($timeline, 'label');

    expect($labels)->toContain('Parcel in Sorting Center')
        ->and($labels)->not->toContain('Parcel is out for delivery')
        ->and($labels)->not->toContain('Shipped');
});

it('adds "Parcel is out for delivery" after "Parcel in Sorting Center" once a rider is assigned', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    [$order] = makeOrder($buyer, $seller, ['status' => 'In Transit']);

    $rider = makeBuyer(['role' => 'courier']); // any Profile row works as the FK target here
    makeParcelAssignment($order, [
        'status' => ParcelAssignment::STATUS_ASSIGNED,
        'rider_profile_id' => $rider->id,
        'assigned_at' => now(),
    ]);

    actingAsSeller($seller);

    $timeline = $this->getJson("/api/seller/orders/{$order->order_number}?include_journey=0")
        ->assertOk()
        ->json('data.timeline');

    $labels = array_column($timeline, 'label');
    $sortingIdx = array_search('Parcel in Sorting Center', $labels, true);
    $outIdx = array_search('Parcel is out for delivery', $labels, true);

    expect($sortingIdx)->not->toBeFalse()
        ->and($outIdx)->not->toBeFalse()
        ->and($outIdx)->toBeGreaterThan($sortingIdx)
        // Exactly one of each — never duplicated on both sides.
        ->and(array_count_values($labels)['Parcel in Sorting Center'])->toBe(1)
        ->and(array_count_values($labels)['Parcel is out for delivery'])->toBe(1);
});

it('only marks "Preparing items" once the order has actually been confirmed', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    [$order] = makeOrder($buyer, $seller, ['status' => 'New']);

    actingAsSeller($seller);

    $this->putJson("/api/seller/orders/{$order->order_number}/status", ['status' => 'Confirmed'])->assertOk();
    $this->putJson("/api/seller/orders/{$order->order_number}/status", ['status' => 'Processing'])->assertOk();

    $timeline = $this->getJson("/api/seller/orders/{$order->order_number}?include_journey=0")
        ->assertOk()
        ->json('data.timeline');

    $labels = array_column($timeline, 'label');
    $confirmedIdx = array_search('Confirmed by seller', $labels, true);
    $preparingIdx = array_search('Preparing items', $labels, true);

    expect($confirmedIdx)->not->toBeFalse()
        ->and($preparingIdx)->not->toBeFalse()
        ->and($preparingIdx)->toBeGreaterThan($confirmedIdx);
});
