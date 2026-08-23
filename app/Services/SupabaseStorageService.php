<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Talks to the Supabase Storage REST API using the service-role key so the
 * Laravel backend can read/write files without a user's Supabase Auth
 * session. Mirrors the pattern already used for Supabase Auth lookups (see
 * Api\Courier\CourierApplicationController::authenticatedProfile()).
 */
class SupabaseStorageService
{
    private const BUCKET = 'documents';

    private string $url;
    private string $serviceRoleKey;

    public function __construct()
    {
        $this->url = rtrim((string) config('services.supabase.url'), '/');
        $this->serviceRoleKey = (string) config('services.supabase.service_role_key');
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && $this->serviceRoleKey !== '';
    }

    /**
     * Upload a file into the "documents" bucket at the given path,
     * replacing any existing object there.
     */
    public function upload(UploadedFile $file, string $path): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Supabase storage is not configured (missing URL or service role key).');
        }

        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw new RuntimeException('Could not read the uploaded file.');
        }

        $response = Http::withHeaders([
            'apikey' => $this->serviceRoleKey,
            'Authorization' => "Bearer {$this->serviceRoleKey}",
            'x-upsert' => 'true',
        ])
            ->withBody($contents, $file->getMimeType() ?: 'application/octet-stream')
            ->post("{$this->url}/storage/v1/object/".self::BUCKET."/{$path}");

        if (! $response->successful()) {
            Log::error('Supabase storage upload failed', [
                'path' => $path,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException('Failed to upload file to Supabase storage.');
        }
    }

    /**
     * Create a short-lived signed URL for a private object in the
     * "documents" bucket. Returns null if the object can't be signed.
     */
    public function signedUrl(string $path, int $expiresInSeconds = 300): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $response = Http::withHeaders([
            'apikey' => $this->serviceRoleKey,
            'Authorization' => "Bearer {$this->serviceRoleKey}",
        ])->post("{$this->url}/storage/v1/object/sign/".self::BUCKET."/{$path}", [
            'expiresIn' => $expiresInSeconds,
        ]);

        if (! $response->successful()) {
            Log::error('Supabase storage sign failed', [
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

        return "{$this->url}/storage/v1{$signedPath}";
    }
}
