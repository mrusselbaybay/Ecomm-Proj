<?php

it('returns the buyer order stage used by each orders tab', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();

    [$toShip] = makeOrder($buyer, $seller, ['status' => 'Processing', 'payment_method' => 'cod']);
    [$toReceive] = makeOrder($buyer, $seller, ['status' => 'In Transit', 'payment_method' => 'cod']);
    [$completed] = makeOrder($buyer, $seller, ['status' => 'Delivered', 'payment_method' => 'cod']);
    [$cancelled] = makeOrder($buyer, $seller, ['status' => 'Cancelled', 'payment_method' => 'cod']);

    actingAsBuyer($buyer);

    $response = $this->getJson('/api/buyer/orders')->assertOk();
    $orders = collect($response->json('data'))->keyBy('orderId');

    expect($orders['#'.$toShip->order_number]['stage'])->toBe('to_ship')
        ->and($orders['#'.$toReceive->order_number]['stage'])->toBe('to_receive')
        ->and($orders['#'.$completed->order_number]['stage'])->toBe('completed')
        ->and($orders['#'.$cancelled->order_number]['stage'])->toBe('cancelled');
});

it('puts unpaid online orders in to pay while cod orders wait to ship', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();

    [$online] = makeOrder($buyer, $seller, ['payment_method' => 'card', 'payment_status' => 'Unpaid']);
    [$cod] = makeOrder($buyer, $seller, ['payment_method' => 'cod', 'payment_status' => 'Unpaid']);

    actingAsBuyer($buyer);

    $orders = collect($this->getJson('/api/buyer/orders')->assertOk()->json('data'))->keyBy('orderId');

    expect($orders['#'.$online->order_number]['stage'])->toBe('to_pay')
        ->and($orders['#'.$cod->order_number]['stage'])->toBe('to_ship');
});
