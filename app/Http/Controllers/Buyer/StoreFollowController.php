<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\StoreFollow;
use App\Services\StoreCatalog;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * A buyer following stores (store_follows). Every route runs behind
 * 'supabase.auth' + 'buyer', and only ever touches the signed-in buyer's
 * own rows.
 *
 * Only visible stores (StoreCatalog::visibleStores, i.e. active sellers
 * with a storefront) can be followed, and a profile can never follow
 * itself. Following is idempotent: following twice keeps one row and
 * unfollowing a store you don't follow is a no-op, so a double click or a
 * retried request can't create duplicates or fail.
 */
class StoreFollowController extends Controller implements HasMiddleware
{
    /**
     * Until the store_follows migration has run, answer clearly instead of
     * failing on a missing table.
     *
     * @return list<Closure>
     */
    public static function middleware(): array
    {
        return [
            function (Request $request, Closure $next) {
                if (! Schema::hasTable('store_follows')) {
                    return response()->json(['message' => 'Following stores is not available yet. Please try again later.'], 503);
                }

                return $next($request);
            },
        ];
    }

    private const PER_PAGE = 20;

    public function __construct(private StoreCatalog $stores) {}

    /**
     * GET /api/buyer/follows?page=
     *
     * The buyer's followed stores, most recently followed first. Stores
     * that have since closed drop out of the list (their follow row is
     * kept in case they reopen).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', self::PER_PAGE), 1), 48);

        $stores = $this->stores->withStoreColumns($this->stores->visibleStores())
            ->join('store_follows', 'store_follows.seller_id', '=', 'profiles.id')
            ->where('store_follows.buyer_profile_id', $request->user()->id)
            ->addSelect('store_follows.created_at as followed_at')
            ->with('address')
            ->orderByDesc('store_follows.created_at')
            ->orderBy('profiles.id')
            ->paginate($perPage);

        return response()->json([
            'data' => $stores->getCollection()->map(fn (Profile $store) => array_merge(
                $this->stores->transform($store),
                ['followedAt' => $store->followed_at ? Carbon::parse($store->followed_at)->toIso8601String() : null],
            )),
            'meta' => [
                'current_page' => $stores->currentPage(),
                'last_page' => $stores->lastPage(),
                'per_page' => $stores->perPage(),
                'total' => $stores->total(),
            ],
        ]);
    }

    /**
     * GET /api/buyer/follows/{storeId}
     */
    public function show(Request $request, string $storeId): JsonResponse
    {
        if (! $this->stores->isVisibleStore($storeId)) {
            return $this->notFound();
        }

        return response()->json(['data' => $this->status($request->user(), $storeId)]);
    }

    /**
     * POST /api/buyer/follows/{storeId}
     *
     * 201 when a new follow was created, 200 when it already existed.
     */
    public function store(Request $request, string $storeId): JsonResponse
    {
        $buyer = $request->user();

        if (! $this->stores->isVisibleStore($storeId)) {
            return $this->notFound();
        }

        if ($storeId === $buyer->id) {
            return response()->json(['message' => 'You can\'t follow your own store.'], 422);
        }

        try {
            $follow = StoreFollow::firstOrCreate([
                'buyer_profile_id' => $buyer->id,
                'seller_id' => $storeId,
            ]);
        } catch (QueryException $e) {
            // Lost a race with a concurrent follow: the unique constraint
            // kept one row, which is exactly the end state we want.
            if (! in_array((string) $e->getCode(), ['23505', '23000'], true)) {
                throw $e;
            }

            $follow = null;
        }

        return response()->json(
            ['data' => $this->status($buyer, $storeId)],
            $follow?->wasRecentlyCreated ? 201 : 200,
        );
    }

    /**
     * DELETE /api/buyer/follows/{storeId}
     */
    public function destroy(Request $request, string $storeId): JsonResponse
    {
        $buyer = $request->user();

        StoreFollow::query()
            ->where('buyer_profile_id', $buyer->id)
            ->where('seller_id', $storeId)
            ->delete();

        return response()->json(['data' => $this->status($buyer, $storeId)]);
    }

    /**
     * @return array{storeId: string, isFollowing: bool, followerCount: int}
     */
    private function status(Profile $buyer, string $storeId): array
    {
        return [
            'storeId' => $storeId,
            'isFollowing' => StoreFollow::query()
                ->where('buyer_profile_id', $buyer->id)
                ->where('seller_id', $storeId)
                ->exists(),
            'followerCount' => StoreFollow::query()->where('seller_id', $storeId)->count(),
        ];
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Store not found.'], 404);
    }
}
