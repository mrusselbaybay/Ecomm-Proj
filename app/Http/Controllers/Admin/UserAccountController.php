<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAccountStatusRequest;
use App\Mail\AccountStatusChanged;
use App\Models\Document;
use App\Models\Profile;
use App\Models\StatusAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class UserAccountController extends Controller
{
    private const ACTION_TO_STATUS = [
        'activate' => 'active',
        'suspend' => 'suspended',
        'deactivate' => 'deactivated',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Profile::query()
            ->where('status', 'approved')
            ->with(['sellerDetail', 'courierDetail', 'driverDetail']);

        if ($role = $request->string('role')->toString()) {
            $query->where('role', $role);
        }

        if ($accountStatus = $request->string('account_status')->toString()) {
            $query->where('account_status', $accountStatus);
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($query) use ($search): void {
                $query->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('contact_no', 'ilike', "%{$search}%");
            });
        }

        $users = $query
            ->orderBy('last_name')
            ->paginate(5)
            ->withQueryString()
            ->through(fn (Profile $profile): array => $this->accountData($profile));

        // One conditional-aggregation query replaces four separate COUNT(*)
        // scans over the same approved-accounts set.
        $summary = Profile::query()
            ->where('status', 'approved')
            ->selectRaw(
                'count(*) as total, '
                ."coalesce(sum(case when account_status = 'active' then 1 else 0 end), 0) as active, "
                ."coalesce(sum(case when account_status = 'suspended' then 1 else 0 end), 0) as suspended, "
                ."coalesce(sum(case when account_status = 'deactivated' then 1 else 0 end), 0) as deactivated",
            )
            ->first();

        return response()->json([
            'accounts' => $users,
            'summary' => [
                'total' => (int) $summary->total,
                'active' => (int) $summary->active,
                'suspended' => (int) $summary->suspended,
                'deactivated' => (int) $summary->deactivated,
            ],
        ]);
    }

    public function show(Profile $profile): JsonResponse
    {
        $profile->load([
            'address',
            'sellerDetail',
            'courierDetail.logisticsCompany',
            'driverDetail.logisticsCompany',
            'documents',
            'statusAuditLogs.changedBy',
        ]);

        return response()->json([
            'account' => $this->accountData($profile, true),
        ]);
    }

    public function updateStatus(
        UpdateAccountStatusRequest $request,
        Profile $profile,
    ): JsonResponse {
        $action = $request->validated('action');
        $reason = $request->validated('reason');
        $newStatus = self::ACTION_TO_STATUS[$action];

        if ($profile->id === $request->user()->id) {
            return response()->json([
                'message' => "You can't change your own admin account status.",
            ], 422);
        }

        if ($profile->status !== 'approved') {
            return response()->json([
                'message' => 'Only approved accounts can have their access status changed.',
            ], 422);
        }

        if ($profile->account_status === $newStatus) {
            return response()->json([
                'message' => "This account is already {$newStatus}.",
            ], 422);
        }

        DB::transaction(function () use (
            $request,
            $profile,
            $newStatus,
            $reason,
            $action,
        ): void {
            $oldStatus = $profile->account_status;

            $profile->update(['account_status' => $newStatus]);

            StatusAuditLog::create([
                'entity_type' => 'profile',
                'entity_id' => $profile->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'reason' => $reason ?: ucfirst($action).'d by admin',
                'changed_by' => $request->user()->id,
            ]);
        });

        Mail::to($profile->email)->queue(
            new AccountStatusChanged(
                $profile->full_name,
                $newStatus,
                $reason,
            ),
        );

        return response()->json([
            'message' => "{$profile->full_name}'s account is now {$newStatus}. An email notification was queued.",
            'account_status' => $newStatus,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function accountData(Profile $profile, bool $includeDetails = false): array
    {
        $account = [
            'id' => $profile->id,
            'full_name' => $profile->full_name,
            'first_name' => $profile->first_name,
            'last_name' => $profile->last_name,
            'middle_initial' => $profile->middle_initial,
            'email' => $profile->email,
            'contact_no' => $profile->contact_no,
            'birthday' => $profile->birthday?->toDateString(),
            'sex' => $profile->sex,
            'role' => $profile->role,
            'status' => $profile->status,
            'account_status' => $profile->account_status,
            'created_at' => $profile->created_at?->toIso8601String(),
            'seller_detail' => $profile->sellerDetail?->toArray(),
            'courier_detail' => $profile->courierDetail?->toArray(),
            'driver_detail' => $profile->driverDetail?->toArray(),
        ];

        if (! $includeDetails) {
            return $account;
        }

        return [
            ...$account,
            'address' => $profile->address ? [
                ...$profile->address->toArray(),
                'full_address' => $profile->address->full_address,
            ] : null,
            'documents' => $profile->documents
                ->map(fn (Document $document): array => [
                    'id' => $document->id,
                    'doc_type' => $document->doc_type,
                    'storage_path' => $document->storage_path,
                    'status' => $document->status,
                    'reviewed_at' => $document->reviewed_at?->toIso8601String(),
                ])
                ->values(),
            'status_history' => $profile->statusAuditLogs
                ->map(fn (StatusAuditLog $log): array => [
                    'id' => $log->getKey(),
                    'old_status' => $log->old_status,
                    'new_status' => $log->new_status,
                    'reason' => $log->reason,
                    'created_at' => $log->created_at?->toIso8601String(),
                    'changed_by' => $log->changedBy?->full_name,
                ])
                ->values(),
        ];
    }
}
