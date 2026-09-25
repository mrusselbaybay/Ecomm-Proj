<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\LogisticsCompany;
use App\Models\Profile;
use App\Models\StatusAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $admin = $request->user();
        $isLogistics = $admin->isLogisticsAdmin();
        $managedRoles = $admin->managedRoles();
        $registrableRoles = $admin->managedRegistrableRoles();

        // A single conditional-aggregation query replaces three separate
        // COUNT(*) scans over profiles (total / active primary accounts /
        // pending registrations) — this endpoint is hit on every dashboard
        // visit and tab switch back to it, so the round-trips add up.
        $roleBindings = implode(',', array_fill(0, count($registrableRoles), '?'));

        $profileCounts = Profile::query()
            ->whereIn('role', $managedRoles)
            ->selectRaw(
                'count(*) as total_users, '
                .'coalesce(sum(case when role = ? and status = ? and account_status = ? then 1 else 0 end), 0) as active_primary, '
                ."coalesce(sum(case when role in ({$roleBindings}) and status = ? then 1 else 0 end), 0) as pending_registrations",
                [$isLogistics ? 'courier' : 'seller', 'approved', 'active', ...$registrableRoles, 'pending'],
            )
            ->first();

        $stats = [
            'total_users' => (int) $profileCounts->total_users,
            'pending_registrations' => (int) $profileCounts->pending_registrations,
        ];

        if ($isLogistics) {
            $stats['active_couriers'] = (int) $profileCounts->active_primary;
            $stats['active_logistics_companies'] = LogisticsCompany::query()
                ->whereHas('owner', fn ($query) => $query
                    ->where('status', 'approved')
                    ->where('account_status', 'active'))
                ->count();
        } else {
            $stats['active_sellers'] = (int) $profileCounts->active_primary;
            $stats['open_complaints'] = Complaint::query()
                ->whereNotIn('status', ['resolved', 'dismissed'])
                ->count();
        }

        return response()->json($stats);
    }

    public function notifications(Request $request): JsonResponse
    {
        // Only status changes on accounts this admin manages.
        $managedIds = Profile::query()
            ->whereIn('role', $request->user()->managedRoles())
            ->select('id');

        $notifications = StatusAuditLog::query()
            ->where('entity_type', 'profile')
            ->whereIn('entity_id', $managedIds)
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
