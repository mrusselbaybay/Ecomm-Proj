<?php

namespace App\Http\Middleware;

use App\Models\Profile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * The seller/logistics SPA authenticates entirely client-side against
 * Supabase Auth (see resources/js/seller/composables/useSeller.js) — there
 * is no Laravel session for these areas. To let Laravel API endpoints
 * (e.g. Seller Order routes) know *who* is calling, the frontend must send
 * the Supabase access token as a Bearer header, and we verify it the same
 * way AuthController::user() already does: forward it to Supabase's
 * /auth/v1/user endpoint.
 *
 * On success, the matching public.profiles row is resolved and bound as
 * the request's authenticated user via setUserResolver(), so downstream
 * code can keep using the existing $request->user() convention (see
 * EnsureUserIsAdmin for the precedent this mirrors).
 */
class AuthenticateSupabaseUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $response = Http::withHeaders([
            'apikey' => config('services.supabase.anon_key'),
            'Authorization' => 'Bearer ' . $token,
        ])->get(config('services.supabase.url') . '/auth/v1/user');

        if (!$response->successful()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $authUserId = $response->json('id');
        $profile = $authUserId ? Profile::find($authUserId) : null;

        if (!$profile) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->setUserResolver(fn () => $profile);

        return $next($request);
    }
}
