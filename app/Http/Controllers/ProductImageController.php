<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/products/{id}/images/{index}?v=&w=&variant=
 *
 * Serves a product photo that is stored inline (a base64 data: URL in
 * products.images or product_variants.image) as a real image file, so the
 * catalogue's JSON never carries it (see ProductImage::liteSql()).
 *
 *   - Same visibility rule as the catalogue: only an active product of an
 *     active seller; anything else is a 404.
 *   - Only that one photo is read from the database, and only on a cache
 *     miss. The decoded file (and each resized copy) is kept in the file
 *     cache, keyed by the product's updated_at, so a seller's edit gets a
 *     new key and a new URL (?v= is the same timestamp) — nothing stale is
 *     served and nothing needs invalidating by hand.
 *   - w=CARD_WIDTH returns a resized WebP (JPEG without WebP support) for
 *     product cards; no w returns the original.
 *   - Browsers may keep a response for a year: the URL changes when the
 *     photo does.
 *
 * `php artisan catalog:cache-inline-images` warms the cache from the CLI,
 * where reading a large photo over a slow link isn't bound by the web
 * request's time limit.
 */
class ProductImageController extends Controller
{
    /** Width of the product card thumbnail (pixels). */
    public const CARD_WIDTH = 480;

    private const WIDTHS = [self::CARD_WIDTH];

    private const MAX_INDEX = 19;

    private const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function show(Request $request, string $id, int $index): Response
    {
        $width = in_array((int) $request->query('w'), self::WIDTHS, true) ? (int) $request->query('w') : null;
        $variantId = $request->query('variant');

        if (! Str::isUuid($id) || $index < 0 || $index > self::MAX_INDEX || ($variantId !== null && ! Str::isUuid((string) $variantId))) {
            abort(404);
        }

        $image = self::cached($id, $index, $width, $variantId === null ? null : (string) $variantId);

        if ($image === null) {
            abort(404);
        }

        return response($image['bytes'], 200, [
            'Content-Type' => $image['type'],
            'Content-Length' => (string) strlen($image['bytes']),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The photo as ['type' => mime, 'bytes' => binary], from the file cache
     * or (once) from the database; null when the product isn't visible or
     * the entry isn't an inline image.
     *
     * @return array{type: string, bytes: string}|null
     */
    public static function cached(string $id, int $index, ?int $width = null, ?string $variantId = null): ?array
    {
        $updatedAt = Product::query()->visibleToBuyers()->whereKey($id)->value('updated_at');

        if ($updatedAt === null && ! Product::query()->visibleToBuyers()->whereKey($id)->exists()) {
            return null;
        }

        $version = $updatedAt ? strtotime((string) $updatedAt) : 0;
        $base = "product-image.{$id}.".($variantId ?? 'p').".{$index}.{$version}";
        $store = Cache::store('file');

        $original = $store->get($base);

        if ($original === null) {
            $original = self::fetchOriginal($id, $index, $variantId);

            if ($original === null) {
                return null;
            }

            $store->put($base, $original, now()->addDays(30));
        }

        if ($width === null) {
            return $original;
        }

        return $store->remember("{$base}.w{$width}", now()->addDays(30), fn () => self::resize($original, $width) ?? $original);
    }

    /**
     * @return array{type: string, bytes: string}|null
     */
    private static function fetchOriginal(string $id, int $index, ?string $variantId): ?array
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $raw = $variantId === null
                ? DB::table('products')->where('id', $id)->selectRaw(ProductImage::entrySql().' as raw', [$index])->value('raw')
                : DB::table('product_variants')->where('id', $variantId)->where('product_id', $id)->selectRaw(ProductImage::entrySql('product_variants.image').' as raw', [$index])->value('raw');
        } else {
            $images = $variantId === null
                ? DB::table('products')->where('id', $id)->value('images')
                : DB::table('product_variants')->where('id', $variantId)->where('product_id', $id)->value('image');
            $decoded = json_decode((string) $images, true);
            $entries = is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];
            $entry = $entries[$index] ?? null;
            $raw = is_array($entry) ? ($entry['url'] ?? null) : $entry;
        }

        return self::decodeDataUrl(is_string($raw) ? $raw : '');
    }

    /**
     * @return array{type: string, bytes: string}|null
     */
    private static function decodeDataUrl(string $raw): ?array
    {
        if (! preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.*)$#is', trim($raw), $matches)) {
            return null;
        }

        $type = strtolower($matches[1]);
        $bytes = base64_decode(preg_replace('/\s+/', '', $matches[2]) ?? '', true);

        if (! in_array($type, self::ALLOWED_TYPES, true) || $bytes === false || $bytes === '') {
            return null;
        }

        return ['type' => $type, 'bytes' => $bytes];
    }

    /**
     * @param  array{type: string, bytes: string}  $image
     * @return array{type: string, bytes: string}|null
     */
    private static function resize(array $image, int $width): ?array
    {
        if (! function_exists('imagecreatefromstring') || $image['type'] === 'image/gif') {
            return null;
        }

        $source = @imagecreatefromstring($image['bytes']);

        if ($source === false) {
            return null;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        if ($sourceWidth <= $width) {
            imagedestroy($source);

            return null;
        }

        $height = (int) round($sourceHeight * $width / $sourceWidth);
        $target = imagecreatetruecolor($width, $height);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        ob_start();
        $webp = function_exists('imagewebp') && imagewebp($target, null, 80);

        if (! $webp) {
            imagejpeg($target, null, 82);
        }

        $bytes = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($target);

        return $bytes === '' ? null : ['type' => $webp ? 'image/webp' : 'image/jpeg', 'bytes' => $bytes];
    }
}
