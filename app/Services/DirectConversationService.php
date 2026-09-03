<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Order;
use App\Models\Product;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DirectConversationService
{
    public function findOrCreateBuyerSeller(
        Profile $buyer,
        string $sellerId,
        ?string $orderNumber,
        ?string $productId,
        ?string $subject,
    ): Conversation {
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

        [$type, $contextId, $order, $product] = $this->resolveBuyerSellerContext(
            $buyer,
            $seller,
            $orderNumber,
            $productId,
        );

        $contextKey = Conversation::makeContextKey($type, $contextId, [$buyer->id, $seller->id]);

        return DB::transaction(function () use ($buyer, $seller, $order, $product, $type, $contextKey, $subject): Conversation {
            $conversation = Conversation::query()->firstOrCreate(
                ['context_key' => $contextKey],
                [
                    'type' => $type,
                    'created_by' => $buyer->id,
                    'buyer_id' => $buyer->id,
                    'seller_id' => $seller->id,
                    'order_id' => $order?->id,
                    'product_id' => $product?->id,
                    'subject' => $subject,
                    'status' => 'open',
                ],
            );

            foreach ([$buyer->id, $seller->id] as $participantId) {
                ConversationParticipant::query()->firstOrCreate(
                    [
                        'conversation_id' => $conversation->id,
                        'user_id' => $participantId,
                    ],
                    ['joined_at' => now()],
                );
            }

            return $conversation;
        });
    }

    /**
     * @return array{0: 'order'|'product', 1: string, 2: Order|null, 3: Product|null}
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

        if ($order) {
            return ['order', $order->id, $order, $product];
        }

        if ($product) {
            return ['product', $product->id, null, $product];
        }

        throw ValidationException::withMessages([
            'context' => 'Choose a product or one of your seller-specific orders before starting a conversation.',
        ]);
    }
}
