<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Profile;
use App\Models\Review;
use App\Models\StoreFollow;
use App\Services\AuthSession;
use App\Services\BuyerStoreBlocks;
use App\Services\StoreCatalog;
use App\Support\CheckoutOptions;
use App\Support\CompletedSales;
use App\Support\PlatformReturnPolicy;
use App\Support\ProductImage;
use App\Support\ProductSearch;
use App\Support\PublicReview;
use App\Support\StoreProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Public buyer-facing store directory (StoreDirectory.vue) and individual
 * store pages (StorePage.vue).
 *
 * A "store" is a seller profile with a seller_details row whose account is
 * active, the same visibility rule ProductController::catalogQuery() applies
 * to the products themselves, so a store page can never list products that
 * the catalog would hide, and a hidden seller has no store page.
 *
 * What a store has: business_name, line_of_business (one per seller, and
 * every product's category), the registration address (only city /
 * province are exposed, never the street), the profile avatar (used as
 * the store logo), the join date, and the optional banner / description /
 * return policy the seller sets in their settings. Anything a seller
 * hasn't supplied comes back null so the frontend renders its fallbacks.
 * The store-level definitions live in App\Services\StoreCatalog.
 *
 * Store products themselves are served by GET /api/products?seller_id=...
 */
class StoreController extends Controller
{
    /**
     * @var list<string>
     */
    private const SORTS = ['relevance', 'products', 'rating', 'newest', 'name'];

    /**
     * Latest product photos shown on a store's directory entry.
     */
    private const PREVIEW_IMAGES = 3;

    public function __construct(private StoreCatalog $stores, private AuthSession $sessions, private BuyerStoreBlocks $blocks) {}

