<?php

use App\Models\Conversation;

it('lets the buyer archive and unarchive a conversation', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller);

    actingAsBuyer($buyer);

    $this->putJson("/api/buyer/messages/conversations/{$conversation->id}/status", ['status' => 'archived'])
        ->assertOk()
        ->assertJsonPath('data.archived', true)
        // Archiving is per-participant, not the shared status column.
        ->assertJsonPath('data.status', 'open');

    expect(Conversation::find($conversation->id)->status)->toBe('open');

    // Archived threads drop out of the default inbox view...
    $this->getJson('/api/buyer/messages/conversations')->assertJsonCount(0, 'data');
    // ...but are listed under the archived view, and counted in meta.
    $this->getJson('/api/buyer/messages/conversations?status=archived')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $conversation->id)
        ->assertJsonPath('data.0.archived', true);

    $this->putJson("/api/buyer/messages/conversations/{$conversation->id}/status", ['status' => 'open'])
        ->assertOk()
        ->assertJsonPath('data.archived', false);

    $this->getJson('/api/buyer/messages/conversations')->assertJsonCount(1, 'data');
});

it('rejects an invalid status transition for the buyer', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller, overrides: ['status' => 'resolved']);

    actingAsBuyer($buyer);

    // resolved -> resolved (a no-op status) is not in the transition map.
    $this->putJson("/api/buyer/messages/conversations/{$conversation->id}/status", ['status' => 'resolved'])
        ->assertStatus(422);
});

it('does not let a buyer change another buyer\'s conversation status', function () {
    $buyer = makeBuyer();
    $otherBuyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($otherBuyer, $seller);

    actingAsBuyer($buyer);

    $this->putJson("/api/buyer/messages/conversations/{$conversation->id}/status", ['status' => 'archived'])
        ->assertStatus(404);
});
