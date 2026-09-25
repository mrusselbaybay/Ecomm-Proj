<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Mail\Logistics\TeamInvitation;
use App\Models\LogisticsAdminDetail;
use App\Models\LogisticsCompany;
use App\Models\LogisticsInvitation;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The logistics portal's Team page: roster, invitations, and membership
 * changes. The owner is implicit (logistics_companies.owner_profile_id);
 * everyone else is a logistics_admin_details row.
 *
 * Permissions: owner manages everyone; admins manage manager/operator/
 * viewer. Nobody changes their own membership here — that's leave().
 */
class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$company, $actorRole] = $this->context($request);

        $owner = Profile::query()->find($company->owner_profile_id, ['id', 'first_name', 'last_name', 'email', 'avatar_path', 'last_active_at']);

        $members = LogisticsAdminDetail::query()
            ->where('logistics_company_id', $company->id)
            ->with('profile:id,first_name,last_name,email,avatar_path,last_active_at')
            ->orderBy('created_at')
            ->get()
            ->map(fn (LogisticsAdminDetail $m) => $this->presentMember($m->profile, $m->role, $m->status, $m->created_at));

        $canManage = in_array($actorRole, LogisticsAdminDetail::TEAM_MANAGER_ROLES, true);

        $invitations = $canManage
            ? $company->invitations()
                ->where('status', 'pending')
                ->with('inviter:id,first_name,last_name')
                ->latest()
                ->get()
                ->map(fn (LogisticsInvitation $i) => $this->presentInvitation($i))
            : collect();

        return response()->json([
            'me' => [
                'id' => $request->user()->id,
                'role' => $actorRole,
                'can_manage' => $canManage,
                'assignable_roles' => $this->assignableRoles($actorRole),
            ],
            'members' => collect([$this->presentMember($owner, LogisticsAdminDetail::ROLE_OWNER, 'active', $company->created_at)])
                ->concat($members)
                ->values(),
            'invitations' => $invitations->values(),
        ]);
    }

    public function invite(Request $request): JsonResponse
    {
        [$company, $actorRole] = $this->context($request, manage: true);

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in($this->assignableRoles($actorRole))],
        ]);

        $email = Str::lower(trim($data['email']));

        $this->assertEmailCanJoin($company, $email);

        $existing = $company->invitations()
            ->where('status', 'pending')
            ->whereRaw('lower(email) = ?', [$email])
            ->first();

        $token = Str::random(64);
        $attributes = [
            'role' => $data['role'],
            'token_hash' => LogisticsInvitation::hashToken($token),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(LogisticsInvitation::TTL_DAYS),
            'renewal_requested_at' => null,
        ];

        $invitation = $existing
            ? tap($existing)->update($attributes)
            : $company->invitations()->create($attributes + ['email' => $email, 'status' => 'pending']);

        $this->sendInvitation($invitation, $token, $company, $request->user());

        return response()->json([
            'message' => $existing
                ? "Invitation re-sent to {$email} as ".ucfirst($data['role']).'.'
                : "Invitation sent to {$email}.",
            'invitation' => $this->presentInvitation($invitation->load('inviter:id,first_name,last_name')),
        ], $existing ? 200 : 201);
    }

    public function resendInvitation(Request $request, string $invitation): JsonResponse
    {
        [$company, $actorRole] = $this->context($request, manage: true);
        $invite = $this->pendingInvitation($company, $invitation);
        $this->assertCanManageRole($actorRole, $invite->role);

        $token = Str::random(64);
        $invite->update([
            'token_hash' => LogisticsInvitation::hashToken($token),
            'expires_at' => now()->addDays(LogisticsInvitation::TTL_DAYS),
            'invited_by' => $request->user()->id,
            'renewal_requested_at' => null,
        ]);

        $this->sendInvitation($invite, $token, $company, $request->user());

        return response()->json([
            'message' => "Invitation re-sent to {$invite->email}.",
            'invitation' => $this->presentInvitation($invite->load('inviter:id,first_name,last_name')),
        ]);
    }

    public function revokeInvitation(Request $request, string $invitation): JsonResponse
    {
        [$company, $actorRole] = $this->context($request, manage: true);
        $invite = $this->pendingInvitation($company, $invitation);
        $this->assertCanManageRole($actorRole, $invite->role);

        $invite->update(['status' => 'revoked']);

        return response()->json(['message' => "Invitation to {$invite->email} revoked."]);
    }

    public function updateRole(Request $request, string $profileId): JsonResponse
    {
        [$company, $actorRole] = $this->context($request, manage: true);
        $member = $this->manageableMember($request, $company, $actorRole, $profileId);

        $data = $request->validate([
            'role' => ['required', Rule::in($this->assignableRoles($actorRole))],
        ]);

        $member->update(['role' => $data['role'], 'updated_at' => now()]);

        return response()->json(['message' => 'Role updated.', 'role' => $member->role]);
    }

    public function suspend(Request $request, string $profileId): JsonResponse
    {
        return $this->setStatus($request, $profileId, 'suspended', 'Member suspended.');
    }

    public function reactivate(Request $request, string $profileId): JsonResponse
    {
        return $this->setStatus($request, $profileId, 'active', 'Member reactivated.');
    }

    public function remove(Request $request, string $profileId): JsonResponse
    {
        [$company, $actorRole] = $this->context($request, manage: true);
        $member = $this->manageableMember($request, $company, $actorRole, $profileId);

        $member->delete();

        return response()->json(['message' => 'Member removed from the team.']);
    }

    public function leave(Request $request): JsonResponse
    {
        [$company, $actorRole] = $this->context($request);

        if ($actorRole === LogisticsAdminDetail::ROLE_OWNER) {
            throw ValidationException::withMessages([
                'team' => 'Owners can\'t leave their company. Transfer ownership to another member first.',
            ]);
        }

        LogisticsAdminDetail::query()
            ->where('logistics_company_id', $company->id)
            ->where('profile_id', $request->user()->id)
            ->delete();

        return response()->json(['message' => "You left {$company->company_name}."]);
    }

    public function transferOwnership(Request $request): JsonResponse
    {
        [$company, $actorRole] = $this->context($request);

        abort_unless($actorRole === LogisticsAdminDetail::ROLE_OWNER, 403, 'Only the owner can transfer ownership.');

        $data = $request->validate(['profile_id' => ['required', 'uuid']]);

        DB::transaction(function () use ($company, $data, $request) {
            $target = LogisticsAdminDetail::query()
                ->where('logistics_company_id', $company->id)
                ->where('profile_id', $data['profile_id'])
                ->lockForUpdate()
                ->first();

            if (! $target || $target->status !== 'active') {
                throw ValidationException::withMessages([
                    'profile_id' => 'Ownership can only go to an active member of this team.',
                ]);
            }

            $target->delete();

            LogisticsCompany::query()->whereKey($company->id)->update([
                'owner_profile_id' => $target->profile_id,
                'updated_at' => now(),
            ]);

            // The previous owner stays on the team as an admin.
            LogisticsAdminDetail::query()->create([
                'profile_id' => $request->user()->id,
                'logistics_company_id' => $company->id,
                'role' => 'admin',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Ownership transferred. You are now an admin.']);
    }

    // ------------------------------------------------------------ helpers

    /** @return array{0: LogisticsCompany, 1: string} */
    private function context(Request $request, bool $manage = false): array
    {
        $profileId = $request->user()->id;

        $company = LogisticsCompany::query()
            ->forMember($profileId)
            ->where('status', 'approved')
            ->where('account_status', 'active')
            ->firstOrFail();

        $role = $company->owner_profile_id === $profileId
            ? LogisticsAdminDetail::ROLE_OWNER
            : LogisticsAdminDetail::query()->whereKey($profileId)->value('role');

        if ($manage && ! in_array($role, LogisticsAdminDetail::TEAM_MANAGER_ROLES, true)) {
            abort(403, 'Only owners and admins can manage the team.');
        }

        return [$company, $role];
    }

    /** @return list<string> */
    private function assignableRoles(string $actorRole): array
    {
        return match ($actorRole) {
            LogisticsAdminDetail::ROLE_OWNER => LogisticsAdminDetail::ROLES,
            'admin' => ['manager', 'operator', 'viewer'],
            default => [],
        };
    }

    private function assertCanManageRole(string $actorRole, string $targetRole): void
    {
        abort_unless(
            in_array($targetRole, $this->assignableRoles($actorRole), true),
            403,
            'Only the owner can manage admins.',
        );
    }

    private function manageableMember(Request $request, LogisticsCompany $company, string $actorRole, string $profileId): LogisticsAdminDetail
    {
        if ($profileId === $request->user()->id) {
            throw ValidationException::withMessages([
                'team' => 'You can\'t change your own membership. Use "Leave team" instead.',
            ]);
        }

        if ($profileId === $company->owner_profile_id) {
            abort(403, 'The owner can\'t be changed. Ownership must be transferred.');
        }

        $member = LogisticsAdminDetail::query()
            ->where('logistics_company_id', $company->id)
            ->where('profile_id', $profileId)
            ->firstOrFail();

        $this->assertCanManageRole($actorRole, $member->role);

        return $member;
    }

    private function setStatus(Request $request, string $profileId, string $status, string $message): JsonResponse
    {
        [$company, $actorRole] = $this->context($request, manage: true);
        $member = $this->manageableMember($request, $company, $actorRole, $profileId);

        $member->update(['status' => $status, 'updated_at' => now()]);

        return response()->json(['message' => $message, 'status' => $status]);
    }

    private function pendingInvitation(LogisticsCompany $company, string $id): LogisticsInvitation
    {
        return $company->invitations()->where('status', 'pending')->findOrFail($id);
    }

    /**
     * Rejects emails already on this team, owned by another company, or
     * registered to a non-logistics account (profiles carry one role).
     */
    private function assertEmailCanJoin(LogisticsCompany $company, string $email): void
    {
        $profile = Profile::query()->whereRaw('lower(email) = ?', [$email])->first(['id', 'role']);

        if (! $profile) {
            return;
        }

        $currentCompanyId = LogisticsCompany::query()
            ->where('owner_profile_id', $profile->id)
            ->value('id')
            ?? LogisticsAdminDetail::query()->whereKey($profile->id)->value('logistics_company_id');

        if ($currentCompanyId === $company->id) {
            throw ValidationException::withMessages(['email' => 'This person is already on your team.']);
        }

        if ($currentCompanyId) {
            throw ValidationException::withMessages(['email' => 'This email already belongs to another logistics company.']);
        }

        if ($profile->role !== 'logistics') {
            throw ValidationException::withMessages([
                'email' => "This email is registered as a {$profile->role} account. Ask them for a different email address.",
            ]);
        }
    }

    private function sendInvitation(LogisticsInvitation $invitation, string $token, LogisticsCompany $company, Profile $inviter): void
    {
        try {
            Mail::to($invitation->email)->send(new TeamInvitation(
                companyName: $company->company_name,
                inviterName: $inviter->full_name ?: 'Your team',
                roleLabel: ucfirst($invitation->role),
                acceptUrl: url('/accept-invite?token='.$token),
                ttlDays: LogisticsInvitation::TTL_DAYS,
            ));
        } catch (\Throwable $e) {
            report($e);

            abort(502, 'The invitation was saved, but the email could not be sent. Try "Resend" in a moment.');
        }
    }

    private function presentMember(?Profile $profile, string $role, string $status, $joinedAt): array
    {
        return [
            'id' => $profile?->id,
            'name' => $profile?->full_name ?: ($profile?->email ?? 'Unknown user'),
            'email' => $profile?->email,
            'avatar_url' => $profile?->avatar_url,
            'role' => $role,
            'status' => $status,
            'is_online' => $profile?->isOnline() ?? false,
            'joined_at' => $joinedAt?->toIso8601String(),
        ];
    }

    private function presentInvitation(LogisticsInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'is_expired' => $invitation->isExpired(),
            'renewal_requested' => (bool) $invitation->renewal_requested_at,
            'invited_by' => $invitation->inviter?->full_name,
            'created_at' => $invitation->created_at?->toIso8601String(),
        ];
    }
}
