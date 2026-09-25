<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side Supabase Auth admin calls (service role key).
 */
class SupabaseAdmin
{
    /** @return array{id: string} */
    public function createUser(string $email, string $password, array $metadata): array
    {
        $response = $this->client()->post($this->url('/auth/v1/admin/users'), [
            'email' => $email,
            'password' => $password,
            'email_confirm' => true,
            'user_metadata' => $metadata,
        ]);

        if (! $response->successful() || ! $response->json('id')) {
            Log::error('Supabase admin createUser failed', ['body' => $response->body()]);
            $error = $response->json() ?? [];
            throw new \RuntimeException($error['msg'] ?? $error['message'] ?? 'Failed to create user.');
        }

        return $response->json();
    }

    public function deleteUser(string $userId): void
    {
        try {
            $this->client()->delete($this->url('/auth/v1/admin/users/'.$userId));
        } catch (\Throwable $e) {
            Log::error('Failed to roll back Supabase auth user', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    private function client(): PendingRequest
    {
        $key = config('services.supabase.service_role_key');

        return Http::withHeaders(['apikey' => $key, 'Authorization' => 'Bearer '.$key])
            ->acceptJson()
            ->timeout(10);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.supabase.url'), '/').$path;
    }
}
