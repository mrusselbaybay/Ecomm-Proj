<?php

use App\Models\Conversation;

/**
 * The bug this locks in: archiving used to write a single shared
 * Conversation.status = 'archived', so one side archiving a thread also
 * hid/blocked it for the OTHER side. Archiving is now per-participant
 * (Conversation::archiveFor()/isArchivedFor(), mirroring leaveFor()'s
 * left_at pattern on a separate archived_at column) — these tests exercise
 * the exact reported scenario: "seller archives buyer, buyer's inbox
 * shows the seller archived too."
 */
it('does not hide a conversation for the buyer when the seller archives it', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller);

    actingAsSeller($seller);
    $this->putJson("/api/seller/messages/conversations/{$conversation->id}/status", ['status' => 'archived'])
        ->assertOk()
        ->assertJsonPath('data.archived', true);

    // The shared column is untouched.
    expect(Conversation::find($conversation->id)->status)->toBe('open');

    // The buyer's own inbox is completely unaffected: still visible, still
    // not archived from the buyer's point of view.
    actingAsBuyer($buyer);
    $this->getJson('/api/buyer/messages/conversations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.archived', false);
    $this->getJson("/api/buyer/messages/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJsonPath('data.archived', false);

    // The seller's own inbox still has it archived (checked BEFORE the
    // buyer replies below — any new message legitimately auto-revives an
    // archived thread for whoever archived it, same as leaveFor()/delete,
    // so asserting "still archived" has to happen first).
    actingAsSeller($seller);
    $this->getJson('/api/seller/messages/conversations?status=archived')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $conversation->id);

    // And it's still fully writable for the buyer despite being archived
    // on the seller's side.
    actingAsBuyer($buyer);
    $this->postJson("/api/buyer/messages/conversations/{$conversation->id}/messages", ['body' => 'still able to reply'])
        ->assertCreated();
});

it('does not hide a conversation for the seller when the buyer archives it', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller);

    actingAsBuyer($buyer);
    $this->putJson("/api/buyer/messages/conversations/{$conversation->id}/status", ['status' => 'archived'])
        ->assertOk()
        ->assertJsonPath('data.archived', true);

    expect(Conversation::find($conversation->id)->status)->toBe('open');

    actingAsSeller($seller);
    $this->getJson('/api/seller/messages/conversations')
        ->assertOk()
        ->assertJsonPath('data.0.archived', false);

    // Checked BEFORE sending — a new message legitimately auto-revives an
    // archived thread for whoever archived it (same as leaveFor()/delete).
    actingAsBuyer($buyer);
    $this->getJson('/api/buyer/messages/conversations?status=archived')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    actingAsSeller($seller);
    $this->postJson("/api/seller/messages/conversations/{$conversation->id}/messages", ['body' => 'still able to reply'])
        ->assertCreated();
});
