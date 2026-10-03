<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DocumentReviewRequest;
use App\Http\Requests\Admin\RejectRegistrationRequest;
use App\Mail\RegistrationApproved;
use App\Mail\RegistrationRejected;
use App\Models\Document;
use App\Models\Profile;
use App\Models\SellerDetail;
use App\Models\StatusAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AccountRegistrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $roles = $request->user()->managedRegistrableRoles();

        $query = Profile::query()
            ->whereIn('role', $roles)
            ->where(function ($query): void {
                $query->whereIn('status', ['pending', 'rejected'])
                    ->orWhere(fn ($query) => $this->sellerApplications($query, ['pending', 'rejected']));
            })
            ->with([
                'address', 'sellerDetail', 'courierDetail.logisticsCompany',
                'driverDetail.logisticsCompany', 'documents',
                'logisticsCompany.address', 'logisticsCompany.documents',
            ]);

        // A buyer's seller application is listed as a "seller" application.
        if ($role = $request->string('role')->toString()) {
            $query->where(function ($query) use ($role): void {
                $query->where(fn ($query) => $query->where('role', $role)->where('status', '!=', 'approved'));

                if ($role === 'seller') {
                    $query->orWhere(fn ($query) => $this->sellerApplications($query, ['pending', 'rejected']));
                }
            });
        }

        if ($status = $request->string('status')->toString()) {
            $query->where(function ($query) use ($status): void {
                $query->where('status', $status)
                    ->orWhere(fn ($query) => $this->sellerApplications($query, [$status]));
            });
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($query) use ($search): void {
                $query->whereLike('first_name', "%{$search}%")
                    ->orWhereLike('last_name', "%{$search}%")
                    ->orWhereLike('email', "%{$search}%");
            });
        }

        $applications = $query
            ->orderByDesc('created_at')
            ->paginate(5)
            ->withQueryString()
            ->through(fn (Profile $profile): array => $this->applicationData($profile));

        // One conditional-aggregation query replaces two separate COUNT(*)
        // scans over the same registrable-roles set.
        $upgrade = "profiles.role = 'buyer' and profiles.status = 'approved' and seller_details.application_status";
        $counts = Profile::query()
            ->leftJoin('seller_details', 'seller_details.profile_id', '=', 'profiles.id')
            ->whereIn('profiles.role', $roles)
            ->selectRaw(
                "coalesce(sum(case when profiles.status = 'pending' or ({$upgrade} = 'pending') then 1 else 0 end), 0) as pending, "
                ."coalesce(sum(case when profiles.status = 'rejected' or ({$upgrade} = 'rejected') then 1 else 0 end), 0) as rejected",
            )
            ->first();

        return response()->json([
            'applications' => $applications,
            'counts' => [
                'pending' => (int) $counts->pending,
                'rejected' => (int) $counts->rejected,
            ],
        ]);
    }

    public function show(Request $request, Profile $profile): JsonResponse
    {
        $this->ensureRegistrable($request, $profile);

        $profile->load([
            'address',
            'sellerDetail',
            'courierDetail.logisticsCompany',
            'driverDetail.logisticsCompany',
            'documents.reviewer',
            'logisticsCompany.address',
            'logisticsCompany.documents.reviewer',
        ]);

        return response()->json([
            'application' => $this->applicationData($profile),
        ]);
    }

    public function approve(Request $request, Profile $profile): JsonResponse
    {
        $this->ensureRegistrable($request, $profile);

        if ($profile->hasSellerApplication()) {
            return $this->approveSellerApplication($request, $profile);
        }

        DB::transaction(function () use ($request, $profile): void {
            $oldStatus = $profile->account_status;

            $profile->update([
                'status' => 'approved',
                'account_status' => 'active',
            ]);

            // Logistics: the reviewable documents (and the row that gates
            // portal access) belong to the company, not the owner profile.
            $company = $profile->role === 'logistics' ? $profile->logisticsCompany : null;

            if ($company) {
                $company->update([
                    'status' => 'approved',
                    'account_status' => 'active',
                ]);
                $company->documents()->where('status', 'pending')->update([
                    'status' => 'approved',
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                ]);
            } else {
                $profile->documents()->where('status', 'pending')->update([
                    'status' => 'approved',
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                ]);
            }

            StatusAuditLog::create([
                'entity_type' => 'profile',
                'entity_id' => $profile->id,
                'old_status' => $oldStatus,
                'new_status' => 'active',
                'reason' => 'Registration approved by admin',
                'changed_by' => $request->user()->id,
            ]);
        });

        Mail::to($profile->email)->queue(
            new RegistrationApproved($profile->full_name),
        );

        return response()->json([
            'message' => "{$profile->full_name}'s application was approved. An email notification was queued.",
        ]);
    }

    public function reject(
        RejectRegistrationRequest $request,
        Profile $profile,
    ): JsonResponse {
        $this->ensureRegistrable($request, $profile);

        $reason = $request->validated('reason');

        if ($profile->hasSellerApplication()) {
            return $this->rejectSellerApplication($request, $profile, $reason);
        }

        DB::transaction(function () use ($request, $profile, $reason): void {
            $oldStatus = $profile->account_status;

            $profile->update([
                'status' => 'rejected',
                'account_status' => 'deactivated',
            ]);

            $company = $profile->role === 'logistics' ? $profile->logisticsCompany : null;

            if ($company) {
                $company->update([
                    'status' => 'rejected',
                    'account_status' => 'deactivated',
                ]);
            }

            StatusAuditLog::create([
                'entity_type' => 'profile',
                'entity_id' => $profile->id,
                'old_status' => $oldStatus,
                'new_status' => 'deactivated',
                'reason' => "Registration rejected: {$reason}",
                'changed_by' => $request->user()->id,
            ]);
        });

        Mail::to($profile->email)->queue(
            new RegistrationRejected($profile->full_name, $reason),
        );

        return response()->json([
            'message' => "{$profile->full_name}'s application was rejected. An email notification was queued.",
        ]);
    }

    public function reviewDocument(
        DocumentReviewRequest $request,
        Document $document,
    ): JsonResponse {
        // Company documents belong to a logistics owner; personal ones to their profile.
        $ownerRole = $document->logistics_company_id
            ? 'logistics'
            : Profile::query()->whereKey($document->profile_id)->value('role');
        abort_unless(in_array($ownerRole, $request->user()->managedRoles(), true), 404);

        $document->update([
            'status' => $request->validated('status'),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Document review updated.',
        ]);
    }

    /**
     * Approve an existing buyer's seller application: grants the seller
     * capability only. The account stays active and keeps its current
     * (buyer) role until the user switches accounts themselves.
     */
    private function approveSellerApplication(Request $request, Profile $profile): JsonResponse
    {
        DB::transaction(function () use ($request, $profile): void {
            $profile->sellerDetail->update([
                'application_status' => SellerDetail::APPLICATION_APPROVED,
                'application_reason' => null,
            ]);

            $profile->documents()->where('status', 'pending')->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            StatusAuditLog::create([
                'entity_type' => 'profile',
                'entity_id' => $profile->id,
                'old_status' => $profile->account_status,
                'new_status' => $profile->account_status,
                'reason' => 'Seller application approved by admin',
                'changed_by' => $request->user()->id,
            ]);
        });

        Mail::to($profile->email)->queue(new RegistrationApproved($profile->full_name));

        return response()->json([
            'message' => "{$profile->full_name}'s seller application was approved. An email notification was queued.",
        ]);
    }

    /** Refuse the seller application without touching the buyer account. */
    private function rejectSellerApplication(Request $request, Profile $profile, string $reason): JsonResponse
    {
        DB::transaction(function () use ($request, $profile, $reason): void {
            $profile->sellerDetail->update([
                'application_status' => SellerDetail::APPLICATION_REJECTED,
                'application_reason' => $reason,
            ]);

            StatusAuditLog::create([
                'entity_type' => 'profile',
                'entity_id' => $profile->id,
                'old_status' => $profile->account_status,
                'new_status' => $profile->account_status,
                'reason' => "Seller application rejected: {$reason}",
                'changed_by' => $request->user()->id,
            ]);
        });

        Mail::to($profile->email)->queue(new RegistrationRejected($profile->full_name, $reason));

        return response()->json([
            'message' => "{$profile->full_name}'s seller application was rejected. An email notification was queued.",
        ]);
    }

    /** Approved buyers whose seller application is in one of $statuses. */
    private function sellerApplications($query, array $statuses)
    {
        return $query->where('role', 'buyer')
            ->where('status', 'approved')
            ->whereHas('sellerDetail', fn ($query) => $query->whereIn('application_status', $statuses));
    }

    private function ensureRegistrable(Request $request, Profile $profile): void
    {
        // Accounts outside this admin's scope are invisible to it.
        abort_unless(in_array($profile->role, $request->user()->managedRoles(), true), 404);

        if (! in_array($profile->role, Profile::REGISTRABLE_ROLES, true)) {
            throw ValidationException::withMessages([
                'profile' => 'This profile does not use the registration review workflow.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationData(Profile $profile): array
    {
        // A logistics registration is reviewed at the company level: its
        // address, documents and contact details hang off logistics_companies
        // / the company-scoped address+document rows, not the owner profile.
        $company = $profile->role === 'logistics' ? $profile->logisticsCompany : null;
        $address = $company ? $company->address : $profile->address;
        $documents = $company ? $company->documents : $profile->documents;
        $sellerApplication = $profile->hasSellerApplication();

        return [
            'id' => $profile->id,
            'full_name' => $profile->full_name,
            'first_name' => $profile->first_name,
            'last_name' => $profile->last_name,
            'middle_initial' => $profile->middle_initial,
            'email' => $company?->company_email ?? $profile->email,
            'contact_no' => $company?->company_contact_no ?? $profile->contact_no,
            'birthday' => $profile->birthday?->toDateString(),
            'sex' => $profile->sex,
            // A buyer applying to sell is reviewed as a seller application.
            'role' => $sellerApplication ? 'seller' : $profile->role,
            'status' => $sellerApplication ? $profile->sellerDetail->application_status : $profile->status,
            'application_type' => $sellerApplication ? 'seller_upgrade' : 'registration',
            'rejection_reason' => $sellerApplication ? $profile->sellerDetail->application_reason : null,
            'account_status' => $profile->account_status,
            'created_at' => $profile->created_at?->toIso8601String(),
            'address' => $address ? [
                ...$address->toArray(),
                'full_address' => $address->full_address,
            ] : null,
            'seller_detail' => $profile->sellerDetail?->toArray(),
            'courier_detail' => $profile->courierDetail?->toArray(),
            'driver_detail' => $profile->driverDetail?->toArray(),
            'company' => $company ? [
                'id' => $company->id,
                'company_name' => $company->company_name,
                'company_email' => $company->company_email,
                'company_contact_no' => $company->company_contact_no,
                'tin' => $company->tin,
                'sec_registration' => $company->sec_registration,
                'region' => $company->region,
                'status' => $company->status,
            ] : null,
            'documents' => $documents->map(fn (Document $document): array => [
                'id' => $document->id,
                'doc_type' => $document->doc_type,
                'id_type' => $document->id_type,
                'storage_path' => $document->storage_path,
                'status' => $document->status,
                'reviewed_at' => $document->reviewed_at?->toIso8601String(),
                'reviewer' => $document->reviewer ? [
                    'id' => $document->reviewer->id,
                    'full_name' => $document->reviewer->full_name,
                ] : null,
            ])->values(),
        ];
    }
}
