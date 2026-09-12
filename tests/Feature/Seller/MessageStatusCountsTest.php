<?php

it('computes the tab status counts in one aggregate query, matching the old per-status counts', function () {
    $seller = makeSeller();

    $buyerA = makeBuyer();
    $buyerB = makeBuyer();
    $buyerC = makeBuyer();
    $buyerD = makeBuyer();

    // open, unread, needs a response (last message from the buyer)
    makeBuyerSellerConversation($buyerA, $seller, overrides: [
        'status' => 'open',
        'seller_unread_count' => 2,
        'last_message_sender_role' => 'buyer',
    ]);

    // open but the seller already replied last — not "needs response"
    makeBuyerSellerConversation($buyerB, $seller, overrides: [
        'status' => 'open',
        'seller_unread_count' => 0,
        'last_message_sender_role' => 'seller',
    ]);

    makeBuyerSellerConversation($buyerC, $seller, overrides: ['status' => 'resolved']);
    // Archiving is per-participant now (Conversation::archiveFor()) rather
    // than the shared `status` column — archive it for the seller directly.
    makeBuyerSellerConversation($buyerD, $seller)->archiveFor($seller->id);

    actingAsSeller($seller);

    $this->getJson('/api/seller/messages/conversations')
        ->assertOk()
        ->assertJsonPath('meta.statusCounts.all', 4)
        ->assertJsonPath('meta.statusCounts.unread', 1)
        ->assertJsonPath('meta.statusCounts.needsResponse', 1)
        ->assertJsonPath('meta.statusCounts.resolved', 1)
        ->assertJsonPath('meta.statusCounts.archived', 1);
});
