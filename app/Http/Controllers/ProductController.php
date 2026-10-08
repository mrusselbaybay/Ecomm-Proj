<?php

namespace App\Http\Controllers;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\SellerDetail;
use App\Support\CategoryFieldConfig;
use App\Support\CompletedSales;
use App\Support\ProductImage;
use App\Support\ProductSearch;
use App\Support\PublicReview;
use App\Support\ReviewStats;
use App\Support\SchemaCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    /** Whether products.subcategory exists (Postgres only), checked once per request. */
    private ?bool $hasSubcategory = null;

    /**
     * GET /api/products
     *
     * Query params: search, category, subcategory[], seller_id, ids, page,
     * per_page, facets (all optional), plus the browse filters / sort described on
     * applyBrowseFilters() and applySort().
     *
     * fields=card returns products without options / variants (null),
     * for listings that only render cards.
     *
     * `ids` is a comma-separated list of product ids (max 100), used by the
     * buyer cart to re-check every line in one request. Ids that aren't in
     * the response are unavailable (inactive, deleted, or their seller is
     * not active), the same rule show() applies with a 404.
     */
    public function index(Request $request): JsonResponse
    {
        // fields=card (the search results page): only what a product card
        // shows — no options / variants, five fewer queries. Opening one
        // fetches the full product (GET /api/products/{id}).
        $query = $this->catalogQuery(withPurchaseOptions: $request->string('fields')->toString() !== 'card');

        if ($request->filled('ids')) {
            $ids = collect(explode(',', $request->string('ids')->toString()))
                ->map(fn (string $id) => trim($id))
                ->filter(fn (string $id) => Str::isUuid($id))
                ->unique()
                ->take(100)
                ->values();

            // At most 100 known rows: skip pagination and its extra count query.
            $products = $query->whereIn('products.id', $ids)->get();

            return response()->json([
                'data' => $products->map(fn (Product $p) => $this->transform($p)),
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'total' => $products->count(),
                ],
            ]);
        }

        $this->applySearchFilters($query, $request);

        $perPage = min((int) $request->integer('per_page', 60), 100) ?: 60;

        $products = $this->applySort(
            $query,
            $request->string('sort')->toString(),
            trim($request->string('search')->toString()),
        )->paginate($perPage);

        $response = [
            'data' => $products->getCollection()->map(fn (Product $p) => $this->transform($p)),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ],
        ];

        if ($request->boolean('facets')) {
            $response['facets'] = $this->facets($request);
        }

        return response()->json($response);
    }

    /**
     * GET /api/products/related?search=...  (plus the results page's filters)
     *
     * "Related products" under a search: products that do NOT match the
     * search, chosen from catalogue data only — no hand-made links:
     *   - From the categories the direct matches come from: the (at most
     *     three) most common category / subcategory pairs among them.
     *     Products in one of those subcategories come first, then the rest
     *     of those categories; within that, best sellers, then newest.
     *   - When nothing matches directly and pg_trgm is installed: products
     *     whose names are trigram-similar to the query (a looser threshold
     *     than search itself uses), most similar first.
     * Every direct match is excluded (not only the page on screen), the
     * buyer-visibility rules are the catalogue's, the browse filters (stock,
     * sale, price, rating) still apply, and the result is capped at
     * `limit` (max 12). `basis` says why these were picked, so the page can
     * label them honestly; an empty list means "hide the section".
     */
    public function related(Request $request): JsonResponse
    {
        $search = trim($request->string('search')->toString());
        $limit = min(max((int) $request->integer('limit', 8), 1), 12);

        if (! ProductSearch::isSearchable($search)) {
            return response()->json(['data' => [], 'basis' => null]);
        }

        $hasSubcategory = $this->hasSubcategoryColumn();

        $matches = $this->visibleProducts();
        $this->applySearchFilters($matches, $request);

        $anchors = $matches->toBase()
            ->selectRaw('products.category as category')
            ->selectRaw($hasSubcategory ? 'products.subcategory as subcategory' : 'null as subcategory')
            ->selectRaw('count(*) as total')
            ->whereNotNull('products.category')
            ->groupBy('products.category')
            ->when($hasSubcategory, fn ($q) => $q->groupBy('products.subcategory'))
            ->orderByDesc('total')
            ->orderBy('products.category')
            ->limit(3)
            ->get();

        $query = $this->catalogQuery(withPurchaseOptions: false);
        ProductSearch::exclude($query, $search);
        $this->applySearchFilters($query, $request, ['search', 'category', 'subcategory']);

        if ($anchors->isNotEmpty()) {
            $categories = $anchors->pluck('category')->unique()->values()->all();
            $subcategories = $anchors->pluck('subcategory')->filter()->unique()->values()->all();

            $query->whereIn('products.category', $categories);

            if ($subcategories !== []) {
                $query->orderByRaw(
                    'case when products.subcategory in ('.implode(', ', array_fill(0, count($subcategories), '?')).') then 0 else 1 end',
                    $subcategories,
                );
            }

            $basis = [
                'type' => 'category',
                'categories' => $anchors
                    ->map(fn (object $row) => ['category' => (string) $row->category, 'subcategory' => $row->subcategory ?: null])
                    ->values()
                    ->all(),
            ];
        } elseif (($schema = ProductSearch::trigramSchema()) && mb_strlen(ProductSearch::phrase($search)) >= ProductSearch::MIN_FUZZY_LENGTH) {
            $phrase = ProductSearch::phrase($search);

            $query->whereRaw("lower(products.name) OPERATOR({$schema}.%) ?", [$phrase])
                ->orderByRaw("{$schema}.similarity(lower(products.name), ?) desc", [$phrase]);

            $basis = ['type' => 'similar_name'];
        } else {
            return response()->json(['data' => [], 'basis' => null]);
        }

        $products = $this->applySort($query, 'popular')->limit($limit)->get();

        return response()->json([
            'data' => $products->map(fn (Product $p) => $this->transform($p))->values(),
            'basis' => $products->isEmpty() ? null : $basis,
        ]);
    }

    /**
     * search, category, subcategory[], seller_id and the browse filters.
     * $skip leaves some of them out — facet counts ("how many if I picked
     * this") and related products. What a search matches, and how, is
     * App\Support\ProductSearch's: name, brand, category, subcategory,
     * description and the store's name, typo-tolerant on PostgreSQL.
     *
     * @param  list<'search'|'category'|'subcategory'|'price'>  $skip
     */
    private function applySearchFilters($query, Request $request, array $skip = []): void
    {
        if (! in_array('search', $skip, true)) {
            ProductSearch::apply($query, $request->string('search')->toString());
        }

        $category = $this->categoryFilter($request);

        if (! in_array('category', $skip, true) && $category !== null) {
            $query->whereRaw('lower(products.category) = ?', [mb_strtolower($category)]);
        }

        $subcategories = $this->subcategoryFilter($request);

        if (! in_array('subcategory', $skip, true) && $subcategories !== []) {
            $query->whereIn('products.subcategory', $subcategories);
        }

        if ($sellerId = $request->string('seller_id')->toString()) {
            $query->where('products.seller_id', $sellerId);
        }

        $this->applyBrowseFilters($query, $request, skipPrice: in_array('price', $skip, true));
    }

    private function categoryFilter(Request $request): ?string
    {
        $category = trim($request->string('category')->toString());

        return $category !== '' && strtolower($category) !== 'all' ? $category : null;
    }

    /**
     * @return list<string>
     */
    private function subcategoryFilter(Request $request): array
    {
        if (! $this->hasSubcategoryColumn()) {
            return [];
        }

        return collect(Arr::wrap($request->query('subcategory')))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

    /**
     * Sidebar counts across every product matching the search (not just
     * the current page), in ONE grouped query. Each facet ignores its own
     * filter and honours all the others:
     *   categories     every filter except category / subcategory
     *   subcategories  every filter except subcategory (only once a
     *                  category is chosen)
     *   price          every filter except price_min / price_max
     *   has_ratings,   every filter — whether the rating filter and the
     *   has_sales      Top rated / Top sales sorts can mean anything
     * The search and the other browse filters (stock, sale, condition,
     * rating) narrow the grouped rows; category, subcategory and price are
     * applied per aggregate with CASE so one pass serves every facet.
     *
     * The frontend asks for facets only when the search or a filter
     * changes, never for a page or sort change (SearchResults.vue).
     *
     * @return array{categories: list<array{name: string, count: int}>, subcategories: list<array{name: string, count: int}>, price: array{min: float, max: float}|null, has_ratings: bool, has_sales: bool}
     */
    private function facets(Request $request): array
    {
        $base = $this->visibleProducts();
        $this->applySearchFilters($base, $request, ['category', 'subcategory', 'price']);

        [$inPrice, $priceBindings] = $this->priceCondition($request);
        [$inCategory, $categoryBindings] = $this->categoryCondition($request);
        [$inSubcategory, $subcategoryBindings] = $this->subcategoryCondition($request);

        $reviewed = 'exists (select 1 from reviews where reviews.product_id = products.id and '.Review::ELIGIBLE_SQL.')';
        $sold = 'exists (select 1 from order_items inner join orders on orders.id = order_items.order_id where order_items.product_id = products.id and '.CompletedSales::sql().')';
        $all = "{$inPrice} and {$inCategory} and {$inSubcategory}";
        $allBindings = [...$priceBindings, ...$categoryBindings, ...$subcategoryBindings];
        $hasSubcategory = $this->hasSubcategoryColumn();

        $rows = $base->toBase()
            ->selectRaw('products.category as category')
            ->selectRaw($hasSubcategory ? 'products.subcategory as subcategory' : 'null as subcategory')
            ->selectRaw("sum(case when {$inPrice} then 1 else 0 end) as in_price", $priceBindings)
            ->selectRaw("sum(case when {$inPrice} and {$inCategory} then 1 else 0 end) as in_category", [...$priceBindings, ...$categoryBindings])
            ->selectRaw("min(case when {$inCategory} and {$inSubcategory} then products.price end) as low", [...$categoryBindings, ...$subcategoryBindings])
            ->selectRaw("max(case when {$inCategory} and {$inSubcategory} then products.price end) as high", [...$categoryBindings, ...$subcategoryBindings])
            ->selectRaw("sum(case when {$all} and {$reviewed} then 1 else 0 end) as rated", $allBindings)
            ->selectRaw("sum(case when {$all} and {$sold} then 1 else 0 end) as sold", $allBindings)
            ->groupBy('products.category')
            ->when($hasSubcategory, fn ($q) => $q->groupBy('products.subcategory'))
            ->get();

        $sumBy = fn (string $key, string $column) => $rows
            ->filter(fn (object $row) => $row->{$key} !== null && $row->{$key} !== '')
            ->groupBy(fn (object $row) => (string) $row->{$key})
            ->map(fn ($group, string $name) => ['name' => $name, 'count' => (int) $group->sum($column)])
            ->filter(fn (array $facet) => $facet['count'] > 0)
            ->sortBy('name')
            ->values()
            ->all();

        $low = $rows->pluck('low')->filter(fn ($value) => $value !== null);
        $high = $rows->pluck('high')->filter(fn ($value) => $value !== null);

        return [
            'categories' => $sumBy('category', 'in_price'),
            'subcategories' => $this->categoryFilter($request) !== null ? $sumBy('subcategory', 'in_category') : [],
            'price' => $low->isEmpty() ? null : [
                'min' => (float) $low->min(),
                'max' => (float) $high->max(),
            ],
            'has_ratings' => $rows->sum('rated') > 0,
            'has_sales' => $rows->sum('sold') > 0,
        ];
    }

    /**
     * @return array{0: string, 1: list<float>}
     */
    private function priceCondition(Request $request): array
    {
        $parts = [];
        $bindings = [];

        foreach (['price_min' => '>=', 'price_max' => '<='] as $param => $operator) {
            $value = $request->query($param);

            if (is_numeric($value) && (float) $value >= 0) {
                $parts[] = "products.price {$operator} ?";
                $bindings[] = (float) $value;
            }
        }

        return [$parts ? '('.implode(' and ', $parts).')' : '1 = 1', $bindings];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function categoryCondition(Request $request): array
    {
        $category = $this->categoryFilter($request);

        return $category === null ? ['1 = 1', []] : ['lower(products.category) = ?', [mb_strtolower($category)]];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function subcategoryCondition(Request $request): array
    {
        $subcategories = $this->subcategoryFilter($request);

        return $subcategories === []
            ? ['1 = 1', []]
            : ['products.subcategory in ('.implode(', ', array_fill(0, count($subcategories), '?')).')', $subcategories];
    }

    private function hasSubcategoryColumn(): bool
    {
        return $this->hasSubcategory ??= SchemaCache::hasColumn('products', 'subcategory');
    }

    /**
     * Optional, additive browse filters used by the store page
     * (StorePage.vue): in_stock=1, on_sale=1, condition (repeatable or
     * comma-separated), price_min / price_max, min_rating (3 or 4).
     * Prices compare the product's base price, the same figure cards show.
     */
    private function applyBrowseFilters($query, Request $request, bool $skipPrice = false): void
    {
        if ($request->boolean('in_stock')) {
            $query->where('products.stock', '>', 0);
        }

        if ($request->boolean('on_sale')) {
            $query->whereNotNull('products.compare_price')
                ->whereColumn('products.compare_price', '>', 'products.price');
        }

        $conditions = collect(Arr::wrap($request->query('condition')))
            ->flatMap(fn ($value) => explode(',', (string) $value))
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->unique()
            ->take(10)
            ->values();

        if ($conditions->isNotEmpty()) {
            $query->whereIn('products.condition', $conditions->all());
        }

        foreach ($skipPrice ? [] : ['price_min' => '>=', 'price_max' => '<='] as $param => $operator) {
            $value = $request->query($param);

            if (is_numeric($value) && (float) $value >= 0) {
                $query->where('products.price', $operator, (float) $value);
            }
        }

        $minRating = (int) $request->integer('min_rating');

        if (in_array($minRating, [3, 4], true)) {
            $query->where(
                fn ($q) => $q->selectRaw('avg(reviews.rating)')
                    ->from('reviews')
                    ->whereColumn('reviews.product_id', 'products.id')
                    ->whereRaw(Review::ELIGIBLE_SQL),
                '>=',
                $minRating,
            );
        }
    }

    /**
     * sort = newest (default) | relevance | price-asc | price-desc | rating | popular | name-asc.
     * Ties fall back to newest, then id, so pages never shuffle.
     *
     * relevance (with a search) is ProductSearch::orderByRelevance(): exact
     * name, name prefix, every word in the name, name contains, store name,
     * other fields, typo-only matches.
     */
    private function applySort($query, string $sort, string $search = '')
    {
        if ($sort === 'relevance' && $search !== '') {
            ProductSearch::orderByRelevance($query, $search);
        }

        switch ($sort) {
            case 'price-asc':
                $query->orderBy('products.price');
                break;
            case 'price-desc':
                $query->orderByDesc('products.price');
                break;
            case 'rating':
                // Unrated products last; Postgres can't use the
                // reviews_avg_rating alias inside an expression.
                $query->orderByRaw('coalesce((select avg(reviews.rating) from reviews where reviews.product_id = products.id and '.Review::ELIGIBLE_SQL.'), 0) desc')
                    ->orderByDesc('reviews_count');
                break;
            case 'popular':
                // Most units delivered first, matching soldCount.
                $query->orderByRaw('coalesce(('.self::soldUnitsSql().'), 0) desc');
                break;
            case 'name-asc':
                $query->orderByRaw('lower(products.name) asc');
                break;
        }

        return $query->orderByDesc('products.created_at')->orderBy('products.id');
    }

    /**
     * Units of a product on completed (Delivered, not refunded) orders, as a correlated sub-select on
     * products.id (the same rule as soldCount in transform()).
     */
    private static function soldUnitsSql(): string
    {
        return 'select sum(order_items.quantity) from order_items join orders on orders.id = order_items.order_id where order_items.product_id = products.id and '.CompletedSales::sql();
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
     * GET /api/products/{id}/reviews
     *
     * Public, paginated list of a product's buyer reviews — powers the
     * "read reviews before buying" drawer on the cart (and is reusable on
     * the product page). Additive: no existing endpoint returned a
     * product-scoped review list (GET /api/buyer/reviews is the signed-in
     * buyer's *own* reviews only). Same visibility rule as the catalog —
     * reviews are only exposed for an active product owned by an active
     * seller.
     *
     * Query params (all optional): page, per_page (max 20),
     * rating (1-5, exact), has_images (bool).
     */
    public function reviews(Request $request, string $id): JsonResponse
    {
        $product = $this->visibleProduct($id);

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $productId = (string) $product->id;
        $perPage = min(max((int) $request->integer('per_page', 5), 1), 20);
        $rating = (int) $request->integer('rating');
        $hasImages = $request->boolean('has_images');

        $paginated = $this->reviewQuery($productId, $rating, $hasImages)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $rows = [];

        foreach ($paginated->items() as $review) {
            $rows[] = PublicReview::transform($review);
        }

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
            ],
            'summary' => $this->reviewSummary($productId),
        ]);
    }

    /**
     * An active product owned by an active seller, or null — the same
     * visibility rule catalogQuery() enforces, without the review
     * sub-selects (reviews() computes its own summary).
     */
    private function visibleProduct(string $id): ?Product
    {
        return Product::query()
            ->active()
            ->whereHas('seller', fn ($q) => $q->where('account_status', 'active'))
            ->find($id);
    }

    /**
     * Base query for a product's reviews with the optional exact-rating
     * and has-images filters applied. "Has photos" is Review::scopeWithPhotos()
     * (a non-empty images list), and PublicReview::transform() still guards
     * the contents.
     */
    private function reviewQuery(string $productId, int $rating, bool $hasImages)
    {
        $query = Review::query()
            ->eligible()
            ->with(['buyer:id,first_name,last_name', 'orderItem:id,variant'])
            ->where('product_id', $productId);

        if ($rating >= 1 && $rating <= 5) {
            $query->where('rating', $rating);
        }

        if ($hasImages) {
            $query->withPhotos();
        }

        return $query;
    }

    /**
     * Unfiltered rating summary for the whole product: average, count,
     * the 5→1 star breakdown, and how many reviews carry photos.
     *
     * @return array{average: float|null, total: int, breakdown: array<int, int>, with_images: int}
     */
    private function reviewSummary(string $productId): array
    {
        $counts = Review::query()
            ->eligible()
            ->where('product_id', $productId)
            ->selectRaw('rating, count(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $breakdown = [];
        $total = 0;

        foreach ([5, 4, 3, 2, 1] as $star) {
            $starCount = (int) ($counts[$star] ?? 0);
            $breakdown[$star] = $starCount;
            $total += $starCount;
        }

        // Same rows and rounding as the cards' rating (ReviewStats).
        $average = ReviewStats::forProduct($productId)['rating'];

        $withImages = (int) Review::query()
            ->eligible()
            ->where('product_id', $productId)
            ->withPhotos()
            ->count();

        return [
            'average' => $average,
            'total' => $total,
            'breakdown' => $breakdown,
            'with_images' => $withImages,
        ];
    }

    /**
     * The one query behind both index() and show(): only products that are
     * status = 'active' AND owned by an active seller account are ever
     * visible to buyers — enforced here at the database level, never left
     * to the frontend. Review count/average are pulled in as correlated
     * sub-selects (one extra scalar per row, no N+1, no dependency on a
     * relation being added to the shared Product model).
     */
    /** Active products of active sellers: the buyer-visible catalog. */
    private function visibleProducts()
    {
        return Product::query()->visibleToBuyers();
    }

    /**
     * The buyer-visible catalogue with everything a product response needs,
     * in as few round trips to the database as possible (each one costs
     * ~110 ms against Supabase before any work is done):
     *   - images come back as ProductImage::liteSql() — inline photos stay
     *     in the database and are served by the image endpoint instead;
     *     shipping a ~100 kB base64 photo with every list was what made
     *     searches run into PHP's 30-second limit;
     *   - the store's name / line of business and the review and sales
     *     aggregates are correlated sub-selects, not extra queries;
     *   - options and variants (two levels each) only when the response
     *     needs them ($withPurchaseOptions); cards don't.
     */
    private function catalogQuery(bool $withPurchaseOptions = true)
    {
        $query = Product::query();
        $this->selectProductColumns($query);

        $query->addSelect([
            'reviews_count' => Review::query()
                ->eligible()
                ->selectRaw('count(*)')
                ->whereColumn('reviews.product_id', 'products.id'),
            'reviews_avg_rating' => Review::query()
                ->eligible()
                // avg() of the smallint rating is already numeric on
                // Postgres; no cast keeps this portable to SQLite tests.
                ->selectRaw('round(avg(rating), 1)')
                ->whereColumn('reviews.product_id', 'products.id'),
            'sold_count' => CompletedSales::constrain(
                OrderItem::query()
                    ->selectRaw('coalesce(sum(order_items.quantity), 0)')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->whereColumn('order_items.product_id', 'products.id'),
            ),
            'seller_business_name' => SellerDetail::query()
                ->select('business_name')
                ->whereColumn('seller_details.profile_id', 'products.seller_id'),
            'seller_line_of_business' => SellerDetail::query()
                ->select('line_of_business')
                ->whereColumn('seller_details.profile_id', 'products.seller_id'),
            'seller_person_name' => DB::table('profiles')
                ->selectRaw("trim(coalesce(profiles.first_name, '') || ' ' || coalesce(profiles.last_name, ''))")
                ->whereColumn('profiles.id', 'products.seller_id'),
        ]);

        $query->visibleToBuyers();

        if ($withPurchaseOptions) {
            $query->with([
                'options.values',
                'variants' => fn ($variants) => $this->selectVariantColumns($variants),
                'variants.optionValues.option',
            ]);
        }

        return $query;
    }

    /**
     * products.* on PostgreSQL, except that `images` is the lite version.
     * The column list comes from SchemaCache (no query per request).
     */
    private function selectProductColumns($query): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $query->select('products.*');

            return;
        }

        $columns = array_values(array_filter(SchemaCache::columns('products'), fn (string $column) => $column !== 'images'));

        $query->select(array_map(fn (string $column) => "products.{$column}", $columns))
            ->selectRaw(ProductImage::liteSql().' as images');
    }

    /**
     * Variants without an inline image payload (see selectProductColumns()).
     */
    private function selectVariantColumns($query): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $columns = array_values(array_filter(SchemaCache::columns('product_variants'), fn (string $column) => $column !== 'image'));

        $query->select(array_map(fn (string $column) => "product_variants.{$column}", $columns))
            ->selectRaw("case when coalesce(product_variants.image->>'url', product_variants.image #>> '{}') like 'data:%' then jsonb_build_object('inline', 0) else product_variants.image end as image");
    }

    /**
     * Shape matches what resources/js/buyer/components/Dashboard.vue,
     * ProductCard.vue, and ProductDetails.vue already read (id, name,
     * price, oldPrice, category, seller, images, stock, description) —
     * see resources/js/buyer/composables/useBuyerProducts.js.
     */
    /**
     * {label: value} specifications from the seller's own template. A
     * listing made before its category gained subcategories has no
     * subcategory, but its keys (animal_type, food_type …) are the same
     * ones those subcategories use, so it's labelled against all of them.
     *
     * @return array<string, mixed>
     */
    private function labelledSpecifications(Product $product): array
    {
        $category = (string) $product->category;
        $specifications = $product->specifications;

        if ($product->subcategory || ! CategoryFieldConfig::hasSubcategories($category) || empty($specifications)) {
            return CategoryFieldConfig::labelSpecifications($category, $product->subcategory, $specifications);
        }

        $labels = [];

        foreach (CategoryFieldConfig::subcategoriesFor($category) as $subcategory) {
            $labels += CategoryFieldConfig::labelSpecifications($category, $subcategory, $specifications);
        }

        return $labels;
    }

    private function transform(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'category' => $product->category,
            // One of CategoryFieldConfig::subcategoriesFor($category), or
            // null for listings made before subcategories existed.
            'subcategory' => $product->subcategory,
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
            'specifications' => $this->labelledSpecifications($product),
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
            // Inline (data:) photos are never in the payload: they're served
            // by GET /api/products/{id}/images/{n} — the card's copy as a
            // resized thumbnail.
            'image' => ProductImage::primaryUrl($product->images, fn (int $n) => ProductImage::inlineUrl($product->id, $n, $product->updated_at, ProductImageController::CARD_WIDTH)),
            'images' => ProductImage::urls($product->images, fn (int $n) => ProductImage::inlineUrl($product->id, $n, $product->updated_at)),
            'seller_id' => $product->seller_id,
            'seller' => ($product->seller_business_name ?: null)
                ?? ($product->seller_person_name ?: null)
                ?? 'BuyTheWay Seller',
            // Sellers have exactly one category === their line_of_business
            // (enforced by DB trigger), exposed by name for the homepage's
            // "line of business" labelling without a second lookup.
            'seller_line_of_business' => $product->seller_line_of_business
                ?? $product->category,
            // Aggregates from the correlated sub-selects in catalogQuery().
            // No reviews yet => rating null, reviewCount 0 (the card
            // already renders "No reviews yet" for that case).
            'rating' => $product->reviews_avg_rating !== null
                ? (float) $product->reviews_avg_rating
                : null,
            'reviewCount' => (int) ($product->reviews_count ?? 0),
            // Units on completed orders only (CompletedSales: Delivered and
            // not refunded), so cancelled, refunded or still in-flight
            // orders never inflate the number buyers see.
            'soldCount' => (int) ($product->sold_count ?? 0),
            'hasVariants' => (bool) $product->has_variants,
            // null (not []) when the response was built without them
            // (fields=card, related products): "not loaded", not "none".
            'options' => ! $product->relationLoaded('options') ? null : $product->options->map(fn ($opt) => [
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
            'variants' => ! $product->relationLoaded('variants') ? null : $product->variants->map(fn ($v) => [
                'id' => $v->id,
                'sku' => $v->sku,
                'price' => $v->price !== null ? (float) $v->price : (float) $product->price,
                'stock' => (int) $v->stock,
                // Kept as a {url} object (or null) to match what
                // ProductDetails.vue reads (selectedVariant.image.url); the
                // url itself is normalized so a stored path still resolves,
                // and null lets the frontend fall back to the product image.
                'image' => ($vurl = ProductImage::normalize($v->image, fn () => ProductImage::inlineUrl($product->id, 0, $product->updated_at, variantId: $v->id))) !== null
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
