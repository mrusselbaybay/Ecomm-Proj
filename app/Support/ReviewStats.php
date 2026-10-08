<?php

namespace App\Support;

use App\Models\Review;

/**
 * A product's public rating and review count, computed from exactly the
 * rows every product card, product page and store aggregate counts
 * (Review::scopeEligible). Returned with each review a buyer writes, edits
 * or deletes, so the page that made the change — and every in-memory copy
 * of the product on the client — can show the new numbers immediately.
 */
class ReviewStats
{
    /**
     * @return array{productId: string, rating: float|null, reviewCount: int}
     */
    public static function forProduct(string $productId): array
    {
        $row = Review::query()
            ->eligible()
            ->where('reviews.product_id', $productId)
            ->toBase()
            ->selectRaw('count(*) as total, round(avg(reviews.rating), 1) as average')
            ->first();

        $count = (int) ($row->total ?? 0);

        return [
            'productId' => $productId,
            // No reviews => null, never a zero-star average.
            'rating' => $count > 0 && $row->average !== null ? (float) $row->average : null,
            'reviewCount' => $count,
        ];
    }
}
