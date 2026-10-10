<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\StoreCatalog;
use App\Services\AuthSession;
use App\Services\BuyerStoreBlocks;
use App\Models\Profile;
use App\Support\ProductImage;
use App\Support\ProductSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/search/suggestions?q=...
 *
 * The header search's suggestions: a few products and a few stores from
 * the whole buyer-visible catalogue, matched and ranked exactly as the
 * results page and the store list do (App\Support\ProductSearch), so a
 * suggestion is always something the results page would show near the
 * top. Only what a suggestion row displays is returned; the full product
 * is fetched when one is picked (GET /api/products/{id}).
 *
 * Fewer than MIN_LENGTH characters returns empty lists without querying.
 */
class SearchSuggestionController extends Controller
{
    public const MIN_LENGTH = 2;

    private const PRODUCTS = 5;

    private const STORES = 4;

    public function __construct(private StoreCatalog $stores, private AuthSession $sessions, private BuyerStoreBlocks $blocks) {}

    public function __invoke(Request $request): JsonResponse
    {
        $search = mb_substr(trim($request->string('q')->toString()), 0, 100);

        if (mb_strlen($search) < self::MIN_LENGTH || ! ProductSearch::isSearchable($search)) {
            return response()->json(['query' => $search, 'products' => [], 'stores' => []]);
        }

        return response()->json([
            'query' => $search,
            'products' => $this->products($search, $this->buyer($request)),
            'stores' => $this->storeMatches($search, $this->buyer($request)),
        ]);
    }

    /**
     * @return list<array{id: string, name: string, price: float, image: string|null, category: string|null}>
     */
    private function products(string $search, ?Profile $buyer): array
    {
        $query = ProductImage::selectWithLiteImages(
            Product::query()->active()->whereHas('seller', fn ($seller) => $seller->where('account_status', 'active')),
            ['products.id', 'products.name', 'products.price', 'products.category', 'products.created_at', 'products.updated_at'],
        );
        $this->blocks->constrainProducts($query, $buyer);

        ProductSearch::apply($query, $search);
        ProductSearch::orderByRelevance($query, $search);

        return $query->orderByDesc('products.created_at')
            ->orderBy('products.id')
            ->limit(self::PRODUCTS)
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'price' => (float) $product->price,
                'image' => ProductImage::cardUrl($product),
                'category' => $product->category,
            ])
            ->all();
    }

    /**
     * @return list<array{id: string, name: string, category: string|null, logo: string|null}>
     */
    private function storeMatches(string $search, ?Profile $buyer): array
    {
        $query = $this->blocks->constrainStores($this->stores->visibleStores(), $buyer)
            ->select(['profiles.id', 'profiles.avatar_path', 'seller_details.business_name', 'seller_details.line_of_business']);

        ProductSearch::applyToStores($query, $search);
        ProductSearch::orderStoresByRelevance($query, $search);

        return $query->orderByRaw('lower(seller_details.business_name) asc')
            ->orderBy('profiles.id')
            ->limit(self::STORES)
            ->get()
            ->map(fn ($store) => [
                'id' => $store->id,
                'name' => $store->business_name ?: 'BuyTheWay Seller',
                'category' => $store->line_of_business,
                'logo' => $this->stores->logoUrl($store->avatar_path),
            ])
            ->all();
    }

    private function buyer(Request $request): ?Profile
    {
        $profile = $this->sessions->resolve($request->bearerToken());

        return $profile?->role === Profile::ROLE_BUYER ? $profile : null;
    }
}
