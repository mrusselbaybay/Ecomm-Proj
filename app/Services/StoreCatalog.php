<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Profile;
use App\Models\Review;
use App\Models\SellerVerification;
use App\Models\StoreFollow;
use App\Support\ProductImage;
use App\Support\StoreProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * What a public "store" is and how it is presented, shared by the store
 * directory / store page (StoreController) and a buyer's followed stores
 * (Buyer\StoreFollowController).
 *
 * A store is a seller profile with a seller_details row whose account is
 * active, the same visibility rule ProductController::catalogQuery()
 * applies to products. Only public fields leave this class: the street
 * address, contact details and account internals never do.
 *
 * The store-profile columns, follows and verifications come from
 * additive migrations; until those have run on a database, the matching
 * fields are simply returned empty instead of the query failing.
 */
class StoreCatalog
{
    /**
     * Public `avatars` Supabase Storage bucket the seller app uploads the
     * profile picture to (see the seller branch's useSeller.js).
     */
    private const AVATAR_BUCKET = 'avatars';

    /**
     * @var array<string, bool>
     */
    private array $schema = [];

    /**
     * Active seller profiles that have a storefront (seller_details row).
     *
     * @return Builder<Profile>
     */
    public function visibleStores(): Builder
    {
        return Profile::query()
            ->join('seller_details', 'seller_details.profile_id', '=', 'profiles.id')
            ->where('profiles.role', 'seller')
            ->where('profiles.account_status', 'active');
    }

    public function isVisibleStore(string $id): bool
    {
        return Str::isUuid($id) && $this->visibleStores()->where('profiles.id', $id)->exists();
    }

    /**
     * Adds the storefront columns plus product / review / verification
     * aggregates as correlated sub-selects (one scalar per row, no N+1).
     * Only active products are counted, matching what the store page can
     * list.
     *
     * @param  Builder<Profile>  $query
     * @return Builder<Profile>
     */
    public function withStoreColumns(Builder $query, bool $withFollowerCount = false): Builder
    {
        $columns = [
            'profiles.*',
            'seller_details.business_name',
            'seller_details.line_of_business',
        ];

        if ($this->hasStoreProfileColumns()) {
            array_push($columns, 'seller_details.banner_path', 'seller_details.description', 'seller_details.return_policy');
        }

        $aggregates = [
            'products_count' => Product::query()
                ->selectRaw('count(*)')
                ->whereColumn('products.seller_id', 'profiles.id')
                ->where('products.status', 'active'),
            'reviews_count' => $this->productReviews()->selectRaw('count(*)'),
            'reviews_avg_rating' => $this->productReviews()->selectRaw('round(avg(reviews.rating), 1)'),
        ];

        if ($this->hasTable('seller_verifications')) {
            $aggregates['verified_at'] = SellerVerification::query()
                ->select('seller_verifications.verified_at')
                ->whereColumn('seller_verifications.seller_id', 'profiles.id')
                ->where('seller_verifications.status', SellerVerification::STATUS_VERIFIED);
        }

        if ($withFollowerCount && $this->hasTable('store_follows')) {
            $aggregates['followers_count'] = StoreFollow::query()
                ->selectRaw('count(*)')
                ->whereColumn('store_follows.seller_id', 'profiles.id');
        }

        return $query->select($columns)->addSelect($aggregates);
    }

    /**
     * The store's rating source: its individual product reviews (reviews
     * written against one of its products). Averaging these rows directly
     * weights every review equally, rather than averaging per-product
     * averages. A review whose product was deleted is no longer a product
     * review and drops out, as it does from every product page.
     *
     * @return Builder<Review>
     */
    public function productReviews(): Builder
    {
        return Review::query()
            ->whereColumn('reviews.seller_id', 'profiles.id')
            ->whereNotNull('reviews.product_id');
    }

    /**
     * @return array{id: string, name: string, category: string|null, location: string|null, logo: string|null, banner: string|null, description: string|null, isVerified: bool, verifiedAt: string|null, productCount: int, rating: float|null, reviewCount: int, joinedAt: string|null, previewImages: list<string>}
     */
    public function transform(Profile $store): array
    {
        $reviewCount = (int) ($store->reviews_count ?? 0);
        $verifiedAt = $store->verified_at ?? null;

        $previewImages = $store->relationLoaded('products')
            ? $store->products
                ->map(fn (Product $product) => ProductImage::urls($product->images)[0] ?? null)
                ->filter()
                ->values()
                ->all()
            : [];

        return [
            'id' => $store->id,
            'name' => $store->business_name ?: ($store->full_name ?: 'BuyTheWay Seller'),
            'category' => $store->line_of_business,
            'location' => $this->locationOf($store),
            'logo' => $this->logoUrl($store->avatar_path),
            'banner' => StoreProfile::bannerUrl($store->banner_path ?? null),
            'description' => StoreProfile::plainText($store->description ?? null),
            'isVerified' => $verifiedAt !== null,
            'verifiedAt' => $verifiedAt !== null ? Carbon::parse($verifiedAt)->toIso8601String() : null,
            'productCount' => (int) ($store->products_count ?? 0),
            // No reviews => null, never a zero-star average.
            'rating' => $reviewCount > 0 && $store->reviews_avg_rating !== null
                ? (float) $store->reviews_avg_rating
                : null,
            'reviewCount' => $reviewCount,
            'joinedAt' => $store->created_at?->toIso8601String(),
            'previewImages' => $previewImages,
        ];
    }

    public function hasStoreProfileColumns(): bool
    {
        return $this->schema['seller_details.store_profile'] ??= Schema::hasColumns('seller_details', ['banner_path', 'description', 'return_policy']);
    }

    public function hasTable(string $table): bool
    {
        return $this->schema[$table] ??= Schema::hasTable($table);
    }

    /**
     * "City, Province" from the seller's registration address. The
     * street, house number and barangay are never exposed.
     */
    private function locationOf(Profile $store): ?string
    {
        $address = $store->relationLoaded('address') ? $store->address : null;

        if (! $address) {
            return null;
        }

        $parts = collect([$address->municipality_name, $address->province_name])
            ->map(fn ($part) => trim((string) $part))
            ->filter()
            ->unique()
            ->values();

        return $parts->isNotEmpty() ? $parts->implode(', ') : null;
    }

    /**
     * profiles.avatar_path is a path inside the public avatars bucket (or,
     * defensively, an absolute URL). Null when unset or when Supabase isn't
     * configured, so the frontend falls back to a monogram.
     */
    private function logoUrl(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (Str::startsWith($path, ['https://', 'http://'])) {
            return $path;
        }

        $base = rtrim((string) config('services.supabase.url'), '/');

        if ($base === '') {
            return null;
        }

        return $base.'/storage/v1/object/public/'.self::AVATAR_BUCKET.'/'.ltrim($path, '/');
    }
}