    /**
     * GET /api/stores
     *
     * Query params (all optional): search (store name), category
     * (line of business), sort (relevance|products|rating|newest|name),
     * page, per_page (max 48).
     *
     * With a search, the default order (relevance, or products) ranks by
     * how well the name matches (ProductSearch::orderStoresByRelevance():
     * exact, prefix, a word starting with the query, contains, then
     * typo-tolerant), with product count as the tie-breaker; an explicit
     * rating / newest / name sort is respected as chosen. The final
     * tie-breakers are always name, then id, so pages stay stable.
     *
     * `facets.categories` counts stores per line of business for the
     * current search, ignoring the category filter, so the category
     * pills keep honest counts while one of them is selected.
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim($request->string('search')->toString());
        $category = trim($request->string('category')->toString());
        $sort = in_array($request->string('sort')->toString(), self::SORTS, true)
            ? $request->string('sort')->toString()
            : 'products';
        $perPage = min(max((int) $request->integer('per_page', 12), 1), 48);

        $buyer = $this->buyer($request);
        $visible = $this->blocks->constrainStores($this->stores->visibleStores(), $buyer);

        if ($search !== '') {
            if (ProductSearch::isSearchable($search)) {
                ProductSearch::applyToStores($visible, $search);
            } else {
                $visible->whereRaw('1 = 0');
            }
        }

        $categoryCounts = (clone $visible)
            ->toBase()
            ->selectRaw('seller_details.line_of_business as category, count(*) as total')
            ->groupBy('seller_details.line_of_business')
            ->get()
            ->map(fn (object $row) => ['name' => $row->category, 'count' => (int) $row->total])
            ->sortBy('name')
            ->values();

        $query = $this->stores->withStoreColumns($visible);

        if ($category !== '' && strtolower($category) !== 'all') {
            $query->where('seller_details.line_of_business', $category);
        }

        $this->applySort($query, $sort, $search);

        $stores = $query
            ->with([
                // Lite images: an inline photo stays in the database and is
                // linked to the image endpoint (ProductImage::liteSql()).
                'products' => fn ($q) => ProductImage::selectWithLiteImages(
                    $q->active(),
                    ['products.id', 'products.seller_id', 'products.created_at', 'products.updated_at'],
                )
                    ->latest()
                    ->limit(self::PREVIEW_IMAGES),
            ])
            ->when($this->stores->hasTable('addresses'), fn ($query) => $query->with('address'))
            ->paginate($perPage);

        return response()->json([
            'data' => $stores->getCollection()->map(fn (Profile $store) => $this->stores->transform($store)),
            'meta' => [
                'current_page' => $stores->currentPage(),
                'last_page' => $stores->lastPage(),
                'per_page' => $stores->perPage(),
                'total' => $stores->total(),
            ],
            'facets' => [
                'categories' => $categoryCounts,
                'has_ratings' => Review::query()
                    ->eligible()
                    ->whereIn('seller_id', $this->stores->visibleStores()->select('profiles.id'))
                    ->exists(),
            ],
        ]);
    }

    /**
     * GET /api/stores/{id}  (route middleware: supabase.auth:optional)
     *
     * The store plus:
     *   - productFacets: what its buyer-visible products actually vary by,
     *     so StorePage.vue only offers filters that can narrow the list;
     *   - followerCount, and isFollowing for a signed-in viewer (null for
     *     a guest);
     *   - returnPolicy (the store's own text) next to returnRules (the
     *     platform rules every store is held to, which it can't override);
     *   - fulfillment: the shipping options and payment methods checkout
     *     actually honours (App\Support\CheckoutOptions).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Store not found.'], 404);
        }

        $buyer = $this->buyer($request);
        $store = $this->stores->withStoreColumns($this->blocks->constrainStores($this->stores->visibleStores(), $buyer, includeReportHeld: true), withFollowerCount: true)
            ->where('profiles.id', $id)
            ->when($this->stores->hasTable('addresses'), fn ($query) => $query->with('address'))
            ->first();

        if (! $store) {
            return response()->json(['message' => 'Store not found.'], 404);
        }

        return response()->json([
            'data' => array_merge($this->stores->transform($store), [
                'returnPolicy' => StoreProfile::plainText($store->return_policy ?? null),
                'returnRules' => PlatformReturnPolicy::rules(),
                'followerCount' => (int) ($store->followers_count ?? 0),
                'isFollowing' => $this->isFollowing($this->sessions->resolve($request->bearerToken()), $id),
                'fulfillment' => CheckoutOptions::toArray(),
                'sales' => $this->salesSummary($id),
                'productFacets' => $this->productFacets($id),
            ]),
        ]);
    }

    /**
     * GET /api/stores/{id}/reviews?page=&per_page=&rating=
     *
     * The store's product reviews, newest first: the same rows its rating
     * is averaged from (StoreCatalog::productReviews), each with the
     * product it was written for. `summary` is unfiltered so the star
     * breakdown stays honest while one star level is selected.
     */
    public function reviews(Request $request, string $id): JsonResponse
    {
        $buyer = $this->buyer($request);
        if (! $this->blocks->constrainStores($this->stores->visibleStores(), $buyer, includeReportHeld: true)->where('profiles.id', $id)->exists()) {
            return response()->json(['message' => 'Store not found.'], 404);
        }

        $perPage = min(max((int) $request->integer('per_page', 10), 1), 20);
        $rating = (int) $request->integer('rating');
        // A product page already lists that product's own reviews, so its
        // shop-wide strip can leave them out.
        $excludeProduct = $request->string('exclude_product')->toString();

        $eligible = fn () => Review::query()
            ->eligible()
            ->where('reviews.seller_id', $id);

        $query = $eligible()
            ->with([
                'buyer:id,first_name,last_name',
                'orderItem:id,variant',
                'product' => fn ($q) => ProductImage::selectWithLiteImages($q, ['products.id', 'products.name', 'products.updated_at']),
            ])
            ->orderByDesc('reviews.created_at')
            ->orderBy('reviews.id');

        if ($rating >= 1 && $rating <= 5) {
            $query->where('reviews.rating', $rating);
        }

        if (Str::isUuid($excludeProduct)) {
            $query->where('reviews.product_id', '<>', $excludeProduct);
        }

        $page = $query->paginate($perPage);

        $counts = $eligible()
            ->selectRaw('rating, count(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $breakdown = [];

        foreach ([5, 4, 3, 2, 1] as $star) {
            $breakdown[$star] = (int) ($counts[$star] ?? 0);
        }

        $total = array_sum($breakdown);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Review $review) => array_merge(
                PublicReview::transform($review),
                ['product' => $review->product ? [
                    'id' => $review->product->id,
                    'name' => $review->product->name,
                    'image' => ProductImage::cardUrl($review->product),
                ] : null],
            ))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'summary' => [
                'average' => $total > 0 ? round((float) $eligible()->avg('reviews.rating'), 1) : null,
                'total' => $total,
                'breakdown' => $breakdown,
            ],
        ]);
    }

    /**
     * Public sales totals for a store, from completed orders only
     * (CompletedSales: Delivered and not refunded). Three different
     * numbers, labelled as such on the page: orders completed, units sold
     * across them, and how many distinct buyers placed them. Aggregates
     * only; no buyer is ever identified.
     *
     * @return array{completedOrders: int, itemsSold: int, buyerCount: int}
     */
    private function salesSummary(string $sellerId): array
    {
        $orders = fn () => CompletedSales::constrain(Order::query()->where('orders.seller_id', $sellerId));

        $itemsSold = (int) CompletedSales::constrain(
            OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.seller_id', $sellerId),
        )->sum('order_items.quantity');

        return [
            'completedOrders' => $orders()->count(),
            'itemsSold' => $itemsSold,
            'buyerCount' => $orders()->distinct()->count('orders.buyer_profile_id'),
        ];
    }

    /**
     * Null for a guest (or a non-buyer, who can't follow), so the page can
     * tell "not following" from "unknown".
     */
    private function isFollowing(?Profile $viewer, string $storeId): ?bool
    {
        if (! $viewer || $viewer->role !== 'buyer' || ! $this->stores->hasTable('store_follows')) {
            return null;
        }

        return StoreFollow::query()
            ->where('buyer_profile_id', $viewer->id)
            ->where('seller_id', $storeId)
            ->exists();
    }

    private function buyer(Request $request): ?Profile
    {
        $profile = $this->sessions->resolve($request->bearerToken());

        return $profile?->role === Profile::ROLE_BUYER ? $profile : null;
    }

    /**
     * Every sort ends on the store name, then id, so pages never shuffle
     * between requests.
     *
     * @param  Builder<Profile>  $query
     */
    private function applySort(Builder $query, string $sort, string $search = ''): void
    {
        if ($search !== '' && in_array($sort, ['relevance', 'products'], true)) {
            ProductSearch::orderStoresByRelevance($query, $search);
        }

        switch ($sort) {
            case 'rating':
                // Unrated stores last. Postgres can't use a select alias
                // inside an expression, so the sub-select is repeated.
                $average = $this->stores->productReviews()->selectRaw('avg(reviews.rating)');
                $query->orderByRaw('coalesce(('.$average->toSql().'), 0) desc', $average->getBindings())
                    ->orderByDesc('reviews_count');
                break;
            case 'newest':
                $query->orderByDesc('profiles.created_at');
                break;
            case 'name':
                break;
            default:
                $query->orderByDesc('products_count');
        }

        $query->orderByRaw('lower(seller_details.business_name) asc')
            ->orderBy('profiles.id');
    }

    /**
     * @return array{total: int, priceMin: float|null, priceMax: float|null, conditions: list<string>, hasSale: bool, hasOutOfStock: bool, hasRatings: bool, hasSold: bool}
     */
    private function productFacets(string $sellerId): array
    {
        $products = Product::query()->active()->where('seller_id', $sellerId);

        $summary = (clone $products)
            ->toBase()
            ->selectRaw('count(*) as total, min(price) as price_min, max(price) as price_max')
            ->selectRaw('sum(case when compare_price > price then 1 else 0 end) as on_sale')
            ->selectRaw('sum(case when stock <= 0 then 1 else 0 end) as out_of_stock')
            ->first();

        $conditions = (clone $products)
            ->whereNotNull('condition')
            ->where('condition', '!=', '')
            ->distinct()
            ->orderBy('condition')
            ->pluck('condition')
            ->values()
            ->all();

        $hasRatings = Review::query()
            ->eligible()
            ->whereIn('product_id', (clone $products)->select('id'))
            ->exists();

        // Same rule as ProductController's soldCount (CompletedSales).
        $hasSold = CompletedSales::constrain(
            OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('order_items.product_id', (clone $products)->select('id')),
        )->exists();

        return [
            'total' => (int) ($summary->total ?? 0),
            'priceMin' => $summary->price_min !== null ? (float) $summary->price_min : null,
            'priceMax' => $summary->price_max !== null ? (float) $summary->price_max : null,
            'conditions' => $conditions,
            'hasSale' => (int) ($summary->on_sale ?? 0) > 0,
            'hasOutOfStock' => (int) ($summary->out_of_stock ?? 0) > 0,
            'hasRatings' => $hasRatings,
            'hasSold' => $hasSold,
        ];
    }
}
