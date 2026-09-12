<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Order;
use App\Models\Product;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class DirectConversationService
{
    /**
     * Finds (or creates) the ONE conversation between this buyer and
     * seller — identity is (buyer_id, seller_id) only, never the order or
     * product that happened to prompt this particular "Message Seller"
     * click. order_number/product_id, when given, are resolved and handed
     * back so the caller can attach them to the new message being sent
     * into this thread (see Buyer\MessageController::appendMessage()) —
     * that's what lets one continuous thread carry inquiry context for
     * several different purchases instead of forking into a new thread
     * per order/product.
     *
     * @return array{conversation: Conversation, order: Order|null, product: Product|null}
     */
    public function findOrCreateBuyerSeller(
        Profile $buyer,
        string $sellerId,
        ?string $orderNumber,
        ?string $productId,
        ?string $subject,
    ): array {
        $seller = Profile::query()
            ->whereKey($sellerId)
            ->where('role', 'seller')
            ->where('status', 'approved')
            ->where('account_status', 'active')
            ->first();

        if (! $seller) {
            throw ValidationException::withMessages([
                'seller_id' => 'That seller is not available.',
            ]);
        }

        if ($buyer->id === $seller->id) {
            throw ValidationException::withMessages([
                'seller_id' => 'You cannot message your own account.',
            ]);
        }

        [$order, $product] = $this->resolveBuyerSellerContext(
            $buyer,
            $seller,
            $orderNumber,
            $productId,
        );

        $contextKey = Conversation::makeContextKey('direct', 'buyer-seller', [$buyer->id, $seller->id]);

        return DB::transaction(function () use ($buyer, $seller, $order, $product, $contextKey, $subject): array {
            $conversation = Conversation::query()->firstOrCreate(
                ['context_key' => $contextKey],
                [
                    'type' => 'direct',
                    'created_by' => $buyer->id,
                    'buyer_id' => $buyer->id,
                    'seller_id' => $seller->id,
                    'order_id' => $order?->id,
                    'product_id' => $product?->id,
                    'subject' => $subject,
                    'status' => 'open',
                ],
            );

            // The conversation's own order_id/product_id are just a
            // lightweight "most recently referenced purchase" pointer for
            // display (e.g. the seller's header chip) — keep it current
            // even when the thread already existed. Source of truth for
            // "what was THIS inquiry about" is the message itself.
            if (($order || $product) && ! $conversation->wasRecentlyCreated) {
                $conversation->forceFill([
                    'order_id' => $order?->id ?? $conversation->order_id,
                    'product_id' => $product?->id ?? $conversation->product_id,
                ])->save();
            }

            foreach ([$buyer->id, $seller->id] as $participantId) {
                $participant = ConversationParticipant::query()->firstOrCreate(
                    [
                        'conversation_id' => $conversation->id,
                        'user_id' => $participantId,
                    ],
                    ['joined_at' => now()],
                );

                // Whoever previously "deleted" this thread (left_at set —
                // see Conversation::leaveFor()) rejoins it the moment either
                // side messages the other again, rather than it staying
                // permanently hidden from their list.
                if ($participant->left_at !== null) {
                    $participant->update(['left_at' => null, 'joined_at' => now()]);
                }
            }

            return ['conversation' => $conversation, 'order' => $order, 'product' => $product];
        });
    }

    /**
     * Auto-starts (or reuses) the ONE buyer<->seller thread for a freshly
     * placed order, and drops a system message announcing it — so the
     * conversation exists and is actually useful (non-empty, sorts to the
     * top of both inboxes) the moment checkout succeeds, without either
     * side having to click "Message Seller" first.
     *
     * Reuses the exact same (buyer_id, seller_id) context_key identity as
     * findOrCreateBuyerSeller(), so this never creates a second thread for
     * a seller the buyer already has one with — including when a single
     * checkout's multiple items for that seller collapsed into one Order
     * (CheckoutService groups by seller) or across separate orders placed
     * on different days.
     *
     * Called from Buyer\CheckoutController::store() AFTER CheckoutService's
     * transaction has committed (same "notify only once the purchase is
     * durable" rule SellerNotifier follows) — so any failure here must
     * never bubble up and taint an already-placed order. Swallows and
     * reports instead of throwing.
     */
    public function startForOrder(Order $order): void
    {
        try {
            DB::transaction(function () use ($order): void {
                $contextKey = Conversation::makeContextKey(
                    'direct',
                    'buyer-seller',
                    [$order->buyer_profile_id, $order->seller_id],
                );

                $conversation = Conversation::query()->firstOrCreate(
                    ['context_key' => $contextKey],
                    [
                        'type' => 'direct',
                        'created_by' => $order->buyer_profile_id,
                        'buyer_id' => $order->buyer_profile_id,
                        'seller_id' => $order->seller_id,
                        'order_id' => $order->id,
                        'status' => 'open',
                    ],
                );

                if (! $conversation->wasRecentlyCreated) {
                    $conversation->forceFill(['order_id' => $order->id])->save();
                }

                foreach ([$order->buyer_profile_id, $order->seller_id] as $participantId) {
                    $participant = ConversationParticipant::query()->firstOrCreate(
                        ['conversation_id' => $conversation->id, 'user_id' => $participantId],
                        ['joined_at' => now()],
                    );

                    if ($participant->left_at !== null) {
                        $participant->update(['left_at' => null, 'joined_at' => now()]);
                    }
                }

                // Kept short — item count/total are already carried
                // structurally on the message's order_id (see
                // Buyer\MessageController::orderPreview()) and rendered as
                // fields in the chat's order card, so this text never needs
                // to spell them out as a sentence too.
                $preview = "Order #{$order->order_number} placed";

                $message = $conversation->messages()->create([
                    'sender_id' => $order->buyer_profile_id,
                    'sender_role' => 'system',
                    'message_type' => 'text',
                    'body' => $preview,
                    'order_id' => $order->id,
                ]);

                $conversation->forceFill([
                    'last_message_at' => $message->created_at,
                    'last_message_preview' => $preview,
                    'last_message_sender_role' => 'system',
                ]);
                $conversation->seller_unread_count = $conversation->seller_unread_count + 1;
                $conversation->save();
                $conversation->reviveLeftParticipants();
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Both are optional — a buyer messaging a seller from the seller's
     * page (no order/product involved) is a valid, contextless "general"
     * conversation, not an error.
     *
     * @return array{0: Order|null, 1: Product|null}
     */
    private function resolveBuyerSellerContext(
        Profile $buyer,
        Profile $seller,
        ?string $orderNumber,
        ?string $productId,
    ): array {
        $order = null;
        $product = null;

        if ($orderNumber) {
            $order = Order::query()
                ->where('order_number', ltrim($orderNumber, '#'))
                ->where('buyer_profile_id', $buyer->id)
                ->where('seller_id', $seller->id)
                ->first();

            if (! $order) {
                throw ValidationException::withMessages([
                    'order_number' => 'That seller-specific order was not found on your account.',
                ]);
            }
        }

        if ($productId) {
            $product = Product::query()
                ->whereKey($productId)
                ->where('seller_id', $seller->id)
                ->first();

            if (! $product) {
                throw ValidationException::withMessages([
                    'product_id' => 'That product does not belong to this seller.',
                ]);
            }
        }

        return [$order, $product];
    }
}
