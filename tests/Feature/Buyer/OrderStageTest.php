<?php

/*
|--------------------------------------------------------------------------
| My Orders tabs (OrderStage) and order item photos
|--------------------------------------------------------------------------
*/

use App\Models\OrderReturnRequest;

function stagesByStatus(): array
{
    return collect(test()->getJson('/api/buyer/orders')->assertOk()->json('data'))
        ->mapWithKeys(fn (array $order) => [$order['status'].'/'.$order['payment_method'].'/'.$order['payment_status'] => $order['stage']])
        ->all();
}

it('maps real order statuses to the My Orders tabs without changing them', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();

    foreach (['New', 'Confirmed', 'Processing', 'Ready for Pickup', 'In Transit', 'Delivered', 'Cancelled', 'Rejected'] as $status) {
        makeOrder($buyer, $seller, ['status' => $status, 'payment_method' => 'cod']);
    }

    actingAsBuyer($buyer);

    expect(stagesByStatus())->toBe([
        'New/cod/Unpaid' => 'to_ship',
        'Confirmed/cod/Unpaid' => 'to_ship',
        'Processing/cod/Unpaid' => 'to_ship',
        'Ready for Pickup/cod/Unpaid' => 'to_ship',
        'In Transit/cod/Unpaid' => 'to_receive',
        'Delivered/cod/Unpaid' => 'completed',
        'Cancelled/cod/Unpaid' => 'cancelled',
        'Rejected/cod/Unpaid' => 'cancelled',
    ]);
});

it('only puts unpaid online payments under To Pay, never cash on delivery', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    makeOrder($buyer, $seller, ['status' => 'New', 'payment_method' => 'gcash', 'payment_status' => 'Unpaid']);
    makeOrder($buyer, $seller, ['status' => 'New', 'payment_method' => 'gcash', 'payment_status' => 'Paid']);
    makeOrder($buyer, $seller, ['status' => 'New', 'payment_method' => 'cod', 'payment_status' => 'Unpaid']);
    makeOrder($buyer, $seller, ['status' => 'In Transit', 'payment_method' => 'gcash', 'payment_status' => 'Unpaid']);

    actingAsBuyer($buyer);

    expect(stagesByStatus())->toBe([
        'New/gcash/Unpaid' => 'to_pay',
        'New/gcash/Paid' => 'to_ship',
        'New/cod/Unpaid' => 'to_ship',
        'In Transit/gcash/Unpaid' => 'to_receive',
    ]);
});

it('moves an order to Return/Refund while a request is open or settled', function (string $requestStatus, string $stage) {
    $buyer = makeBuyer();
    [$order, $item] = makeOrder($buyer, makeSeller(), ['status' => 'Delivered']);

    OrderReturnRequest::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'buyer_profile_id' => $buyer->id,
        'seller_id' => $order->seller_id,
        'request_type' => 'return_and_refund',
        'reason' => 'damaged',
        'details' => 'Cracked on arrival.',
        'quantity' => 1,
        'status' => $requestStatus,
    ]);

    actingAsBuyer($buyer);

    $this->getJson('/api/buyer/orders')->assertOk()
        ->assertJsonPath('data.0.stage', $stage)
        ->assertJsonPath('data.0.status', 'Delivered');
})->with([
    'pending' => ['pending', 'return_refund'],
    'approved' => ['approved', 'return_refund'],
    'completed' => ['completed', 'return_refund'],
    'rejected' => ['rejected', 'completed'],
    'withdrawn' => ['cancelled', 'completed'],
]);

it('includes each item photo: the variant, then the product, else none', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $photo = makeProduct($seller, ['images' => [['url' => 'https://cdn.example.test/mug.jpg']]]);
    $inline = makeProduct($seller, ['images' => [['url' => 'data:image/png;base64,iVBORw0KGgo=']]]);

    [$order, $first] = makeOrder($buyer, $seller);
    $first->update(['product_id' => $photo->id]);
    $second = $first->replicate();
    $second->product_id = $inline->id;
    $second->save();
    $third = $first->replicate();
    $third->product_id = null;
    $third->save();

    actingAsBuyer($buyer);

    $images = collect($this->getJson('/api/buyer/orders')->assertOk()->json('data.0.items'))->pluck('image', 'product_id');

    expect($images[$photo->id])->toBe('https://cdn.example.test/mug.jpg');
    expect($images[$inline->id])->toStartWith("/api/products/{$inline->id}/images/0?v=")->toContain('w=480');
    expect($images[''] ?? null)->toBeNull();
    expect($this->getJson('/api/buyer/orders')->getContent())->not->toContain('data:image');
});
