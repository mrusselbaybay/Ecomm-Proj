<?php

namespace App\Support;

use App\Models\Review;

/**
 * One public review row, shared by a product's reviews
 * (ProductController::reviews) and a store's product reviews
 * (StoreController::reviews).
 *
 * The reviewer is shown as "First L." only, never the full name or any
 * contact detail. verifiedPurchase is a real signal: Buyer\ReviewController
 * only ever creates a review with an order_item_id, and only for a
 * delivered order the buyer owns, so a non-null order_item_id genuinely
 * means "bought and received".
 */
class PublicReview
{
    /**
     * @return array{id: string, author: string, rating: int, comment: string|null, createdAt: string|null, isEdited: bool, variant: string|null, verifiedPurchase: bool, images: list<string>, sellerResponse: string|null, respondedAt: string|null}
     */
    public static function transform(Review $review): array
    {
        $first = trim((string) ($review->buyer?->first_name ?? ''));
        $last = trim((string) ($review->buyer?->last_name ?? ''));
        $author = trim($first.' '.($last !== '' ? mb_substr($last, 0, 1).'.' : ''));

        $images = collect(is_array($review->images) ? $review->images : [])
            ->filter(fn ($img) => is_string($img) && $img !== '')
            ->values()
            ->all();

        return [
            'id' => $review->id,
            'author' => $author !== '' ? $author : 'BuyTheWay Buyer',
            'rating' => (int) $review->rating,
            'comment' => $review->comment,
            'createdAt' => optional($review->created_at)->toIso8601String(),
            'isEdited' => (bool) ($review->updated_at && $review->created_at
                && ! $review->updated_at->equalTo($review->created_at)),
            'variant' => $review->orderItem?->variant,
            'verifiedPurchase' => ! is_null($review->order_item_id),
            'images' => $images,
            'sellerResponse' => $review->seller_response,
            'respondedAt' => optional($review->responded_at)->toIso8601String(),
        ];
    }
}
