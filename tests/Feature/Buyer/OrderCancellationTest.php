<?php

use App\Models\OrderStatusHistory;

it('lets a buyer cancel their own New order and restores stock', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $product = makeProduct($seller, ['stock' => 5]);
    [$order, $item] = makeOrder($buyer, $seller);
    $item->update(['product_id' => $product->id, 'quantity' => 2]);

    actingAsBuyer($buyer);

    $response = $this->postJson("/api/buyer/orders/{$order->order_number}/cancel", [
        'reason' => 'Changed my mind',
    ]);

    $response->assertOk()->assertJsonPath('data.status', 'Cancelled');

    expect($order->fresh()->status)->toBe('Cancelled');
    expect($product->fresh()->stock)->toBe(7); // 5 + 2 restored
    expect(OrderStatusHistory::where('order_id', $order->id)->where('status', 'Cancelled')->exists())->toBeTrue();
});

it('refunds a paid order on cancellation', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    [$order] = makeOrder($buyer, $seller, ['payment_status' => 'Paid']);

    actingAsBuyer($buyer);

    $this->postJson("/api/buyer/orders/{$order->order_number}/cancel")->assertOk();

    expect($order->fresh()->payment_status)->toBe('Refunded');
});

it('refuses to cancel an order already being processed', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    [$order] = makeOrder($buyer, $seller, ['status' => 'Processing']);

    actingAsBuyer($buyer);

    $this->postJson("/api/buyer/orders/{$order->order_number}/cancel")
        ->assertStatus(422);

    expect($order->fresh()->status)->toBe('Processing');
});

it("404s when cancelling another buyer's order and leaves it untouched", function () {
    $buyer = makeBuyer();
    $otherBuyer = makeBuyer();
    $seller = makeSeller();
    [$order] = makeOrder($otherBuyer, $seller);

    actingAsBuyer($buyer);

    $this->postJson("/api/buyer/orders/{$order->order_number}/cancel")->assertStatus(404);

    expect($order->fresh()->status)->toBe('New');
});

it('requires authentication', function () {
    $seller = makeSeller();
    [$order] = makeOrder(makeBuyer(), $seller);

    $this->postJson("/api/buyer/orders/{$order->order_number}/cancel")->assertStatus(401);
});
