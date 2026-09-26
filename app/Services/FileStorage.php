<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Uploaded files on this server's disk, organised by "bucket".
 *
 * Public buckets (avatars, product images) live on the `public` disk and are
 * served directly at /storage/{bucket}/{path}. Everything else (ID
 * documents, delivery photos, resumes, ...) lives on the private `local`
 * disk and is only reachable through a short-lived signed URL
 * (FileController::show).
 */
class FileStorage
{
    public const PUBLIC_BUCKETS = ['avatars', 'product-images', 'return-evidence'];

    public function isPublic(string $bucket): bool
    {
        return in_array($bucket, self::PUBLIC_BUCKETS, true);
    }

    public function disk(string $bucket): Filesystem
    {
        return Storage::disk($this->isPublic($bucket) ? 'public' : 'local');
    }

    public function key(string $bucket, string $path): string
    {
        return $bucket.'/'.ltrim($path, '/');
    }

    /**
     * Writes `$binary` to `$bucket/$path` (overwriting) and returns its URL.
     *
     * @throws \RuntimeException when the file can't be written
     */
    public function upload(string $bucket, string $path, string $binary, string $mime): string
    {
        if (! $this->disk($bucket)->put($this->key($bucket, $path), $binary)) {
            Log::error('File upload failed', ['bucket' => $bucket, 'path' => $path, 'mime' => $mime]);

            throw new \RuntimeException("Could not store \"{$path}\".");
        }

        return $this->isPublic($bucket)
            ? $this->publicUrl($bucket, $path)
            : (string) $this->createSignedUrl($bucket, $path);
    }

    public function exists(string $bucket, string $path): bool
    {
        return $this->disk($bucket)->exists($this->key($bucket, $path));
    }

    /** Root-relative so stored URLs keep working across hosts. */
    public function publicUrl(string $bucket, string $path): string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));

        return "/storage/{$bucket}/{$encoded}";
    }

    /**
     * A time-limited URL for a file (public buckets just get their public
     * URL). Returns null when the file doesn't exist.
     */
    public function createSignedUrl(string $bucket, string $path, int $expiresInSeconds = 3600): ?string
    {
        if ($path === '' || ! $this->exists($bucket, $path)) {
            return null;
        }

        if ($this->isPublic($bucket)) {
            return $this->publicUrl($bucket, $path);
        }

        return URL::temporarySignedRoute('files.show', now()->addSeconds($expiresInSeconds), [
            'bucket' => $bucket,
            'path' => ltrim($path, '/'),
        ]);
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<string, string>  path => url (missing files are absent)
     */
    public function createSignedUrls(string $bucket, array $paths, int $expiresInSeconds = 3600): array
    {
        $urls = [];

        foreach (array_unique(array_filter($paths, fn ($p) => is_string($p) && $p !== '')) as $path) {
            if ($url = $this->createSignedUrl($bucket, $path, $expiresInSeconds)) {
                $urls[$path] = $url;
            }
        }

        return $urls;
    }

    /** Best-effort delete — a leftover file is never worth failing a save over. */
    public function delete(string $bucket, array $paths): void
    {
        $keys = array_map(fn ($p) => $this->key($bucket, $p), array_values(array_filter($paths)));

        if ($keys && ! $this->disk($bucket)->delete($keys)) {
            Log::warning('File delete failed (non-fatal)', ['bucket' => $bucket, 'paths' => $paths]);
        }
    }

    /**
     * Decodes a `data:<mime>;base64,<data>` string. Returns null for
     * anything else (already a real URL, malformed, etc.).
     *
     * @return array{mime: string, binary: string}|null
     */
    public function decodeDataUrl(string $dataUrl): ?array
    {
        if (! str_starts_with($dataUrl, 'data:')) {
            return null;
        }

        $parts = explode(',', $dataUrl, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$meta, $encoded] = $parts;
        $mime = 'application/octet-stream';

        if (preg_match('/^data:([^;]+);base64$/', $meta, $m)) {
            $mime = $m[1];
        }

        $binary = base64_decode($encoded, true);

        if ($binary === false) {
            return null;
        }

        return ['mime' => $mime, 'binary' => $binary];
    }

    public function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            default => 'jpg',
        };
    }
}
