<?php

use App\Models\ParcelAssignment;
use Illuminate\Support\Str;

/**
 * Covers the `parcel` field Buyer\OrderController::transform() adds for
 * the buyer-facing order timeline (resources/js/buyer/composables/
 * useOrderTimeline.js) — the same sorting-center-vs-out-for-delivery split
 * the seller side gets from SellerOrderController, so a buyer sees the
 * real state of their shipped parcel instead of a flat "Shipped".
 */
function makeBuyerSideParcelAssignment(\App\Models\Order $order, array $overrides = []): ParcelAssignment
{
    return ParcelAssignment::create(array_merge([
        'order_id' => $order->id,
        'logistics_company_id' => (string) Str::uuid(),
        'status' => ParcelAssignment::STATUS_RECEIVED,
        'received_at' => now(),
    ], $overrides));
}

it('reports no parcel data for an order that has not shipped yet', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    [$order] = makeOrder($buyer, $seller, ['status' => 'Processing']);

    actingAsBuyer($buyer);

    $this->getJson("/api/buyer/orders/{$order->order_number}")
        ->assertOk()
        ->assertJsonPath('data.parcel', null);
});

it('reports riderAssigned=false while the parcel sits at the sorting center', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    [$order] = makeOrder($buyer, $seller, ['status' => 'In Transit']);

    makeBuyerSideParcelAssignment($order, [
        'status' => ParcelAssignment::STATUS_SORTED,
        'rider_profile_id' => null,
    ]);

    actingAsBuyer($buyer);

    $this->getJson("/api/buyer/orders/{$order->order_number}")
        ->assertOk()
        ->assertJsonPath('data.parcel.riderAssigned', false);
});

it('reports riderAssigned=true once a rider is assigned to the parcel', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    [$order] = makeOrder($buyer, $seller, ['status' => 'In Transit']);

    $rider = makeBuyer(['role' => 'courier']);
    makeBuyerSideParcelAssignment($order, [
        'status' => ParcelAssignment::STATUS_ASSIGNED,
        'rider_profile_id' => $rider->id,
        'assigned_at' => now(),
    ]);

    actingAsBuyer($buyer);

    $this->getJson("/api/buyer/orders/{$order->order_number}")
        ->assertOk()
        ->assertJsonPath('data.parcel.riderAssigned', true)
        ->assertJsonPath('data.status', 'In Transit');
});
