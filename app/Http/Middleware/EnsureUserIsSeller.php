<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Must run after 'supabase.auth' (see routes/seller.php), which resolves
 * $request->user() to a public.profiles row. Requires that profile to be
 * an approved, active seller — the same bar the admin approval workflow
 * (app/Http/Controllers/Admin/AccountRegistrationController.php) uses
 * before a seller account is usable.
 */
class EnsureUserIsSeller
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->role !== 'seller') {
            abort(403, 'Sellers only.');
        }

        if ($user->status !== 'approved' || $user->account_status !== 'active') {
            abort(403, 'Your seller account is not active.');
        }

        return $next($request);
    }
}