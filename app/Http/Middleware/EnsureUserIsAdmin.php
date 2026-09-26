<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mirrors App\Http\Middleware\EnsureUserIsSeller: assumes a Profile has
 * already been resolved onto the request (see AuthenticateApiToken)
 * and checks the role. Also blocks admins whose account isn't active —
 * without this, a self-deactivated (or admin-suspended) admin account
 * would keep passing every admin-gated route since account_status was
 * never checked here.
 */
class EnsureUserIsAdmin
{
    /**
     * @param  string  ...$roles  Allowed admin roles (e.g. admin:logistics_admin).
     *                            Defaults to the platform admin.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $profile = $request->user();
        $roles = $roles ?: ['admin'];

        if (! $profile || ! in_array($profile->role, $roles, true)) {
            abort(403, 'Admins only.');
        }

        if ($profile->account_status !== 'active') {
            abort(403, 'Your admin account is not active.');
        }

        return $next($request);
    }
}
