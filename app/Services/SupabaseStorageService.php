<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around Supabase Storage's REST API, authenticated the
 * same way AuthController already talks to Supabase's admin API
 * (service_role_key as both `apikey` and a Bearer token) — no new
 * credentials needed, no S3-compatible disk driver to configure.
 *
 * Replaces the inline-base64-in-a-jsonb-column pattern used by
 * products.images / product_variants.image / reviews.images /
 * message_attachments.url: a real file goes here instead, and only a
 * short {path, url} reference gets stored in the database row. That's
 * what fixed the seller product list timing out (SELECT * was pulling
 * megabytes of base64 text through the DB connection on every load —
 * see the incident this class was built to resolve).
 */
class SupabaseStorageService
{
    private string $baseUrl;

    private string $serviceRoleKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.supabase.url'), '/');
        $this->serviceRoleKey = (string) config('services.supabase.service_role_key');
    }

    private function headers(): array
    {
        return [
            'apikey' => $this->serviceRoleKey,
            'Authorization' => 'Bearer '.$this->serviceRoleKey,
        ];
    }

    /**
     * Creates the bucket if it doesn't already exist. Safe to call on
     * every request that needs it (checks first) — buckets aren't
     * created via a migration since this project's `products` table
     * itself isn't Laravel-migrated either (Supabase-side schema, not
     * ours to own outright).
     */
    public function ensureBucket(string $bucket, bool $public = true): void
    {
        $existing = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/storage/v1/bucket/{$bucket}");

        if ($existing->successful()) {
            // Corrects a bucket's visibility if it was created (or left)
            // with the wrong one — e.g. message-attachments switching
            // from public to private for signed-URL access. Safe to
            // check on every call; only actually writes when it differs.
            if ((bool) $existing->json('public') !== $public) {
                Http::withHeaders($this->headers())
                    ->put("{$this->baseUrl}/storage/v1/bucket/{$bucket}", ['public' => $public]);
            }

            return;
        }

        $created = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/storage/v1/bucket", [
                'id' => $bucket,
                'name' => $bucket,
                'public' => $public,
            ]);

        if (! $created->successful()) {
            Log::error('Supabase Storage bucket creation failed', [
                'bucket' => $bucket,
                'status' => $created->status(),
                'body' => $created->body(),
            ]);

            throw new \RuntimeException("Could not create Supabase Storage bucket \"{$bucket}\".");
        }
    }

    /**
     * Uploads raw binary to `$bucket/$path` (`x-upsert` so re-uploading
     * the same path overwrites rather than erroring) and returns that
     * path's public URL. Throws on failure — callers should keep the
     * base64 fallback until this succeeds, never write a broken
     * reference into the database.
     */
    public function upload(string $bucket, string $path, string $binary, string $mime): string
    {
        $response = Http::withHeaders(array_merge($this->headers(), [
            'Content-Type' => $mime,
            'x-upsert' => 'true',
        ]))->withBody($binary, $mime)
            ->post("{$this->baseUrl}/storage/v1/object/{$bucket}/{$path}");

        if (! $response->successful()) {
            Log::error('Supabase Storage upload failed', [
                'bucket' => $bucket,
                'path' => $path,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException("Could not upload \"{$path}\" to Supabase Storage.");
        }

        return $this->publicUrl($bucket, $path);
    }

    public function publicUrl(string $bucket, string $path): string
    {
        return "{$this->baseUrl}/storage/v1/object/public/{$bucket}/{$path}";
    }

    /**
     * For a PRIVATE bucket (message-attachments): a time-limited URL
     * that works without the viewer being authenticated to Supabase
     * directly, generated fresh — never cached in the database, since a
     * cached signed URL would just go stale the same way a permanent
     * public URL would be a privacy problem. Matches the existing
     * Document::getUrlAttribute() pattern in this codebase (computed
     * on-demand via Storage::disk(...)->temporaryUrl(), never stored) —
     * this is the same idea via the same raw-REST-API approach the rest
     * of this class already uses. Returns null (not a broken link) on
     * failure so a caller can fall back gracefully.
     */
    public function createSignedUrl(string $bucket, string $path, int $expiresInSeconds = 3600): ?string
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/storage/v1/object/sign/{$bucket}/{$path}", [
                'expiresIn' => $expiresInSeconds,
            ]);

        if (! $response->successful()) {
            Log::warning('Supabase Storage sign failed', [
                'bucket' => $bucket,
                'path' => $path,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $signedPath = $response->json('signedURL');

        if (! is_string($signedPath) || $signedPath === '') {
            return null;
        }

        return "{$this->baseUrl}/storage/v1{$signedPath}";
    }

    /**
     * Bulk version of createSignedUrl() — signs many paths in ONE
     * request instead of one round trip per path. Matters here: on
     * this project's connection to Supabase, a single sign call
     * measures roughly 1 SECOND — calling createSignedUrl() once per
     * attachment per message (the original implementation) meant a
     * conversation thread with several image messages took several
     * real seconds just for signing, every time it loaded. Callers
     * should always prefer this over a loop of createSignedUrl() calls
     * whenever there's more than one path to resolve.
     *
     * @param  array<int, string>  $paths
     * @return array<string, string>  path => signed url (entries the
     *                                 server couldn't sign are simply
     *                                 absent, not present with a null)
     */
    public function createSignedUrls(string $bucket, array $paths, int $expiresInSeconds = 3600): array
    {
        $paths = array_values(array_unique(array_filter($paths, fn ($p) => is_string($p) && $p !== '')));

        if (! $paths) {
            return [];
        }

        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/storage/v1/object/sign/{$bucket}", [
                'expiresIn' => $expiresInSeconds,
                'paths' => $paths,
            ]);

        if (! $response->successful()) {
            Log::warning('Supabase Storage bulk sign failed', [
                'bucket' => $bucket,
                'count' => count($paths),
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $result = [];

        foreach ((array) $response->json() as $entry) {
            $path = $entry['path'] ?? null;
            $signedPath = $entry['signedURL'] ?? null;

            if (is_string($path) && is_string($signedPath) && $signedPath !== '') {
                $result[$path] = "{$this->baseUrl}/storage/v1{$signedPath}";
            }
        }

        return $result;
    }

    /**
     * Bulk-deletes objects. Best-effort: logs and swallows failures
     * rather than throwing — a stuck orphan file is a cleanup nuisance,
     * never worth failing the seller's actual save/delete action over.
     */
    public function delete(string $bucket, array $paths): void
    {
        $paths = array_values(array_filter($paths));

        if (! $paths) {
            return;
        }

        $response = Http::withHeaders($this->headers())
            ->delete("{$this->baseUrl}/storage/v1/object/{$bucket}", [
                'prefixes' => $paths,
            ]);

        if (! $response->successful()) {
            Log::warning('Supabase Storage delete failed (non-fatal)', [
                'bucket' => $bucket,
                'paths' => $paths,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }

    /**
     * Decodes a `data:<mime>;base64,<data>` string. Returns null for
     * anything else (already a real URL, malformed, etc.) so callers
     * can tell "nothing to migrate" apart from "migration failed".
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
