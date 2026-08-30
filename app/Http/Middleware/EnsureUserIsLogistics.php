<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsLogistics
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $profile = $request->user();

        if (! $profile || $profile->role !== 'logistics') {
            abort(403, 'Logistics owners only.');
        }

        if ($profile->status !== 'approved' || $profile->account_status !== 'active') {
            abort(403, 'Your logistics account is not active.');
        }

        return $next($request);
    }
}
