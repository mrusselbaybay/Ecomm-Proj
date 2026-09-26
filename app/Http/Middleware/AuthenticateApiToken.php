<?php

namespace App\Http\Middleware;

use App\Models\Profile;
use App\Services\AuthSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the `Authorization: Bearer <token>` Sanctum token to its profile
 * and exposes it as $request->user(). A local database lookup — no call to
 * an external auth server.
 */
class AuthenticateApiToken
{
    public function __construct(private readonly AuthSession $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->bearerToken()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $profile = $this->sessions->resolve($request->bearerToken());

        if (! $profile) {
            return response()->json(['message' => 'Invalid or expired session.'], 401);
        }

        $request->setUserResolver(fn () => $profile);

        $this->touchActivity($profile);

        return $next($request);
    }

    /**
     * Powers the messaging "Online"/"Offline" indicator (Profile::isOnline()).
     * Throttled via cache to at most one write per profile per minute, and
     * written with the query builder so it doesn't bump updated_at.
     */
    private function touchActivity(Profile $profile): void
    {
        $cacheKey = 'profile_activity_touch:'.$profile->id;

        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, 60);

        Profile::where('id', $profile->id)->update(['last_active_at' => now()]);
    }
}
