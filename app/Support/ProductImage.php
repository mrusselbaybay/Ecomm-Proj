<?php

namespace App\Support;

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
    public static function primaryUrl(mixed $images): string
    {
        foreach (self::urls($images) as $url) {
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
     * @return array<int, string>
     */
    public static function urls(mixed $images): array
    {
        if (is_string($images) || self::isUrlObject($images)) {
            $images = [$images];
        }

        if (! is_array($images)) {
            return [];
        }

        $out = [];

        foreach ($images as $entry) {
            $url = self::normalize($entry);

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
    public static function normalize(mixed $entry): ?string
    {
        $raw = null;

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

    private static function isUrlObject(mixed $value): bool
    {
        return (is_array($value) && array_key_exists('url', $value))
            || (is_object($value) && isset($value->url));
    }
}
