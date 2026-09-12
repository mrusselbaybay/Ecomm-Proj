<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Directly exercises 2026_09_12_000100_merge_duplicate_buyer_seller_conversations's
 * up() logic — RefreshDatabase already runs every migration (including this
 * one) against a schema with no data yet, so it never actually merges
 * anything in the normal test bootstrap. This test manually recreates the
 * pre-migration duplicate state (old (buyer,seller,order/product)-keyed
 * conversations) the migration is meant to fix, then re-runs its up()
 * method against that data to verify the merge itself — not just that new
 * conversations dedupe correctly (see Buyer\MessagingTest for that).
 */
it('merges pre-existing duplicate buyer-seller conversations into one thread', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $productA = makeProduct($seller);
    $productB = makeProduct($seller);
    [$order] = makeOrder($buyer, $seller);

    $oldConversation = fn (string $type, ?string $orderId, ?string $productId, string $createdAt, array $overrides = []) => [
        'id' => (string) Str::uuid(),
        'type' => $type,
        'created_by' => $buyer->id,
        'context_key' => hash('sha256', "{$type}:".($orderId ?? $productId).":".implode(':', collect([$buyer->id, $seller->id])->sort()->all())),
        'buyer_id' => $buyer->id,
        'seller_id' => $seller->id,
        'order_id' => $orderId,
        'product_id' => $productId,
        'status' => 'open',
        'last_message_preview' => $overrides['last_message_preview'] ?? null,
        'last_message_sender_role' => $overrides['last_message_sender_role'] ?? null,
        'last_message_at' => $overrides['last_message_at'] ?? null,
        'buyer_unread_count' => $overrides['buyer_unread_count'] ?? 0,
        'seller_unread_count' => $overrides['seller_unread_count'] ?? 0,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ];

    $oldest = $oldConversation('product', null, $productA->id, '2026-01-01 00:00:00', [
        'last_message_preview' => 'About product A', 'last_message_sender_role' => 'buyer',
        'last_message_at' => '2026-01-01 00:00:00', 'seller_unread_count' => 1,
    ]);
    $middle = $oldConversation('product', null, $productB->id, '2026-01-02 00:00:00', [
        'last_message_preview' => 'About product B', 'last_message_sender_role' => 'buyer',
        'last_message_at' => '2026-01-02 00:00:00', 'seller_unread_count' => 1,
    ]);
    $newest = $oldConversation('order', $order->id, null, '2026-01-03 00:00:00', [
        'last_message_preview' => 'About my order', 'last_message_sender_role' => 'seller',
        'last_message_at' => '2026-01-03 00:00:00', 'buyer_unread_count' => 1,
    ]);

    DB::table('conversations')->insert([$oldest, $middle, $newest]);

    foreach ([$oldest, $middle, $newest] as $conversation) {
        foreach ([$buyer->id, $seller->id] as $participantId) {
            ConversationParticipant::create([
                'conversation_id' => $conversation['id'],
                'user_id' => $participantId,
                'joined_at' => now(),
            ]);
        }

        Message::create([
            'conversation_id' => $conversation['id'],
            'sender_id' => $conversation['last_message_sender_role'] === 'seller' ? $seller->id : $buyer->id,
            'sender_role' => $conversation['last_message_sender_role'],
            'body' => $conversation['last_message_preview'],
        ]);
    }

    $migration = require base_path('database/migrations/2026_09_12_000100_merge_duplicate_buyer_seller_conversations.php');
    $migration->up();

    // Merged down to the oldest conversation; the two duplicates are gone.
    expect(Conversation::count())->toBe(1);

    $canonical = Conversation::sole();
    expect($canonical->id)->toBe($oldest['id']);
    expect($canonical->type)->toBe('direct');
    expect($canonical->context_key)->not->toBe($oldest['context_key']);

    // All three messages now live on the surviving conversation, each still
    // carrying which order/product it was actually about.
    expect(Message::count())->toBe(3);
    expect(Message::where('conversation_id', $canonical->id)->count())->toBe(3);
    expect(Message::where('product_id', $productA->id)->exists())->toBeTrue();
    expect(Message::where('product_id', $productB->id)->exists())->toBeTrue();
    expect(Message::where('order_id', $order->id)->exists())->toBeTrue();

    // Unread counts summed and last_message_* pulled from the most recent
    // of the merged rows (the order-context one, created last).
    expect($canonical->buyer_unread_count)->toBe(1);
    expect($canonical->seller_unread_count)->toBe(2);
    expect($canonical->last_message_preview)->toBe('About my order');

    // No leftover duplicate participant rows for the buyer/seller pair.
    expect(ConversationParticipant::where('conversation_id', $canonical->id)->count())->toBe(2);

    // A brand-new message to this same seller now resolves to the SAME
    // merged conversation rather than creating a fourth one — confirms the
    // relabeled context_key actually matches what the app computes going
    // forward (App\Services\DirectConversationService).
    actingAsBuyer($buyer);
    $this->postJson('/api/buyer/messages/conversations', [
        'seller_id' => $seller->id,
        'body' => 'One more thing',
    ])->assertCreated();

    expect(Conversation::count())->toBe(1);
    expect(Message::count())->toBe(4);
});
