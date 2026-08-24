<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Must run after 'supabase.auth' (see routes/driver.php), which resolves
 * $request->user() to a public.profiles row. Requires that profile to be
 * an approved, active driver — or courier, since courier and driver share
 * the same mobile app experience (the driver UI won out — see
 * DriverShell) and therefore the same self-service account settings.
 */
class EnsureUserIsDriver
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, ['driver', 'courier'], true)) {
            abort(403, 'Drivers only.');
        }

        if ($user->status !== 'approved' || $user->account_status !== 'active') {
            abort(403, 'Your account is not active.');
        }

        return $next($request);
    }
}
