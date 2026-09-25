<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Mail\Logistics\InvitationRenewalRequested;
use App\Models\LogisticsAdminDetail;
use App\Models\LogisticsCompany;
use App\Models\LogisticsInvitation;
use App\Models\Profile;
use App\Services\SupabaseAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The /accept-invite page's API. The raw token is the credential; only its
 * sha256 is stored. show/acceptNew/requestRenewal are public, accept()
 * needs the invitee signed in (supabase.auth).
 */
class InvitationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $invitation = $this->find($token);
        $company = $invitation->company;

        return response()->json([
            'company_name' => $company->company_name,
            'role' => $invitation->role,
            'email' => $invitation->email,
            'state' => match (true) {
                $invitation->status === 'accepted' => 'accepted',
                $invitation->isExpired() => 'expired',
                default => 'pending',
            },
            'renewal_requested' => (bool) $invitation->renewal_requested_at,
            'account_exists' => $this->profileFor($invitation->email) !== null,
        ]);
    }

    /** New user: create the account and join in one step. */
    public function acceptNew(Request $request, string $token, SupabaseAdmin $supabase): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_initial' => ['nullable', 'string', 'max:1'],
            'last_name' => ['required', 'string', 'max:100'],
            'sex' => ['required', 'in:Male,Female'],
            'birthday' => ['required', 'date', 'before_or_equal:today'],

            // Same address shape as AuthController::registerUser().
            'region' => ['required', 'in:Luzon,Visayas,Mindanao'],
            'province_code' => ['required', 'string'],
            'province_name' => ['required', 'string'],
            'municipality_code' => ['required', 'string'],
            'municipality_name' => ['required', 'string'],
            'barangay' => ['required', 'string'],
            'street' => ['nullable', 'string', 'max:255'],
            'house_no' => ['nullable', 'string', 'max:50'],

            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $invitation = $this->usable($token);

        if ($this->profileFor($invitation->email)) {
            abort(409, 'An account with this email already exists. Sign in to accept the invitation.');
        }

        try {
            $user = $supabase->createUser($invitation->email, $data['password'], [
                'role' => 'logistics',
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'middle_initial' => $data['middle_initial'] ?? '',
                'sex' => $data['sex'],
                'birthday' => $data['birthday'],
                'status' => 'approved',
            ]);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        try {
            DB::transaction(function () use ($invitation, $user, $data) {
                // The auth.users trigger normally creates the profile from
                // metadata; this makes the approved/active state explicit.
                DB::table('profiles')->updateOrInsert(['id' => $user['id']], [
                    'email' => $invitation->email,
                    'role' => 'logistics',
                    'first_name' => $data['first_name'],
                    'middle_initial' => $data['middle_initial'] ?? null,
                    'last_name' => $data['last_name'],
                    'sex' => $data['sex'],
                    'birthday' => $data['birthday'],
                    'status' => 'approved',
                    'account_status' => 'active',
                    'updated_at' => now(),
                ]);

                DB::table('addresses')->insert([
                    'owner_kind' => 'profile',
                    'profile_id' => $user['id'],
                    'region_name' => $data['region'],
                    'province_code' => $data['province_code'],
                    'province_name' => $data['province_name'],
                    'municipality_code' => $data['municipality_code'],
                    'municipality_name' => $data['municipality_name'],
                    'barangay' => $data['barangay'],
                    'street' => $data['street'] ?? '',
                    'house_no' => $data['house_no'] ?? null,
                ]);

                $this->join($invitation, $user['id']);
            });
        } catch (\Throwable $e) {
            $supabase->deleteUser($user['id']);

            throw $e;
        }

        return response()->json(['message' => 'Account created.', 'email' => $invitation->email], 201);
    }

    /** Existing user, signed in as the invited email. */
    public function accept(Request $request, string $token): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();
        $invitation = $this->usable($token);

        if (Str::lower((string) $profile->email) !== Str::lower($invitation->email)) {
            abort(403, "This invitation was sent to {$invitation->email}. Sign in with that account to accept it.");
        }

        if ($profile->role !== 'logistics') {
            abort(422, "This email is registered as a {$profile->role} account and can't join a logistics team.");
        }

        $current = LogisticsCompany::query()->where('owner_profile_id', $profile->id)->first(['id', 'company_name'])
            ?? LogisticsAdminDetail::query()->whereKey($profile->id)->with('logisticsCompany:id,company_name')->first()?->logisticsCompany;

        if ($current && $current->id !== $invitation->logistics_company_id) {
            abort(409, "You already belong to {$current->company_name}. Leave that company (or transfer its ownership) before joining another.");
        }

        DB::transaction(function () use ($invitation, $profile, $current) {
            $current
                ? $this->markAccepted($invitation, $profile->id)
                : $this->join($invitation, $profile->id);

            Profile::query()->whereKey($profile->id)
                ->where('status', '!=', 'approved')
                ->update(['status' => 'approved']);
        });

        return response()->json(['message' => "You joined {$invitation->company->company_name}."]);
    }

    /** Expired link: ask the team's owner/admins for a fresh one. */
    public function requestRenewal(string $token): JsonResponse
    {
        $invitation = $this->find($token);

        abort_unless($invitation->status === 'pending' && $invitation->isExpired(), 422, 'This invitation doesn\'t need renewing.');

        if ($invitation->renewal_requested_at?->gt(now()->subDay())) {
            return response()->json(['message' => 'Request already sent. The team will send you a new link.']);
        }

        $company = $invitation->company;
        $recipients = Profile::query()
            ->whereKey($company->owner_profile_id)
            ->orWhereIn('id', $company->admins()->where('role', 'admin')->where('status', 'active')->select('profile_id'))
            ->pluck('email')
            ->filter()
            ->all();

        try {
            Mail::to($recipients)->send(new InvitationRenewalRequested(
                $company->company_name,
                $invitation->email,
                url('/logistics/team'),
            ));
        } catch (\Throwable $e) {
            report($e);
            abort(502, 'We couldn\'t reach the team right now. Please try again later.');
        }

        $invitation->update(['renewal_requested_at' => now()]);

        return response()->json(['message' => 'Request sent. The team will send you a new link.']);
    }

    // ------------------------------------------------------------ helpers

    private function find(string $token): LogisticsInvitation
    {
        $invitation = LogisticsInvitation::findByToken($token);

        abort_if(! $invitation || $invitation->status === 'revoked', 404, 'This invitation is invalid or has been revoked.');

        return $invitation->load('company:id,company_name,owner_profile_id');
    }

    private function usable(string $token): LogisticsInvitation
    {
        $invitation = $this->find($token);

        abort_if($invitation->status === 'accepted', 410, 'This invitation has already been used.');
        abort_if($invitation->isExpired(), 410, 'This invitation has expired.');

        return $invitation;
    }

    private function profileFor(string $email): ?Profile
    {
        return Profile::query()->whereRaw('lower(email) = ?', [Str::lower($email)])->first(['id']);
    }

    private function join(LogisticsInvitation $invitation, string $profileId): void
    {
        // Row lock stops the same link being redeemed twice concurrently.
        $locked = LogisticsInvitation::query()->whereKey($invitation->id)->lockForUpdate()->first();
        abort_unless($locked?->status === 'pending', 410, 'This invitation has already been used.');

        LogisticsAdminDetail::query()->create([
            'profile_id' => $profileId,
            'logistics_company_id' => $invitation->logistics_company_id,
            'role' => $invitation->role,
            'status' => 'active',
            'invited_by' => $invitation->invited_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->markAccepted($invitation, $profileId);
    }

    private function markAccepted(LogisticsInvitation $invitation, string $profileId): void
    {
        $invitation->update([
            'status' => 'accepted',
            'accepted_by' => $profileId,
            'accepted_at' => now(),
        ]);
    }
}
