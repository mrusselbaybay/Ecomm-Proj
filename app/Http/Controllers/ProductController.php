<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Review;
use App\Support\CategoryFieldConfig;
use App\Support\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public buyer-facing product catalog. Deliberately implemented as a
 * Laravel endpoint (not a direct Supabase read from the Vue SPA, the way
 * resources/js/seller/composables/useSellerProducts.js reads its own
 * products) because:
 *   1. it needs to join in the seller's storefront name from
 *      seller_details, which would otherwise mean embedding across
 *      profiles -> seller_details from the client with the anon key, and
 *   2. it must never leak products belonging to sellers/accounts that
 *      aren't active, which is easiest to guarantee with one server-side
 *      query rather than relying on RLS policies this task doesn't own.
 */
class ProductController extends Controller
{
    /**
     * GET /api/products
     *
     * Query params: search, category, seller_id, page, per_page (all optional).
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->catalogQuery();

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%")
                    ->orWhere('category', 'ilike', "%{$search}%");
            });
        }

        if ($category = $request->string('category')->toString()) {
            if (strtolower($category) !== 'all') {
                $query->where('category', 'ilike', $category);
            }
        }

        if ($sellerId = $request->string('seller_id')->toString()) {
            $query->where('seller_id', $sellerId);
        }

        $perPage = min((int) $request->integer('per_page', 60), 100) ?: 60;

        $products = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => $products->getCollection()->map(fn (Product $p) => $this->transform($p)),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    /**
     * GET /api/products/{id}
     */
    public function show(string $id): JsonResponse
    {
        $product = $this->catalogQuery()->find($id);

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json(['data' => $this->transform($product)]);
    }

    /**
     * The one query behind both index() and show(): only products that are
     * status = 'active' AND owned by an active seller account are ever
     * visible to buyers — enforced here at the database level, never left
     * to the frontend. Review count/average are pulled in as correlated
     * sub-selects (one extra scalar per row, no N+1, no dependency on a
     * relation being added to the shared Product model).
     */
    private function catalogQuery()
    {
        return Product::query()
            ->select('products.*')
            ->addSelect([
                'reviews_count' => Review::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('reviews.product_id', 'products.id'),
                'reviews_avg_rating' => Review::query()
                    ->selectRaw('round(avg(rating)::numeric, 1)')
                    ->whereColumn('reviews.product_id', 'products.id'),
            ])
            ->active()
            ->with(['seller.sellerDetail', 'options.values', 'variants.optionValues.option'])
            ->whereHas('seller', function ($q) {
                $q->where('account_status', 'active');
            });
    }

    /**
     * Shape matches what resources/js/buyer/components/Dashboard.vue,
     * ProductCard.vue, and ProductDetails.vue already read (id, name,
     * price, oldPrice, category, seller, images, stock, description) —
     * see resources/js/buyer/composables/useBuyerProducts.js.
     */
    private function transform(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'category' => $product->category,
            'brand' => $product->brand,
            'condition' => $product->condition,
            'dimensions' => $product->dimensions,
            'weight' => $product->weight !== null ? (float) $product->weight : null,
            // Human-labeled {label: value} pairs, built from the same
            // category template the seller form used to collect them —
            // reused as-is by ProductDetails.vue's existing
            // "Specifications" tab (hasSpecifications/spec-row). Only
            // fields that actually have a value are included, so an
            // incomplete spec never shows a blank row to the buyer.
            'specifications' => CategoryFieldConfig::labelSpecifications(
                $product->category,
                $product->specifications,
            ),
            'sku' => $product->sku,
            'price' => (float) $product->price,
            'oldPrice' => $product->compare_price ? (float) $product->compare_price : null,
            'stock' => (int) $product->stock,
            'lowStockThreshold' => $product->low_stock_threshold,
            // Only ever 'active' reaches a buyer (see catalogQuery()), but
            // returned explicitly so the frontend never has to infer it.
            'status' => $product->status,
            // Primary card image (first usable entry) as a ready-to-render
            // URL string, plus the full gallery normalized the same way.
            // products.images holds {url} objects whose url may be a data:
            // URL, a full URL, or a bare Supabase Storage path — all
            // resolved here so Vue only ever binds a plain string to :src,
            // and an imageless product falls back to a local placeholder.
            'image' => ProductImage::primaryUrl($product->images),
            'images' => ProductImage::urls($product->images),
            'seller_id' => $product->seller_id,
            'seller' => $product->seller?->sellerDetail?->business_name
                ?? $product->seller?->full_name
                ?? 'NEXMART Seller',
            // Sellers have exactly one category === their line_of_business
            // (enforced by DB trigger), exposed by name for the homepage's
            // "line of business" labelling without a second lookup.
            'seller_line_of_business' => $product->seller?->sellerDetail?->line_of_business
                ?? $product->category,
            // Aggregates from the correlated sub-selects in catalogQuery().
            // No reviews yet => rating null, reviewCount 0 (the card
            // already renders "No reviews yet" for that case).
            'rating' => $product->reviews_avg_rating !== null
                ? (float) $product->reviews_avg_rating
                : null,
            'reviewCount' => (int) ($product->reviews_count ?? 0),
            'hasVariants' => (bool) $product->has_variants,
            'options' => $product->options->map(fn ($opt) => [
                'id' => $opt->id,
                'name' => $opt->name,
                'values' => $opt->values->map(fn ($v) => [
                    'id' => $v->id,
                    'value' => $v->value,
                ])->all(),
            ])->all(),
            // Buyers only ever see variants belonging to an already-active
            // product (see the ->active() scope on index()/show() above);
            // unavailable/out-of-stock variants are still included here
            // (not filtered out) so the frontend can show them disabled
            // rather than silently missing, but their own status/stock
            // still blocks purchase — enforced again in CheckoutService,
            // never trusted from the client at add-to-cart time.
            'variants' => $product->variants->map(fn ($v) => [
                'id' => $v->id,
                'sku' => $v->sku,
                'price' => $v->price !== null ? (float) $v->price : (float) $product->price,
                'stock' => (int) $v->stock,
                // Kept as a {url} object (or null) to match what
                // ProductDetails.vue reads (selectedVariant.image.url); the
                // url itself is normalized so a stored path still resolves,
                // and null lets the frontend fall back to the product image.
                'image' => ($vurl = ProductImage::normalize($v->image)) !== null
                    ? ['url' => $vurl]
                    : null,
                'status' => $v->status,
                'option_values' => $v->optionValues->mapWithKeys(
                    fn ($ov) => [$ov->option?->name ?? '' => $ov->value],
                )->all(),
            ])->all(),
            'created_at' => $product->created_at,
        ];
    }
}
