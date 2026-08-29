<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\WishlistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Buyer's wishlist (buyer_wishlist_items). Scoped to the authenticated
 * buyer's own rows. Returns bare product ids — Wishlist.vue matches them
 * against the already-loaded public catalog (useBuyerProducts) rather than
 * duplicating product data here.
 */
class WishlistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $productIds = WishlistItem::query()
            ->where('buyer_profile_id', $request->user()->id)
            ->orderBy('created_at')
            ->pluck('product_id');

        return response()->json(['data' => $productIds]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'uuid', 'exists:products,id'],
        ]);

        $buyer = $request->user();

        // Never leak non-buyable products into a wishlist.
        $product = Product::query()->active()->whereKey($data['product_id'])->first();

        if (! $product) {
            return response()->json(['message' => 'This product is not available.'], 422);
        }

        WishlistItem::firstOrCreate([
            'buyer_profile_id' => $buyer->id,
            'product_id' => $product->id,
        ]);

        return response()->json(['message' => 'Added to wishlist.'], 201);
    }

    public function destroy(Request $request, string $productId): JsonResponse
    {
        WishlistItem::query()
            ->where('buyer_profile_id', $request->user()->id)
            ->where('product_id', $productId)
            ->delete();

        return response()->json(['message' => 'Removed from wishlist.']);
    }
}
