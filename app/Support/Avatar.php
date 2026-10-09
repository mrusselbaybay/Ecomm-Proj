<?php

namespace App\Support;

use App\Services\FileStorage;
use Illuminate\Support\Str;

/**
 * Profile photos in the public avatars bucket (a seller's avatar is also
 * their store logo). Buyer uploads go
 * through Laravel (Buyer\AccountSettingsController) into
 * "<profile id>/<uuid>.<ext>".
 */
class Avatar
{
    public const BUCKET = 'avatars';

    /**
     * @var list<string>
     */
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const MAX_KILOBYTES = 2048;

    public const MIN_SIZE = 128;

    public const MAX_SIZE = 4000;

    /**
     * Public URL for a stored path (or an absolute URL as-is), or null.
     */
    public static function url(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (Str::startsWith($path, ['https://', 'http://'])) {
            return $path;
        }

        return app(FileStorage::class)->publicUrl(self::BUCKET, $path);
    }

    public static function ownsPath(string $profileId, ?string $path): bool
    {
        $path = (string) $path;

        return $path !== '' && Str::startsWith($path, $profileId.'/') && ! Str::contains($path, '..');
    }
}
