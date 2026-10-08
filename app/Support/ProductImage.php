<?php

namespace App\Support;

use App\Http\Controllers\ProductImageController;

/**
 * Normalizes whatever is stored in products.images / product_variants.image
 * into safe, ready-to-render URL strings for the buyer-facing API.
 *
 * What the database actually holds (see the seller Inventory form):
 *   - products.images        : jsonb array of {url, isNew?} objects
 *   - product_variants.image : jsonb single {url} object (or null)
 * and `url` is, depending on when/how the row was written:
 *   - a base64 data: URL   (current seller uploads — no Storage bucket yet)
 *   - a full http(s) URL   (once a Storage bucket is wired, or external)
 *   - a bare storage path  (e.g. "seller-uuid/file.jpg")
 * Entries are also sometimes plain strings rather than {url} objects,
 * which is why every accessor here tolerates both shapes.
 *
 * Inline data: URLs are the big ones (a single photo is ~100 kB of base64)
 * and over the Supabase link every byte of a query's result costs time:
 * one such column took 10-40 seconds to arrive, which is what pushed
 * search requests past PHP's 30-second limit. So list and detail queries
 * select liteSql() instead of products.images: the database swaps each
 * inline entry for a small {"inline": n} marker, and the URL helpers below
 * turn a marker into the image endpoint's URL (inlineUrl()), which serves
 * the decoded, cached file to the browser on its own request.
 *
 * Lives in app/Support/ (alongside CategoryFieldConfig) because the same
 * products.images shape is written by the seller side; this class is a
 * candidate to be reused there and should be kept in sync across branches.
 */
class ProductImage
{
    /**
     * Supabase Storage bucket that product image *paths* resolve against.
     * Matches the bucket name the seller Inventory upload note references
     * ("supabase.storage.from('product-images')"). Hardcoded rather than a
     * config key to keep this change off the shared config/services.php.
     */
    private const BUCKET = 'product-images';

    /**
     * Safe fallback returned when a product/variant has no usable image.
     * A local static asset — never a remote URL that could 404.
     */
    public const PLACEHOLDER = '/images/product-placeholder.svg';

    /**
     * The single image a product card should show: the first entry that
     * resolves to a non-empty URL ("primary or first"), or the placeholder.
     *
     * @param  mixed  $images  products.images (array), a single {url}, or null
     */
    public static function primaryUrl(mixed $images, ?callable $inline = null): string
    {
        foreach (self::urls($images, $inline) as $url) {
            return $url;
        }

        return self::PLACEHOLDER;
    }

