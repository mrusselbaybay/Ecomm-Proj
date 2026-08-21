<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\StatusAuditLog;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $registrableProfiles = Profile::query()
            ->whereIn('role', Profile::REGISTRABLE_ROLES);

        return response()->json([
            'total_users' => Profile::query()->count(),
            'active_sellers' => Profile::query()
                ->where('role', 'seller')
                ->where('status', 'approved')
                ->where('account_status', 'active')
                ->count(),
            'pending_registrations' => (clone $registrableProfiles)
                ->where('status', 'pending')
                ->count(),
            'open_complaints' => 0,
        ]);
    }

    public function notifications(): JsonResponse
    {
        $notifications = StatusAuditLog::query()
            ->with('changedBy:id,first_name,last_name')
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(function (StatusAuditLog $log): array {
                $actor = $log->changedBy?->full_name ?? 'An administrator';
                $status = str_replace('_', ' ', $log->new_status);

                return [
                    'id' => $log->getKey(),
                    'text' => "{$actor} changed a {$log->entity_type} status to {$status}.",
                    'time' => $log->created_at?->diffForHumans() ?? 'Recently',
                ];
            })
            ->values();

        return response()->json($notifications);
    }
}
