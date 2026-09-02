<?php

use App\Models\OrderReturnRequest;

function validReturnPayload(string $orderItemId, array $overrides = []): array
{
    return array_merge([
        'order_item_id' => $orderItemId,
        'request_type' => 'return_and_refund',
        'reason' => 'damaged',
        'details' => 'Arrived with a cracked screen, unusable.',
        'quantity' => 1,
        'evidence' => ['data:image/png;base64,iVBORw0KGgo='],
    ], $overrides);
}

it('creates a return request for a delivered order item', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    [$order, $item] = makeOrder($buyer, $seller, ['status' => 'Delivered']);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/returns', validReturnPayload($item->id))
        ->assertCreated()
        ->assertJsonPath('data.status', 'Pending')
        ->assertJsonPath('data.requestType', 'return_and_refund');

    $row = OrderReturnRequest::first();
    expect($row->buyer_profile_id)->toBe($buyer->id);
    expect($row->seller_id)->toBe($seller->id);
    expect((float) $row->estimated_amount)->toBe(100.0);
});

it('rejects a return on an order that is not delivered', function () {
    $buyer = makeBuyer();
    [$order, $item] = makeOrder($buyer, makeSeller(), ['status' => 'In Transit']);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/returns', validReturnPayload($item->id))->assertStatus(422);
    expect(OrderReturnRequest::count())->toBe(0);
});

it("rejects a return for an item on another buyer's order", function () {
    $buyer = makeBuyer();
    [$order, $item] = makeOrder(makeBuyer(), makeSeller(), ['status' => 'Delivered']);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/returns', validReturnPayload($item->id))->assertStatus(422);
});

it('rejects a second open request for the same item', function () {
    $buyer = makeBuyer();
    [$order, $item] = makeOrder($buyer, makeSeller(), ['status' => 'Delivered']);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/returns', validReturnPayload($item->id))->assertCreated();
    $this->postJson('/api/buyer/returns', validReturnPayload($item->id))->assertStatus(422);

    expect(OrderReturnRequest::count())->toBe(1);
});

it('rejects a quantity greater than what was ordered', function () {
    $buyer = makeBuyer();
    [$order, $item] = makeOrder($buyer, makeSeller(), ['status' => 'Delivered']);
    $item->update(['quantity' => 2]);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/returns', validReturnPayload($item->id, ['quantity' => 3]))
        ->assertStatus(422);
});

it('requires at least one evidence image and a 10+ char reason', function () {
    $buyer = makeBuyer();
    [$order, $item] = makeOrder($buyer, makeSeller(), ['status' => 'Delivered']);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/returns', validReturnPayload($item->id, ['evidence' => []]))
        ->assertStatus(422)->assertJsonValidationErrors('evidence');

    $this->postJson('/api/buyer/returns', validReturnPayload($item->id, ['details' => 'too short']))
        ->assertStatus(422)->assertJsonValidationErrors('details');
});

it('surfaces the return request on the order detail payload', function () {
    $buyer = makeBuyer();
    [$order, $item] = makeOrder($buyer, makeSeller(), ['status' => 'Delivered']);

    actingAsBuyer($buyer);
    $this->postJson('/api/buyer/returns', validReturnPayload($item->id))->assertCreated();

    $this->getJson("/api/buyer/orders/{$order->order_number}")
        ->assertOk()
        ->assertJsonPath('data.items.0.returnRequest.status', 'Pending');
});
