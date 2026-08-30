<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Profile;
use App\Models\StatusAuditLog;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        // A single conditional-aggregation query replaces three separate
        // COUNT(*) scans over profiles (total / active sellers / pending
        // registrations) — this endpoint is hit on every dashboard visit and
        // tab switch back to it, so the round-trips add up.
        $roleBindings = implode(',', array_fill(0, count(Profile::REGISTRABLE_ROLES), '?'));

        $profileCounts = Profile::query()
            ->selectRaw(
                'count(*) as total_users, '
                .'coalesce(sum(case when role = ? and status = ? and account_status = ? then 1 else 0 end), 0) as active_sellers, '
                ."coalesce(sum(case when role in ({$roleBindings}) and status = ? then 1 else 0 end), 0) as pending_registrations",
                ['seller', 'approved', 'active', ...Profile::REGISTRABLE_ROLES, 'pending'],
            )
            ->first();

        return response()->json([
            'total_users' => (int) $profileCounts->total_users,
            'active_sellers' => (int) $profileCounts->active_sellers,
            'pending_registrations' => (int) $profileCounts->pending_registrations,
            'open_complaints' => Complaint::query()
                ->whereNotIn('status', ['resolved', 'dismissed'])
                ->count(),
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
