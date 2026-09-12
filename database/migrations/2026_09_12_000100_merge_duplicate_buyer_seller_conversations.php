<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Buyer <-> seller conversations were keyed by (buyer_id, seller_id,
 * order_id-or-product_id) via `context_key` — see
 * 2026_08_29_000005_create_conversations_table.php's own docblock, which
 * documents this as intentional: "a buyer messaging the same seller about a
 * different order gets a distinct thread". In practice that means every
 * order/product a buyer asks a seller about opens a brand new thread,
 * instead of the buyer<->seller relationship having one continuous
 * conversation the way marketplace messaging normally works.
 *
 * This migration:
 *   1. Backfills the new messages.order_id/product_id (previous migration)
 *      from each message's conversation — until now a conversation only
 *      ever had one order/product for its whole life, so this is a lossless
 *      copy of context down to the message level.
 *   2. Merges every group of conversations sharing the same (buyer_id,
 *      seller_id) pair (type 'product' or 'order' — the direct buyer<->
 *      seller ones; 'shipment' and 'support' conversations are untouched)
 *      into the oldest conversation in the group, moving messages and
 *      participants over and summing unread counts.
 *   3. Relabels every surviving buyer<->seller conversation to type
 *      'direct' with a context_key keyed on (buyer_id, seller_id) only, so
 *      DirectConversationService's firstOrCreate() naturally reuses it
 *      going forward regardless of which order/product started it.
 *
 * `type` has no DB check constraint (only `status` does — see the unified-
 * fields migration), so introducing 'direct' is safe, and every existing
 * `$conversation->type` check in the app is exclusion-based (!== 'support',
 * !== 'shipment'), so it passes through them all untouched.
 *
 * down() is intentionally a no-op: once distinct conversations are merged
 * into one, the original split can't be reconstructed from the merged
 * state — there is no lossless reverse of this operation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $this->backfillMessageContext();
            $this->mergeDuplicateConversations();
            $this->relabelToDirectIdentity();
        });
    }

    private function backfillMessageContext(): void
    {
        DB::table('conversations')
            ->whereIn('type', ['product', 'order'])
            ->where(fn ($q) => $q->whereNotNull('order_id')->orWhereNotNull('product_id'))
            ->orderBy('id')
            ->chunkById(200, function ($conversations) {
                foreach ($conversations as $conversation) {
                    DB::table('messages')
                        ->where('conversation_id', $conversation->id)
                        ->update([
                            'order_id' => $conversation->order_id,
                            'product_id' => $conversation->product_id,
                        ]);
                }
            });
    }

    private function mergeDuplicateConversations(): void
    {
        $groups = DB::table('conversations')
            ->whereIn('type', ['product', 'order'])
            ->whereNotNull('buyer_id')
            ->whereNotNull('seller_id')
            ->orderBy('created_at')
            ->get([
                'id', 'buyer_id', 'seller_id', 'created_at',
                'last_message_at', 'last_message_preview', 'last_message_sender_role',
                'buyer_unread_count', 'seller_unread_count',
            ])
            ->groupBy(fn ($c) => $c->buyer_id.':'.$c->seller_id);

        foreach ($groups as $rows) {
            $rows = $rows->values();

            if ($rows->count() < 2) {
                continue;
            }

            // Oldest survives as the canonical thread — least disruptive to
            // whatever the buyer/seller already have open or bookmarked.
            $canonical = $rows->first();
            $duplicates = $rows->slice(1);

            $latestAt = $canonical->last_message_at;
            $latestPreview = $canonical->last_message_preview;
            $latestSenderRole = $canonical->last_message_sender_role;
            $buyerUnread = (int) $canonical->buyer_unread_count;
            $sellerUnread = (int) $canonical->seller_unread_count;

            foreach ($duplicates as $duplicate) {
                DB::table('messages')->where('conversation_id', $duplicate->id)->update(['conversation_id' => $canonical->id]);

                // conversation_participants has unique(conversation_id, user_id) —
                // the buyer/seller pair is already a participant on the
                // canonical row, so drop the duplicate's rows for them
                // before repointing anything else onto the canonical id.
                $existingUserIds = DB::table('conversation_participants')
                    ->where('conversation_id', $canonical->id)
                    ->pluck('user_id');

                DB::table('conversation_participants')
                    ->where('conversation_id', $duplicate->id)
                    ->whereIn('user_id', $existingUserIds)
                    ->delete();

                DB::table('conversation_participants')
                    ->where('conversation_id', $duplicate->id)
                    ->update(['conversation_id' => $canonical->id]);

                $buyerUnread += (int) $duplicate->buyer_unread_count;
                $sellerUnread += (int) $duplicate->seller_unread_count;

                if ($duplicate->last_message_at !== null
                    && ($latestAt === null || $duplicate->last_message_at > $latestAt)) {
                    $latestAt = $duplicate->last_message_at;
                    $latestPreview = $duplicate->last_message_preview;
                    $latestSenderRole = $duplicate->last_message_sender_role;
                }

                DB::table('conversations')->where('id', $duplicate->id)->delete();
            }

            DB::table('conversations')->where('id', $canonical->id)->update([
                'last_message_at' => $latestAt,
                'last_message_preview' => $latestPreview,
                'last_message_sender_role' => $latestSenderRole,
                'buyer_unread_count' => $buyerUnread,
                'seller_unread_count' => $sellerUnread,
            ]);
        }
    }

    private function relabelToDirectIdentity(): void
    {
        DB::table('conversations')
            ->whereIn('type', ['product', 'order'])
            ->orderBy('id')
            ->chunkById(200, function ($conversations) {
                foreach ($conversations as $conversation) {
                    DB::table('conversations')->where('id', $conversation->id)->update([
                        'type' => 'direct',
                        'context_key' => self::directContextKey($conversation->buyer_id, $conversation->seller_id),
                    ]);
                }
            });
    }

    /**
     * Mirrors App\Models\Conversation::makeContextKey('direct', 'buyer-seller', [...])
     * exactly (format must match what the application computes going
     * forward, or DirectConversationService's firstOrCreate() would miss
     * this row and create a fresh duplicate) — inlined rather than calling
     * the model, matching this schema's existing convention of not
     * depending on app model code inside migrations (see the unified-
     * fields migration, which does the same for the pre-existing key).
     */
    private static function directContextKey(string $buyerId, string $sellerId): string
    {
        $ids = [$buyerId, $sellerId];
        sort($ids);

        return hash('sha256', 'direct:buyer-seller:'.implode(':', $ids));
    }

    public function down(): void
    {
        // Not reversible — see class docblock.
    }
};
