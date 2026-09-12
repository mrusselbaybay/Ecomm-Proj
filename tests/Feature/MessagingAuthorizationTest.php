<?php

use App\Models\Message;

it('requires authentication for the buyer conversation inbox', function () {
    $this->getJson('/api/buyer/messages/conversations')->assertUnauthorized();
});

it('allows starting a direct conversation with no product or order context', function () {
    // A buyer messaging a seller in general (e.g. from the seller's page,
    // not about a specific purchase) is valid — order/product are optional
    // context, not a requirement to open a thread. See also
    // Buyer\MessagingTest's "allows messaging a seller with no order or
    // product context" for the corresponding happy-path coverage.
    $buyer = makeBuyer();
    $seller = makeSeller();

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/messages/conversations', [
        'seller_id' => $seller->id,
        'body' => 'Hello',
    ])->assertCreated();
});

it('does not allow a buyer to directly message an admin account', function () {
    $buyer = makeBuyer();
    $admin = makeAdmin();
    $seller = makeSeller();
    $product = makeProduct($seller);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/messages/conversations', [
        'seller_id' => $admin->id,
        'product_id' => $product->id,
        'body' => 'I need platform help',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['seller_id']);
});

it('derives the sender from authentication instead of trusting request data', function () {
    $buyer = makeBuyer();
    $otherBuyer = makeBuyer();
    $seller = makeSeller();
    $product = makeProduct($seller);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/messages/conversations', [
        'seller_id' => $seller->id,
        'product_id' => $product->id,
        'sender_id' => $otherBuyer->id,
        'body' => 'Authenticated sender only',
    ])->assertCreated();

    expect(Message::first()->sender_id)->toBe($buyer->id);
});

it('prevents an unrelated seller from viewing or replying to a conversation', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $otherSeller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller);

    actingAsSeller($otherSeller);

    $this->getJson("/api/seller/messages/conversations/{$conversation->id}")->assertNotFound();
    $this->postJson("/api/seller/messages/conversations/{$conversation->id}/messages", [
        'body' => 'Unauthorized reply',
    ])->assertNotFound();
});

it('rejects new messages after a direct conversation is closed', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller, overrides: ['status' => 'closed']);

    actingAsBuyer($buyer);

    $this->postJson("/api/buyer/messages/conversations/{$conversation->id}/messages", [
        'body' => 'This should not send',
    ])->assertUnprocessable();

    expect(Message::count())->toBe(0);
});

it('rejects seller replies after a direct conversation is closed', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller, overrides: ['status' => 'closed']);

    actingAsSeller($seller);

    $this->postJson("/api/seller/messages/conversations/{$conversation->id}/messages", [
        'body' => 'This should not send either',
    ])->assertUnprocessable();

    expect(Message::count())->toBe(0);
});
