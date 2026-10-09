<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Rules and helpers for the store page content a seller supplies
 * (seller_details.banner_path / description / return_policy).
 *
 * Shared by both sides, so keep it identical on feature/buyer (which
 * reads it for the public store API) and feature/seller (whose settings
 * endpoint writes it). The length caps match the CHECK constraints in the
 * add_store_profile_columns migration.
 */
class StoreProfile
{
    public const DESCRIPTION_MAX = 1000;

    public const RETURN_POLICY_MAX = 2000;

    /**
     * Public Supabase Storage bucket for store banners. Objects are
     * written only with the service role key (Laravel), under
     * "<seller profile id>/<uuid>.<ext>".
     */
    public const BANNER_BUCKET = 'store-banners';

    /**
     * @var list<string>
     */
    public const BANNER_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const BANNER_MAX_KILOBYTES = 3072;

    /**
     * Wide images only, so the banner never needs awkward cropping: at
     * least 1200x300, at most 4000x2000, between 2:1 and 6:1.
     *
     * @var array{min_width: int, min_height: int, max_width: int, max_height: int}
     */
    public const BANNER_DIMENSIONS = [
        'min_width' => 1200,
        'min_height' => 300,
        'max_width' => 4000,
        'max_height' => 2000,
    ];

    public const BANNER_MIN_RATIO = 2.0;

    public const BANNER_MAX_RATIO = 6.0;

    /**
     * Validation rules for the uploaded banner file.
     *
     * @return list<string>
     */
    public static function bannerRules(): array
    {
        $d = self::BANNER_DIMENSIONS;

        return [
            'required',
            'file',
            'mimetypes:'.implode(',', self::BANNER_MIMES),
            'max:'.self::BANNER_MAX_KILOBYTES,
            "dimensions:min_width={$d['min_width']},min_height={$d['min_height']},max_width={$d['max_width']},max_height={$d['max_height']}",
        ];
    }

    public static function bannerRatioAllowed(int $width, int $height): bool
    {
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $ratio = $width / $height;

        return $ratio >= self::BANNER_MIN_RATIO && $ratio <= self::BANNER_MAX_RATIO;
    }

    /**
     * True when $path is a banner object inside this seller's own folder.
     */
    public static function ownsBannerPath(string $sellerId, ?string $path): bool
    {
        $path = (string) $path;

        return $path !== ''
            && Str::startsWith($path, $sellerId.'/')
            && ! Str::contains($path, '..');
    }

    /**
     * Public URL for a stored banner path, or null when there is none (or
     * Supabase isn't configured) so the store page uses its fallback.
     */
    public static function bannerUrl(?string $path): ?string
    {
        $path = trim((string) $path);

        if (Str::startsWith($path, ['https://', 'http://'])) {
            return $path;
        }

        if (Str::startsWith($path, '/storage/')) {
            return url($path);
        }

        $base = rtrim((string) config('services.supabase.url'), '/');

        if ($path === '' || $base === '') {
            return null;
        }

        return $base.'/storage/v1/object/public/'.self::BANNER_BUCKET.'/'.ltrim($path, '/');
    }

    /**
     * Wording a store policy can't use: every buyer can request a return
     * from Orders once an order is delivered (PlatformReturnPolicy), so a
     * store can describe its process but not refuse returns outright.
     *
     * @var list<string>
     */
    public const RETURN_POLICY_CONFLICTS = [
        '/\bno\s+returns?\b/i',
        '/\bno\s+refunds?\b/i',
        '/\bnon[-\s]?(?:returnable|refundable)\b/i',
        '/\ball\s+sales?\s+(?:are\s+)?final\b/i',
        '/\breturns?\s+(?:are\s+)?not\s+(?:accepted|allowed)\b/i',
        '/\b(?:do|does|will)\s+not\s+accept\s+returns?\b/i',
    ];

    /**
     * True when the policy text contradicts the platform return rules.
     */
    public static function returnPolicyConflicts(?string $policy): bool
    {
        $text = (string) $policy;

        foreach (self::RETURN_POLICY_CONFLICTS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Descriptions and policies are plain text: tags are stripped, line
     * endings normalised, runs of blank lines collapsed. Empty becomes
     * null so the store page falls back cleanly.
     */
    public static function plainText(?string $value): ?string
    {
        $text = strip_tags((string) $value);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\S\n]+\n/', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = trim($text);

        return $text === '' ? null : $text;
    }
}
