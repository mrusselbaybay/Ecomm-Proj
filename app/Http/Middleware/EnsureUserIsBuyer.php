<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Must run after 'supabase.auth' (see routes/buyer.php), which resolves
 * $request->user() to a public.profiles row. Requires that profile to be
 * an approved, active buyer.
 */
class EnsureUserIsBuyer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->role !== 'buyer') {
            abort(403, 'Buyers only.');
        }

        if ($user->status !== 'approved' || $user->account_status !== 'active') {
            abort(403, 'Your account is not active.');
        }

        return $next($request);
    }
}