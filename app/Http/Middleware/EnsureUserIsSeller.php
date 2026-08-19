<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mirrors App\Http\Middleware\EnsureUserIsAdmin: assumes a Profile has
 * already been resolved onto the request (see AuthenticateSupabaseUser)
 * and checks the role. Also blocks sellers whose account isn't active,
 * matching the account_status checks already enforced client-side at
 * login (resources/js/app.js::handleLogin).
 */
class EnsureUserIsSeller
{
    public function handle(Request $request, Closure $next): Response
    {
        $profile = $request->user();

        if (!$profile || $profile->role !== 'seller') {
            abort(403, 'Sellers only.');
        }

        if ($profile->account_status !== 'active') {
            abort(403, 'Your seller account is not active.');
        }

        return $next($request);
    }
}
