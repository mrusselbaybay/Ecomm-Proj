<?php

use App\Models\Order;
use App\Models\OrderItem;

function makeBuyerOrder(App\Models\Profile $buyer, App\Models\Profile $seller, array $overrides = []): Order
{
    $product = makeProduct($seller, ['name' => 'Desk Lamp', 'images' => [['url' => 'https://example.test/lamp.jpg']]]);

    $order = Order::create(array_merge([
        'order_number' => 'SN-'.random_int(10000, 99999),
        'seller_id' => $seller->id,
        'buyer_profile_id' => $buyer->id,
        'recipient_name' => 'Test Buyer',
        'status' => 'Delivered',
        'payment_status' => 'Paid',
        'subtotal' => 100,
        'shipping_fee' => 60,
        'total' => 160,
        'placed_at' => now()->addSecond(),
    ], $overrides));

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'category' => $product->category,
        'unit_price' => 100,
        'quantity' => 2,
        'subtotal' => 200,
    ]);

    return $order;
}

it('lists the buyers own orders with this seller for the product-inquiry picker', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller);

    for ($i = 0; $i < 5; $i++) {
        makeBuyerOrder($buyer, $seller);
    }

    // An order with a DIFFERENT seller must never appear.
    makeBuyerOrder($buyer, makeSeller());
    // Another buyer's order with the SAME seller must never appear either.
    makeBuyerOrder(makeBuyer(), $seller);

    actingAsBuyer($buyer);

    $page1 = $this->getJson("/api/buyer/messages/conversations/{$conversation->id}/products")
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.lastPage', 2)
        ->json('data');

    expect($page1[0]['previewName'])->toBe('Desk Lamp');
    expect($page1[0]['previewImage'])->toBe('https://example.test/lamp.jpg');
    expect($page1[0]['quantity'])->toBe(2);

    $this->getJson("/api/buyer/messages/conversations/{$conversation->id}/products?page=2")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('lets a buyer attach a product inquiry (order/product context, no body) visible to the seller', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller);
    $order = makeBuyerOrder($buyer, $seller);
    $item = $order->items->first();

    actingAsBuyer($buyer);

    $this->postJson("/api/buyer/messages/conversations/{$conversation->id}/messages", [
        'order_id' => $order->id,
        'product_id' => $item->product_id,
    ])->assertCreated()
        ->assertJsonPath('data.text', '')
        ->assertJsonPath('data.orderContext.orderNumber', $order->order_number)
        ->assertJsonPath('data.productContext.name', 'Desk Lamp')
        ->assertJsonPath('data.productContext.quantity', 2);

    actingAsSeller($seller);
    $messages = $this->getJson("/api/seller/messages/conversations/{$conversation->id}/messages")
        ->assertOk()
        ->json('data');

    $inquiry = collect($messages)->firstWhere('body', '');
    expect($inquiry)->not->toBeNull();
    expect($inquiry['productContext']['name'])->toBe('Desk Lamp');
    expect($inquiry['productContext']['quantity'])->toBe(2);

    // Someone else's order/product is rejected, not silently trusted.
    actingAsBuyer($buyer);
    $unrelatedOrder = makeBuyerOrder($buyer, makeSeller());

    $this->postJson("/api/buyer/messages/conversations/{$conversation->id}/messages", [
        'order_id' => $unrelatedOrder->id,
    ])->assertStatus(422);
});
