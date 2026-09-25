<?php

namespace App\Http\Middleware;

use App\Models\LogisticsAdminDetail;
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
            abort(403, 'Logistics accounts only.');
        }

        if ($profile->status !== 'approved' || $profile->account_status !== 'active') {
            abort(403, 'Your logistics account is not active.');
        }

        // Owners have no membership row; team members do.
        $membership = LogisticsAdminDetail::query()
            ->where('profile_id', $profile->id)
            ->first(['profile_id', 'logistics_company_id', 'role', 'status']);

        if ($membership?->status === 'suspended') {
            abort(403, 'Your access to this company has been suspended.');
        }

        if ($membership?->role === 'viewer'
            && ! $request->isMethodSafe()
            && ! $request->routeIs('logistics.team.leave')) {
            abort(403, 'Your role has view-only access.');
        }

        return $next($request);
    }
}
