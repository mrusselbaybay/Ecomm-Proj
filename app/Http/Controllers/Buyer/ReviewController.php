<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Review;
use App\Services\SupabaseStorageService;
use App\Support\ProductImage;
use App\Support\ReviewStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
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
     * Review photos: real files in a public Storage bucket, only their URLs
     * in reviews.images (never base64 in the row — see
     * SupabaseStorageService). Public product reviews already render
     * reviews.images (PublicReview).
     */
    public const PHOTO_BUCKET = 'review-photos';

    public const MAX_PHOTOS = 3;

    public const MAX_PHOTO_KILOBYTES = 5120;

    public function __construct(private SupabaseStorageService $storage) {}

    /**
     * GET /api/buyer/reviews
     */
    public function index(Request $request): JsonResponse
    {
        $buyer = $request->user();

        $reviews = Review::with(['product' => $this->productColumns()])
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
     *
     * JSON, or multipart with images[] (up to MAX_PHOTOS JPEG / PNG / WebP
     * photos). The review is tied to the order item, so to its product,
     * variant (order_items.variant) and buyer.
     */
    public function store(Request $request): JsonResponse
    {
        $buyer = $request->user();

        $data = $request->validate([
            'order_item_id' => 'required|uuid',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
            ...$this->photoRules(),
        ], $this->photoMessages());

        $orderItem = OrderItem::with(['order', 'product'])
            ->where('id', $data['order_item_id'])
            ->first();

        if (! $orderItem || $orderItem->order?->buyer_profile_id !== $buyer->id) {
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

        $photos = $this->uploadPhotos($buyer->id, $request->file('images', []));

        if ($photos === null) {
            return $this->uploadFailed();
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
            'images' => $photos,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return response()->json(['data' => $this->transformWithStats($review)], 201);
    }

    /**
     * PUT /api/buyer/reviews/{id}  (multipart: POST with _method=PUT)
     *
     * Only the buyer's own review. keep_images[] lists the current photos
     * to keep (anything else is removed, from Storage too); images[] adds
     * new ones, up to MAX_PHOTOS in total.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $buyer = $request->user();

        $review = Review::where('id', $id)->where('buyer_id', $buyer->id)->first();

        if (! $review) {
            return response()->json(['message' => 'Review not found.'], 404);
        }

        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
            'keep_images' => 'nullable|array|max:'.self::MAX_PHOTOS,
            'keep_images.*' => 'string|max:1000',
            ...$this->photoRules(),
        ], $this->photoMessages());

        $current = collect(is_array($review->images) ? $review->images : [])->filter(fn ($url) => is_string($url))->values();
        // Absent keep_images (a plain JSON edit) keeps every photo.
        $keep = $request->has('keep_images')
            ? $current->intersect($data['keep_images'] ?? [])->values()
            : $current;
        $newFiles = $request->file('images', []);

        if ($keep->count() + count($newFiles) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'images' => 'A review can have up to '.self::MAX_PHOTOS.' photos.',
            ]);
        }

        $added = $this->uploadPhotos($buyer->id, $newFiles);

        if ($added === null) {
            return $this->uploadFailed();
        }

        $review->update([
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
            'images' => [...$keep->all(), ...$added],
            'updated_at' => now(),
        ]);

        $this->deletePhotos($current->diff($keep)->all());

        return response()->json(['data' => $this->transformWithStats($review)]);
    }

    /**
     * DELETE /api/buyer/reviews/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $buyer = $request->user();

        $review = Review::where('id', $id)->where('buyer_id', $buyer->id)->first();

        if (! $review) {
            return response()->json(['message' => 'Review not found.'], 404);
        }

        $photos = is_array($review->images) ? $review->images : [];
        $productId = $review->product_id;
        $review->delete();
        $this->deletePhotos($photos);

        return response()->json([
            'message' => 'Review deleted.',
            'data' => ['productStats' => $productId ? ReviewStats::forProduct($productId) : null],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function photoRules(): array
    {
        return [
            'images' => 'nullable|array|max:'.self::MAX_PHOTOS,
            'images.*' => 'file|mimetypes:image/jpeg,image/png,image/webp|max:'.self::MAX_PHOTO_KILOBYTES,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function photoMessages(): array
    {
        return [
            'images.max' => 'A review can have up to '.self::MAX_PHOTOS.' photos.',
            'images.*.mimetypes' => 'Photos must be JPEG, PNG or WebP.',
            'images.*.max' => 'Each photo must be 5 MB or smaller.',
        ];
    }

    /**
     * Uploads every photo or none (a failure removes the ones already
     * uploaded). Returns their public URLs, or null on failure.
     *
     * @param  array<int, UploadedFile>  $files
     * @return list<string>|null
     */
    private function uploadPhotos(string $buyerId, array $files): ?array
    {
        if ($files === []) {
            return [];
        }

        $urls = [];

        try {
            $this->storage->ensureBucket(self::PHOTO_BUCKET, public: true);

            foreach ($files as $file) {
                $mime = (string) $file->getMimeType();
                $path = $buyerId.'/'.Str::uuid().'.'.$this->storage->extensionForMime($mime);
                $urls[] = $this->storage->upload(self::PHOTO_BUCKET, $path, (string) file_get_contents($file->getRealPath()), $mime);
            }
        } catch (\RuntimeException $e) {
            report($e);
            $this->deletePhotos($urls);

            return null;
        }

        return $urls;
    }

    /**
     * Best effort: a photo left behind in Storage is harmless, so a
     * failure here never fails the request.
     *
     * @param  iterable<string>  $urls
     */
    private function deletePhotos(iterable $urls): void
    {
        $prefix = $this->storage->publicUrl(self::PHOTO_BUCKET, '');
        $paths = collect($urls)
            ->filter(fn ($url) => is_string($url) && str_starts_with($url, $prefix))
            ->map(fn (string $url) => substr($url, strlen($prefix)))
            ->values()
            ->all();

        if ($paths === []) {
            return;
        }

        try {
            $this->storage->delete(self::PHOTO_BUCKET, $paths);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function uploadFailed(): JsonResponse
    {
        return response()->json(['message' => 'Your photos couldn\'t be uploaded. Please try again.'], 502);
    }

    /**
     * The product columns a review response shows — with the lite image
     * list, so an inline product photo never comes back with it.
     */
    private function productColumns(): \Closure
    {
        return fn ($query) => ProductImage::selectWithLiteImages($query, ['products.id', 'products.name', 'products.category', 'products.status', 'products.seller_id', 'products.updated_at'])
            ->with('seller:id,account_status');
    }

    /**
     * A saved review plus its product's new public rating and count, so the
     * client can update every copy of that product without a reload.
     *
     * @return array<string, mixed>
     */
    private function transformWithStats(Review $review): array
    {
        $review->load(['product' => $this->productColumns()]);

        return [
            ...$this->transform($review),
            'productStats' => $review->product_id ? ReviewStats::forProduct($review->product_id) : null,
        ];
    }

    /**
     * Whether other buyers can see this review, and why not when they
     * can't. Reviews aren't moderated here (no status column), so a saved
     * review is public as long as its product is listed; the product page
     * and every rating hide it when the product isn't.
     *
     * @return array{isPublic: bool, visibilityNote: string|null}
     */
    private function visibility(Review $review): array
    {
        $product = $review->product;

        if (! $review->product_id || ! $product) {
            return ['isPublic' => false, 'visibilityNote' => 'The product was removed, so this review is no longer shown to other buyers.'];
        }

        if ($product->status !== 'active' || $product->seller?->account_status !== 'active') {
            return ['isPublic' => false, 'visibilityNote' => 'This product isn’t listed right now, so its reviews are hidden until it’s back.'];
        }

        return ['isPublic' => true, 'visibilityNote' => null];
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
            // A link, never an inline photo (ProductImage::cardUrl()).
            'image' => $product ? ProductImage::cardUrl($product) : null,
            'images' => collect(is_array($review->images) ? $review->images : [])->filter(fn ($url) => is_string($url) && $url !== '')->values()->all(),
            'orderItemId' => $review->order_item_id,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'createdAt' => optional($review->created_at)->toIso8601String(),
            'updatedAt' => optional($review->updated_at)->toIso8601String(),
            'isEdited' => $review->updated_at && $review->created_at
                && ! $review->updated_at->equalTo($review->created_at),
            'sellerResponse' => $review->seller_response,
            'respondedAt' => optional($review->responded_at)->toIso8601String(),
            ...$this->visibility($review),
        ];
    }
}
