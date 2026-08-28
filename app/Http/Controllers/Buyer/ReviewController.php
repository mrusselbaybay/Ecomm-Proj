<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Buyer-facing review management for the "My Reviews" page. Separate from
 * anything seller-side (SellerFeedbackController, RespondToReviewRequest
 * on the other branch — those handle a seller *responding* to a review;
 * this handles the buyer who *wrote* one).
 *
 * Replaces useBuyer.js's submitReview(), which was a local-only stub —
 * see that file's previous comment: "there is no reviews table/endpoint
 * yet." The table already existed on the real database; this was the
 * missing endpoint, in the same vein as Order.php/OrderStatusHistory.php
 * earlier — referenced/assumed but never wired up.
 */
class ReviewController extends Controller
{
    /**
     * GET /api/buyer/reviews
     */
    public function index(Request $request): JsonResponse
    {
        $buyer = $request->user();

        $reviews = Review::with('product')
            ->where('buyer_id', $buyer->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $reviews->map(fn (Review $review) => $this->transform($review)),
        ]);
    }

    /**
     * POST /api/buyer/reviews
     *
     * A review can only be written for an order_item that:
     *   - belongs to one of this buyer's own orders (not just any order),
     *   - is on an order that's actually Delivered (matches
     *     OrderDetails.vue's canReviewOrder computed on the frontend —
     *     enforced again here since the frontend check is only a UX
     *     nicety, not something a request can be trusted to have honored),
     *   - doesn't already have a review (reviews.order_item_id is UNIQUE;
     *     checked here first for a clean 422 instead of surfacing a raw
     *     DB constraint violation as a 500).
     */
    public function store(Request $request): JsonResponse
    {
        $buyer = $request->user();

        $data = $request->validate([
            'order_item_id' => 'required|uuid',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        $orderItem = OrderItem::with(['order', 'product'])
            ->where('id', $data['order_item_id'])
            ->first();

        if (!$orderItem || $orderItem->order?->buyer_profile_id !== $buyer->id) {
            throw ValidationException::withMessages([
                'order_item_id' => 'This order item was not found on one of your orders.',
            ]);
        }

        if ($orderItem->order->status !== 'Delivered') {
            throw ValidationException::withMessages([
                'order_item_id' => 'You can only review items from delivered orders.',
            ]);
        }

        if (Review::where('order_item_id', $orderItem->id)->exists()) {
            throw ValidationException::withMessages([
                'order_item_id' => 'This item has already been reviewed.',
            ]);
        }

        $timestamp = now();

        $review = Review::create([
            'product_id' => $orderItem->product_id,
            'seller_id' => $orderItem->order->seller_id,
            'buyer_id' => $buyer->id,
            'order_item_id' => $orderItem->id,
            'product_name' => $orderItem->product_name,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return response()->json(['data' => $this->transform($review)], 201);
    }

    /**
     * PUT /api/buyer/reviews/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $buyer = $request->user();

        $review = Review::where('id', $id)->where('buyer_id', $buyer->id)->first();

        if (!$review) {
            return response()->json(['message' => 'Review not found.'], 404);
        }

        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        $review->update([
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
            'updated_at' => now(),
        ]);

        return response()->json(['data' => $this->transform($review)]);
    }

    /**
     * DELETE /api/buyer/reviews/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $buyer = $request->user();

        $review = Review::where('id', $id)->where('buyer_id', $buyer->id)->first();

        if (!$review) {
            return response()->json(['message' => 'Review not found.'], 404);
        }

        $review->delete();

        return response()->json(['message' => 'Review deleted.']);
    }

    private function transform(Review $review): array
    {
        $product = $review->product;

        return [
            'id' => $review->id,
            'productId' => $review->product_id,
            'productName' => $review->product_name ?? $product?->name ?? 'Product no longer available',
            // Real category/image only if the product still exists —
            // reviews.product_name is a snapshot, but category/images
            // aren't duplicated onto the review row, so a deleted product
            // genuinely has neither to show.
            'category' => $product?->category,
            'image' => is_array($product?->images) && count($product->images) > 0 ? $product->images[0] : null,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'createdAt' => optional($review->created_at)->toIso8601String(),
            'updatedAt' => optional($review->updated_at)->toIso8601String(),
            'isEdited' => $review->updated_at && $review->created_at
                && !$review->updated_at->equalTo($review->created_at),
            'sellerResponse' => $review->seller_response,
            'respondedAt' => optional($review->responded_at)->toIso8601String(),
        ];
    }
}