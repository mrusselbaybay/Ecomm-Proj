<?php

use App\Models\Order;

/**
 * @return array<string, mixed>
 */
function checkoutPayload(string $productId, array $overrides = []): array
{
    return array_merge([
        'items' => [
            ['product_id' => $productId, 'quantity' => 1],
        ],
        'delivery_address' => [
            'recipient_name' => 'Test Buyer',
            'contact_number' => '09171234567',
            'address' => '12 Mabini St, Quezon City, Metro Manila',
        ],
        'shipping_method' => 'standard',
        'payment_method' => 'cod',
    ], $overrides);
}

it('places a cash on delivery order as unpaid', function () {
    $buyer = makeBuyer();
    $product = makeProduct(makeSeller());

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', checkoutPayload($product->id))
        ->assertCreated()
        ->assertJsonPath('data.0.payment_method', 'cod')
        ->assertJsonPath('data.0.payment_status', 'Unpaid');

    $order = Order::where('buyer_profile_id', $buyer->id)->sole();

    expect($order->payment_method)->toBe('cod');
    expect($order->payment_status)->toBe('Unpaid');
});

it('rejects a payment method checkout cannot complete', function (string $method) {
    $buyer = makeBuyer();
    $product = makeProduct(makeSeller());

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', checkoutPayload($product->id, ['payment_method' => $method]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payment_method');

    expect(Order::where('buyer_profile_id', $buyer->id)->exists())->toBeFalse();
})->with(['card', 'gcash', 'maya']);

it('requires a payment method', function () {
    $buyer = makeBuyer();
    $product = makeProduct(makeSeller());

    actingAsBuyer($buyer);

    $payload = checkoutPayload($product->id);
    unset($payload['payment_method']);

    $this->postJson('/api/buyer/checkout', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payment_method');
});
