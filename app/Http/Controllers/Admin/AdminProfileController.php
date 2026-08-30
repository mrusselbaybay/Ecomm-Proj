<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeactivateAdminAccountRequest;
use App\Http\Requests\Admin\UpdateAdminProfileRequest;
use App\Mail\AccountStatusChanged;
use App\Models\Address;
use App\Models\Profile;
use App\Models\StatusAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Self-service account settings for the logged-in admin: view/edit their
 * own name fields (email is intentionally never editable here), and the
 * "Danger Zone" self-deactivation flow.
 *
 * Password changes are NOT handled here — they reuse the existing
 * PasswordResetController email-code flow (POST /api/password/send-code,
 * /verify-code, /reset) with the admin's own email, so there's exactly
 * one code path for "prove you own this inbox, then set a new password"
 * instead of a second parallel implementation.
 */
class AdminProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();
        $profile->loadMissing('address');

        return response()->json([
            'profile' => $this->profileData($profile),
            'address' => $this->addressData($profile->address),
        ]);
    }

    public function update(UpdateAdminProfileRequest $request): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();
        $address = $profile->address;

        DB::transaction(function () use ($request, $profile, &$address): void {
            $profile->update([
                'first_name' => $request->validated('first_name'),
                'last_name' => $request->validated('last_name'),
                'middle_initial' => $request->validated('middle_initial'),
            ]);

            // Address is optional for admins (internal staff, not a
            // delivery destination) — only touch the record if the caller
            // actually sent address fields, so an admin who never fills
            // this in simply never gets an addresses row.
            $addressPayload = array_filter([
                'region_code' => $request->validated('region_code'),
                'region_name' => $request->validated('region_name'),
                'province_code' => $request->validated('province_code'),
                'province_name' => $request->validated('province_name'),
                'municipality_code' => $request->validated('municipality_code'),
                'municipality_name' => $request->validated('municipality_name'),
                'barangay' => $request->validated('barangay'),
                'street' => $request->validated('street'),
                'house_no' => $request->validated('house_no'),
            ], fn ($value) => $value !== null && $value !== '');

            if (!empty($addressPayload)) {
                $address = Address::updateOrCreate(
                    ['owner_kind' => 'profile', 'profile_id' => $profile->id],
                    $addressPayload,
                );
            }
        });

        return response()->json([
            'message' => 'Profile updated successfully.',
            'profile' => $this->profileData($profile->fresh()),
            'address' => $this->addressData($address),
        ]);
    }

    /**
     * Upload/replace the admin's profile picture. Mirrors
     * BuyerProfileController::uploadAvatar — same public `avatars` bucket,
     * same fixed `{profile id}/avatar.{ext}` path so re-uploading
     * overwrites in place.
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        /** @var Profile $profile */
        $profile = $request->user();
        $file = $request->file('avatar');
        $path = $profile->id.'/avatar.'.$file->getClientOriginalExtension();

        $response = Http::withHeaders([
            'apikey' => config('services.supabase.service_role_key'),
            'Authorization' => 'Bearer '.config('services.supabase.service_role_key'),
            'Content-Type' => $file->getClientMimeType(),
            'x-upsert' => 'true',
        ])->withBody(
            file_get_contents($file->getRealPath()),
            $file->getClientMimeType(),
        )->post(config('services.supabase.url')."/storage/v1/object/avatars/{$path}");

        if (!$response->successful()) {
            Log::error('Avatar upload failed', ['body' => $response->body()]);

            return response()->json(['message' => 'Failed to upload profile picture.'], 500);
        }

        $profile->update(['avatar_path' => $path]);

        return response()->json([
            'message' => 'Profile picture updated.',
            'avatar_url' => $profile->avatar_url,
        ]);
    }

    public function deactivate(DeactivateAdminAccountRequest $request): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();

        if ($profile->account_status === 'deactivated') {
            return response()->json([
                'message' => 'Your account is already deactivated.',
            ], 422);
        }

        if (!$this->verifyPassword($profile->email, $request->validated('password'))) {
            return response()->json([
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($profile): void {
                $oldStatus = $profile->account_status;

                $profile->update(['account_status' => 'deactivated']);

                StatusAuditLog::create([
                    'entity_type' => 'profile',
                    'entity_id' => $profile->id,
                    'old_status' => $oldStatus,
                    'new_status' => 'deactivated',
                    'reason' => 'Self-deactivated by admin from account settings',
                    'changed_by' => $profile->id,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Admin self-deactivation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Deactivation failed. Please contact support.',
            ], 500);
        }

        Mail::to($profile->email)->queue(
            new AccountStatusChanged($profile->full_name, 'deactivated', 'Self-deactivated by admin from account settings')
        );

        // Best-effort: end the current Supabase session immediately so the
        // deactivation takes effect without waiting for token expiry. A
        // failure here shouldn't undo the deactivation that already
        // committed above — the account_status + EnsureUserIsAdmin check
        // still blocks every subsequent admin-gated request either way.
        try {
            Http::withHeaders([
                'apikey' => config('services.supabase.anon_key'),
                'Authorization' => 'Bearer '.$request->bearerToken(),
                'Content-Type' => 'application/json',
            ])->post(config('services.supabase.url').'/auth/v1/logout');
        } catch (\Throwable $e) {
            Log::warning('Supabase logout after self-deactivation failed', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Your account has been deactivated.',
        ]);
    }

    /**
     * Verifies a password by attempting a real Supabase password-grant
     * sign-in — the same mechanism AuthController::login uses. Supabase
     * never exposes password hashes to us, so this is the only correct
     * way to check "is this the current password" server-side.
     */
    private function verifyPassword(string $email, string $password): bool
    {
        try {
            $response = Http::withHeaders([
                'apikey' => config('services.supabase.anon_key'),
                'Content-Type' => 'application/json',
            ])->post(config('services.supabase.url').'/auth/v1/token?grant_type=password', [
                'email' => $email,
                'password' => $password,
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('Password verification request failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function profileData(Profile $profile): array
    {
        return [
            'id' => $profile->id,
            'first_name' => $profile->first_name,
            'middle_initial' => $profile->middle_initial,
            'last_name' => $profile->last_name,
            'full_name' => $profile->full_name,
            'email' => $profile->email,
            'role' => $profile->role,
            'account_status' => $profile->account_status,
            'avatar_url' => $profile->avatar_url,
            'created_at' => $profile->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function addressData(?Address $address): ?array
    {
        if (!$address) {
            return null;
        }

        return [
            'region_code' => $address->region_code,
            'region_name' => $address->region_name,
            'province_code' => $address->province_code,
            'province_name' => $address->province_name,
            'municipality_code' => $address->municipality_code,
            'municipality_name' => $address->municipality_name,
            'barangay' => $address->barangay,
            'street' => $address->street,
            'house_no' => $address->house_no,
            'full_address' => $address->full_address,
        ];
    }
}
