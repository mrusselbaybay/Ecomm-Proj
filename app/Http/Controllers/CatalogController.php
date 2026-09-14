<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\CategoryFieldConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Public, unauthenticated read-only endpoints backing the BuyTheWay
 * marketing homepage (resources/js/home) — category tiles and a small
 * product feed a visitor can browse before signing in.
 *
 * Deliberately separate from Seller\SellerProductController, which is
 * seller-scoped (auth required, "my own products" only, and returns
 * seller-management fields like stock-adjustment history this surface
 * has no business exposing). This controller only ever reads
 * Product::active() rows and only returns buyer-safe fields.
 */
class CatalogController extends Controller
{
    /**
     * GET /api/catalog/categories
     *
     * Every category BuyTheWay recognizes — the same fixed taxonomy a
     * seller registers their line_of_business under (see
     * CategoryFieldConfig) — each with how many ACTIVE listings exist
     * in it right now. A zero count is real data, not an error: the
     * storefront still links there so the category directory doesn't
     * shrink to only whichever category happens to have inventory
     * today.
     */
    public function categories(): JsonResponse
    {
        $counts = Product::active()
            ->selectRaw('category, count(*) as aggregate')
            ->groupBy('category')
            ->pluck('aggregate', 'category');

        // Cheapest active listing per category — the homepage's editorial
        // collection list shows a real "From ₱…" figure only when this is
        // non-null (see CollectionList in resources/js/home), never an
        // invented starting price for a category with no active stock.
        $minPrices = Product::active()
            ->selectRaw('category, min(price) as floor_price')
            ->groupBy('category')
            ->pluck('floor_price', 'category');

        $categories = collect(CategoryFieldConfig::categories())
            ->map(fn (string $name) => [
                'name' => $name,
                'slug' => Str::slug($name),
                'product_count' => (int) ($counts[$name] ?? 0),
                'min_price' => isset($minPrices[$name]) ? (float) $minPrices[$name] : null,
            ])
            ->values();

        return response()->json(['data' => $categories]);
    }

    /**
     * GET /api/catalog/products
     *
     * Query params (all optional):
     *   category - exact category name, matched against products.category
     *   search   - matched against name/brand
     *   limit    - max rows returned, capped at 24 (default 12)
     *
     * Always active-only, newest first. There is no "featured" flag or
     * promotions table in this schema, so "newest" is the only honest
     * default ordering — see the homepage's own copy for how it labels
     * this (it does not claim a curated "featured" selection that isn't
     * real).
     */
    public function products(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->integer('limit', 12), 24));

        $query = Product::active()
            ->with(['seller.sellerDetail'])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating');

        if ($category = trim((string) $request->query('category', ''))) {
            $query->where('category', $category);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        $products = $query->latest()->limit($limit)->get();

        return response()->json([
            'data' => $products->map(fn (Product $p) => $this->transform($p))->values(),
        ]);
    }

    /**
     * GET /api/catalog/products/{id}
     *
     * Single active product, buyer-safe fields only. Returns 404 for a
     * missing id AND for a non-active one (pending/archived listings
     * are not publicly browsable — same rule as Product::scopeActive()
     * everywhere else buyers touch the catalog).
     */
    public function show(string $id): JsonResponse
    {
        $product = Product::active()
            ->with(['seller.sellerDetail'])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->find($id);

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json(['data' => $this->transform($product)]);
    }

    private function transform(Product $product): array
    {
        $images = $product->images ?? [];
        $firstImage = $images[0]['url'] ?? null;

        $ratingAvg = $product->reviews_avg_rating;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'category' => $product->category,
            'price' => (float) $product->price,
            'compare_price' => $product->compare_price !== null ? (float) $product->compare_price : null,
            'has_variants' => (bool) $product->has_variants,
            'stock' => (int) $product->stock,
            'stock_status' => $product->stockStatus(),
            'is_out_of_stock' => $product->isOutOfStock(),
            'image' => $firstImage,
            'seller_name' => $product->seller?->sellerDetail?->business_name,
            'rating_avg' => $ratingAvg !== null ? round((float) $ratingAvg, 1) : null,
            'rating_count' => (int) ($product->reviews_count ?? 0),
            'created_at' => optional($product->created_at)->toIso8601String(),
        ];
    }
}
