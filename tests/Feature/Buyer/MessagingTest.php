<?php

use App\Models\Conversation;
use App\Models\Message;

it('starts a conversation with a seller and stores the first message', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/messages/conversations', [
        'seller_id' => $seller->id,
        'body' => 'Hi, is this still available?',
    ])->assertCreated()
        ->assertJsonPath('data.seller', 'Test Storefront')
        ->assertJsonPath('data.messages.0.from', 'buyer')
        ->assertJsonPath('data.messages.0.text', 'Hi, is this still available?');

    expect(Conversation::count())->toBe(1);
    expect(Message::count())->toBe(1);
    expect(Conversation::first()->seller_unread_count)->toBe(1);
});

it('reuses the same general thread for the same seller', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/messages/conversations', ['seller_id' => $seller->id, 'body' => 'first']);
    $this->postJson('/api/buyer/messages/conversations', ['seller_id' => $seller->id, 'body' => 'second']);

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
    $conversation = Conversation::create([
        'buyer_id' => $buyer->id, 'seller_id' => $seller->id, 'status' => 'open',
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
});

it("never exposes another buyer's conversation", function () {
    $me = makeBuyer();
    $other = makeBuyer();
    $seller = makeSeller();
    $theirThread = Conversation::create([
        'buyer_id' => $other->id, 'seller_id' => $seller->id, 'status' => 'open',
    ]);

    actingAsBuyer($me);

    $this->getJson("/api/buyer/messages/conversations/{$theirThread->id}")->assertStatus(404);
    $this->postJson("/api/buyer/messages/conversations/{$theirThread->id}/messages", ['body' => 'sneak'])
        ->assertStatus(404);
    $this->getJson('/api/buyer/messages/conversations')->assertJsonCount(0, 'data');
});
