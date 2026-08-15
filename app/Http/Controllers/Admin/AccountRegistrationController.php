<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DocumentReviewRequest;
use App\Http\Requests\Admin\RejectRegistrationRequest;
use App\Mail\RegistrationApproved;
use App\Mail\RegistrationRejected;
use App\Models\Document;
use App\Models\Profile;
use App\Models\StatusAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class AccountRegistrationController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Profile::query()
            ->whereIn('role', Profile::REGISTRABLE_ROLES)
            ->whereIn('status', ['pending', 'rejected'])
            ->with(['address', 'sellerDetail', 'courierDetail', 'documents']);

        if ($role = $request->string('role')->toString()) {
            $query->where('role', $role);
        }

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $applications = $query->orderByDesc('created_at')->paginate(15)->withQueryString();

        return Inertia::render('Admin/AccountRegistrations/Index', [
            'applications' => $applications,
            'filters' => $request->only(['role', 'status', 'search']),
            'counts' => [
                'pending' => Profile::whereIn('role', Profile::REGISTRABLE_ROLES)->where('status', 'pending')->count(),
                'rejected' => Profile::whereIn('role', Profile::REGISTRABLE_ROLES)->where('status', 'rejected')->count(),
            ],
        ]);
    }

    public function show(Profile $profile): Response
    {
        $profile->load(['address', 'sellerDetail', 'courierDetail', 'documents.reviewer']);

        return Inertia::render('Admin/AccountRegistrations/Show', [
            'application' => $profile,
        ]);
    }

    public function approve(Profile $profile): RedirectResponse
    {
        abort_unless($profile->role !== 'admin', 403);

        DB::transaction(function () use ($profile) {
            $oldStatus = $profile->account_status;

            $profile->update([
                'status' => 'approved',
                'account_status' => 'active',
            ]);

            // Approve any documents still pending review.
            $profile->documents()->where('status', 'pending')->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            StatusAuditLog::create([
                'entity_type' => 'profile',
                'entity_id' => $profile->id,
                'old_status' => $oldStatus,
                'new_status' => 'active',
                'reason' => 'Registration approved by admin',
                'changed_by' => auth()->id(),
            ]);
        });

        Mail::to($profile->email)->queue(new RegistrationApproved($profile->fresh()));

        return back()->with('success', "{$profile->full_name}'s application was approved and notified by email.");
    }

    public function reject(RejectRegistrationRequest $request, Profile $profile): RedirectResponse
    {
        $reason = $request->validated('reason');

        DB::transaction(function () use ($profile, $reason) {
            $oldStatus = $profile->account_status;

            $profile->update(['status' => 'rejected']);

            StatusAuditLog::create([
                'entity_type' => 'profile',
                'entity_id' => $profile->id,
                'old_status' => $oldStatus,
                'new_status' => $profile->account_status,
                'reason' => "Registration rejected: {$reason}",
                'changed_by' => auth()->id(),
            ]);
        });

        Mail::to($profile->email)->queue(new RegistrationRejected($profile->fresh(), $reason));

        return back()->with('success', "{$profile->full_name}'s application was rejected and notified by email.");
    }

    public function reviewDocument(DocumentReviewRequest $request, Document $document): RedirectResponse
    {
        $document->update([
            'status' => $request->validated('status'),
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Document review updated.');
    }
}