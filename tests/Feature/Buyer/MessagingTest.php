<?php

use App\Models\Conversation;
use App\Models\Message;

it('starts a conversation with a seller and stores the first message', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $product = makeProduct($seller);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/messages/conversations', [
        'seller_id' => $seller->id,
        'product_id' => $product->id,
        'body' => 'Hi, is this still available?',
    ])->assertCreated()
        ->assertJsonPath('data.seller', 'Test Storefront')
        ->assertJsonPath('data.messages.0.from', 'buyer')
        ->assertJsonPath('data.messages.0.text', 'Hi, is this still available?');

    expect(Conversation::count())->toBe(1);
    expect(Message::count())->toBe(1);
    expect(Conversation::first()->seller_unread_count)->toBe(1);
    expect(Conversation::first()->participantRecords)->toHaveCount(2);
});

it('reuses the same product thread for the same seller', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $product = makeProduct($seller);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/messages/conversations', [
        'seller_id' => $seller->id,
        'product_id' => $product->id,
        'body' => 'first',
    ]);
    $this->postJson('/api/buyer/messages/conversations', [
        'seller_id' => $seller->id,
        'product_id' => $product->id,
        'body' => 'second',
    ]);

    expect(Conversation::count())->toBe(1);
    expect(Message::count())->toBe(2);
});

it('links a thread to one of the buyer\'s own orders by order number', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    [$order] = makeOrder($buyer, $seller);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/messages/conversations', [
        'seller_id' => $seller->id,
        'order_number' => '#'.$order->order_number,
        'body' => 'About my order',
    ])->assertCreated();

    expect(Conversation::first()->order_id)->toBe($order->id);
});

it("rejects linking a thread to an order that isn't the buyer's", function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    [$order] = makeOrder(makeBuyer(), $seller);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/messages/conversations', [
        'seller_id' => $seller->id,
        'order_number' => $order->order_number,
        'body' => 'hi',
    ])->assertStatus(422);
});

it('marks a thread read and zeroes the unread count', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller, overrides: [
        'buyer_unread_count' => 3,
    ]);
    Message::create([
        'conversation_id' => $conversation->id, 'sender_id' => $seller->id,
        'sender_role' => 'seller', 'body' => 'reply',
    ]);

    actingAsBuyer($buyer);

    $this->getJson('/api/buyer/messages/unread-count')->assertJsonPath('data.count', 3);
    $this->putJson("/api/buyer/messages/conversations/{$conversation->id}/read")->assertOk();
    $this->getJson('/api/buyer/messages/unread-count')->assertJsonPath('data.count', 0);

    expect(Message::first()->read_at)->not->toBeNull();
    expect($conversation->participantRecords()->where('user_id', $buyer->id)->first()->last_read_at)->not->toBeNull();
});

it("never exposes another buyer's conversation", function () {
    $me = makeBuyer();
    $other = makeBuyer();
    $seller = makeSeller();
    $theirThread = makeBuyerSellerConversation($other, $seller);

    actingAsBuyer($me);

    $this->getJson("/api/buyer/messages/conversations/{$theirThread->id}")->assertStatus(404);
    $this->postJson("/api/buyer/messages/conversations/{$theirThread->id}/messages", ['body' => 'sneak'])
        ->assertStatus(404);
    $this->getJson('/api/buyer/messages/conversations')->assertJsonCount(0, 'data');
});
