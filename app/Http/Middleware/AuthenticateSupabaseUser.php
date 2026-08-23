<?php

namespace App\Http\Middleware;

use App\Models\Profile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the Supabase access token sent as `Authorization: Bearer <token>`
 * by asking Supabase's GoTrue auth server who it belongs to (GET
 * /auth/v1/user), then resolves the matching public.profiles row and makes
 * it available as $request->user() for the rest of the request — the same
 * way EnsureUserIsAdmin/EnsureUserIsSeller and the Seller/Buyer controllers
 * already expect.
 *
 * We deliberately verify against Supabase's own endpoint rather than
 * decoding the JWT locally: the project only has the anon key configured
 * (no SUPABASE_JWT_SECRET), and asking Supabase directly means a revoked/
 * expired/tampered token is rejected the same way Supabase itself would
 * reject it, with no extra secret to manage.
 *
 * The verification result is cached briefly per-token so a page that fires
 * several API calls in a row doesn't round-trip to Supabase for each one.
 */
class AuthenticateSupabaseUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->bearerToken($request);

        if (!$token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $supabaseUserId = $this->resolveSupabaseUserId($token);

        if (!$supabaseUserId) {
            return response()->json(['message' => 'Invalid or expired session.'], 401);
        }

        $profile = Profile::find($supabaseUserId);

        if (!$profile) {
            return response()->json(['message' => 'No profile found for this account.'], 401);
        }

        $request->setUserResolver(fn () => $profile);

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }

    /**
     * Returns the Supabase auth.users id the token belongs to, or null if
     * the token is missing/invalid/expired. Cached for a short window
     * keyed by a hash of the token (never the raw token) so we don't log
     * or store the credential itself.
     */
    private function resolveSupabaseUserId(string $token): ?string
    {
        $cacheKey = 'supabase_auth_token:' . hash('sha256', $token);

        return Cache::remember($cacheKey, now()->addSeconds(30), function () use ($token) {
            $url = rtrim((string) config('services.supabase.url'), '/') . '/auth/v1/user';
            $anonKey = config('services.supabase.anon_key');

            if (!$url || !$anonKey) {
                return null;
            }

            try {
                $response = Http::withHeaders([
                    'apikey' => $anonKey,
                    'Authorization' => "Bearer {$token}",
                ])->timeout(5)->get($url);
            } catch (\Throwable $e) {
                report($e);

                return null;
            }

            if (!$response->successful()) {
                return null;
            }

            return $response->json('id');
        }) ?: null;
    }
}