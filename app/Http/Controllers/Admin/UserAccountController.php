<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAccountStatusRequest;
use App\Mail\AccountStatusChanged;
use App\Models\Profile;
use App\Models\StatusAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class UserAccountController extends Controller
{
    private const ACTION_TO_STATUS = [
        'activate' => 'active',
        'suspend' => 'suspended',
        'deactivate' => 'deactivated',
    ];

    public function index(Request $request): Response
    {
        $query = Profile::query()
            ->where('status', 'approved')
            ->with(['sellerDetail', 'courierDetail']);

        if ($role = $request->string('role')->toString()) {
            $query->where('role', $role);
        }

        if ($accountStatus = $request->string('account_status')->toString()) {
            $query->where('account_status', $accountStatus);
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('contact_no', 'ilike', "%{$search}%");
            });
        }

        $users = $query->orderBy('last_name')->paginate(15)->withQueryString();

        return Inertia::render('Admin/UserAccounts/Index', [
            'users' => $users,
            'filters' => $request->only(['role', 'account_status', 'search']),
            'summary' => [
                'active' => Profile::where('status', 'approved')->where('account_status', 'active')->count(),
                'suspended' => Profile::where('status', 'approved')->where('account_status', 'suspended')->count(),
                'deactivated' => Profile::where('status', 'approved')->where('account_status', 'deactivated')->count(),
            ],
        ]);
    }

    public function show(Profile $profile): Response
    {
        $profile->load(['address', 'sellerDetail', 'courierDetail', 'documents', 'statusAuditLogs.changedBy']);

        return Inertia::render('Admin/UserAccounts/Show', [
            'account' => $profile,
        ]);
    }

    public function updateStatus(UpdateAccountStatusRequest $request, Profile $profile): RedirectResponse
    {
        $action = $request->validated('action');
        $reason = $request->validated('reason');
        $newStatus = self::ACTION_TO_STATUS[$action];

        if ($profile->id === auth()->id()) {
            return back()->with('error', "You can't change the status of your own admin account here.");
        }

        DB::transaction(function () use ($profile, $newStatus, $reason, $action) {
            $oldStatus = $profile->account_status;

            $profile->update(['account_status' => $newStatus]);

            StatusAuditLog::create([
                'entity_type' => 'profile',
                'entity_id' => $profile->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'reason' => $reason ?: ucfirst($action) . 'd by admin',
                'changed_by' => auth()->id(),
            ]);
        });

        Mail::to($profile->email)->queue(new AccountStatusChanged($profile->fresh(), $action, $reason));

        return back()->with('success', "{$profile->full_name}'s account was {$newStatus}.");
    }
}