    /**
     * Every stored image as a clean URL string, in order, with unusable
     * entries dropped. Never contains the placeholder — callers that need
     * a guaranteed non-empty value use primaryUrl().
     *
     * @param  mixed  $images  products.images (array), a single {url}, or null
     * @param  (callable(int): ?string)|null  $inline  URL for an {"inline": n} marker (liteSql())
     * @return array<int, string>
     */
    public static function urls(mixed $images, ?callable $inline = null): array
    {
        if (is_string($images) || self::isUrlObject($images)) {
            $images = [$images];
        }

        if (! is_array($images)) {
            return [];
        }

        $out = [];

        foreach (array_values($images) as $position => $entry) {
            $url = self::normalize($entry, $inline);

            // A full data: URL that reached PHP anyway (not loaded through
            // liteSql(), e.g. on sqlite) still leaves as an endpoint URL
            // when the caller can build one.
            if ($inline && $url !== null && str_starts_with($url, 'data:')) {
                $url = $inline($position);
            }

            if ($url !== null) {
                $out[] = $url;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Resolve one image entry ({url: "..."} | "..." ) to an absolute,
     * renderable URL, or null when there is nothing usable.
     */
    public static function normalize(mixed $entry, ?callable $inline = null): ?string
    {
        $raw = null;

        if (is_array($entry) && isset($entry['inline']) && is_int($entry['inline'])) {
            return $inline ? $inline($entry['inline']) : null;
        }

        if (is_string($entry)) {
            $raw = $entry;
        } elseif (is_array($entry) && isset($entry['url']) && is_string($entry['url'])) {
            $raw = $entry['url'];
        } elseif (is_object($entry) && isset($entry->url) && is_string($entry->url)) {
            $raw = $entry->url;
        }

        $raw = is_string($raw) ? trim($raw) : '';

        if ($raw === '') {
            return null;
        }

        // Already renderable as-is.
        if (
            str_starts_with($raw, 'http://')
            || str_starts_with($raw, 'https://')
            || str_starts_with($raw, 'data:')
        ) {
            return $raw;
        }

        // Root-relative asset path (e.g. the placeholder itself).
        if (str_starts_with($raw, '/')) {
            return $raw;
        }

        // Otherwise treat it as a Supabase Storage object path.
        $base = rtrim((string) config('services.supabase.url'), '/');

        if ($base === '') {
            // No Supabase URL configured — can't build a valid link, so
            // fall back rather than emit a broken relative URL.
            return null;
        }

        return $base.'/storage/v1/object/public/'.self::BUCKET.'/'.ltrim($raw, '/');
    }

    /**
     * PostgreSQL expression: the images column as a jsonb array (a single
     * {url} or string is wrapped) with every inline data: URL replaced by
     * {"inline": n}, n being its position — so the photo itself never
     * leaves the database with a list or detail query.
     */
    public static function liteSql(string $column = 'products.images'): string
    {
        return "(select coalesce(jsonb_agg(case when coalesce(listed.entry->>'url', listed.entry #>> '{}') like 'data:%' then jsonb_build_object('inline', listed.position - 1) else listed.entry end order by listed.position), '[]'::jsonb) from jsonb_array_elements(".self::arraySql($column).') with ordinality as listed(entry, position))';
    }

    /**
     * PostgreSQL expression: the raw stored url text of one entry, by
     * position (the image endpoint's single-photo fetch). Bind the
     * position as an integer.
     */
    public static function entrySql(string $column = 'products.images'): string
    {
        return "(select coalesce(e->>'url', e #>> '{}') from (select (".self::arraySql($column).') -> ?::int as e) picked)';
    }

    /**
     * The image endpoint URL for an inline photo, versioned by the
     * product's updated_at so browsers and the cache can keep it forever.
     */
    public static function inlineUrl(string $productId, int $index, mixed $updatedAt, ?int $width = null, ?string $variantId = null): string
    {
        $version = $updatedAt ? strtotime((string) $updatedAt) : 0;

        return '/api/products/'.$productId.'/images/'.$index.'?v='.$version
            .($width ? '&w='.$width : '')
            .($variantId ? '&variant='.$variantId : '');
    }

    /**
     * Selects $columns plus `images` on a products query — the lite form on
     * PostgreSQL, so inline photos never come back with it. Include
     * updated_at in $columns when the result builds inline URLs.
     *
     * @param  list<string>  $columns
     */
    public static function selectWithLiteImages($query, array $columns, string $table = 'products')
    {
        $query->select($columns);

        if ($query->getConnection()->getDriverName() === 'pgsql') {
            return $query->selectRaw(self::liteSql("{$table}.images").' as images');
        }

        return $query->addSelect("{$table}.images");
    }

    /**
     * The first image of a product loaded with selectWithLiteImages(), as
     * a URL (inline photos as the card-sized image endpoint URL), or null.
     */
    public static function cardUrl(object $product): ?string
    {
        return self::urls(
            $product->images ?? null,
            fn (int $n) => self::inlineUrl((string) $product->id, $n, $product->updated_at ?? null, ProductImageController::CARD_WIDTH),
        )[0] ?? null;
    }

    /**
     * Selects $columns plus `image` on a product_variants query, the
     * inline photo swapped for {"inline": 0} on PostgreSQL (see liteSql()).
     *
     * @param  list<string>  $columns
     */
    public static function selectVariantWithLiteImage($query, array $columns)
    {
        $query->select($columns);

        if ($query->getConnection()->getDriverName() === 'pgsql') {
            return $query->selectRaw("case when coalesce(product_variants.image->>'url', product_variants.image #>> '{}') like 'data:%' then jsonb_build_object('inline', 0) else product_variants.image end as image");
        }

        return $query->addSelect('product_variants.image');
    }

    private static function arraySql(string $column): string
    {
        return "case jsonb_typeof({$column}) when 'array' then {$column} when 'object' then jsonb_build_array({$column}) when 'string' then jsonb_build_array({$column}) else '[]'::jsonb end";
    }

    private static function isUrlObject(mixed $value): bool
    {
        return (is_array($value) && array_key_exists('url', $value))
            || (is_object($value) && isset($value->url));
    }
}
