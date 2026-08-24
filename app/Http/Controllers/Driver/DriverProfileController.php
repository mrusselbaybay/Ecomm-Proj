<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\DeactivateDriverAccountRequest;
use App\Http\Requests\Driver\UpdateDriverProfileRequest;
use App\Mail\AccountStatusChanged;
use App\Models\Address;
use App\Models\CourierDetail;
use App\Models\DriverDetail;
use App\Models\Profile;
use App\Models\StatusAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Self-service "Settings" for the logged-in driver/courier: view/edit
 * their own personal info + on-file address, read-only vehicle/document
 * details, and the "Danger Zone" self-deactivation flow. Mirrors
 * Buyer\BuyerProfileController — courier and driver share this controller
 * because both roles land on the same driver UI (see routes/driver.php
 * and EnsureUserIsDriver).
 *
 * Password changes are NOT handled here — same as buyer/admin, they reuse
 * the existing PasswordResetController email-code flow
 * (POST /api/password/send-code, /verify-code, /reset).
 */
class DriverProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();
        $profile->loadMissing(['address', 'driverDetail', 'courierDetail.logisticsCompany']);

        return response()->json([
            'profile' => $this->profileData($profile),
            'address' => $this->addressData($profile->address),
            'vehicle' => $this->vehicleData($profile),
        ]);
    }

    public function update(UpdateDriverProfileRequest $request): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();
        $address = null;

        DB::transaction(function () use ($request, $profile, &$address): void {
            $profile->update([
                'first_name' => $request->validated('first_name'),
                'last_name' => $request->validated('last_name'),
                'middle_initial' => $request->validated('middle_initial'),
                'sex' => $request->validated('sex'),
                'birthday' => $request->validated('birthday'),
                'contact_no' => $request->validated('contact_no'),
            ]);

            $addressPayload = [
                'province_code' => $request->validated('province_code'),
                'province_name' => $request->validated('province_name'),
                'municipality_code' => $request->validated('municipality_code'),
                'municipality_name' => $request->validated('municipality_name'),
                'barangay' => $request->validated('barangay'),
                'street' => $request->validated('street'),
                'house_no' => $request->validated('house_no'),
            ];

            $address = Address::updateOrCreate(
                ['owner_kind' => 'profile', 'profile_id' => $profile->id],
                $addressPayload,
            );
        });

        $profile->refresh();
        $profile->loadMissing(['driverDetail', 'courierDetail.logisticsCompany']);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'profile' => $this->profileData($profile),
            'address' => $this->addressData($address),
            'vehicle' => $this->vehicleData($profile),
        ]);
    }

    public function deactivate(DeactivateDriverAccountRequest $request): JsonResponse
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
                    'reason' => 'Self-deactivated by '.$profile->role.' from account settings',
                    'changed_by' => $profile->id,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Driver/courier self-deactivation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Deactivation failed. Please contact support.',
            ], 500);
        }

        Mail::to($profile->email)->queue(
            new AccountStatusChanged($profile->full_name, 'deactivated', 'Self-deactivated by '.$profile->role.' from account settings')
        );

        // Best-effort: end the current Supabase session immediately so the
        // deactivation takes effect without waiting for token expiry. A
        // failure here shouldn't undo the deactivation that already
        // committed above — the account_status + EnsureUserIsDriver check
        // still blocks every subsequent driver-gated request either way.
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
     * sign-in — the same mechanism Buyer\BuyerProfileController::verifyPassword
     * and AuthController::login use. Supabase never exposes password
     * hashes to us, so this is the only correct way to check "is this the
     * current password" server-side.
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
            'sex' => $profile->sex,
            'email' => $profile->email,
            'contact_no' => $profile->contact_no,
            'birthday' => optional($profile->birthday)->toDateString(),
            'role' => $profile->role,
            'account_status' => $profile->account_status,
            'status' => $profile->status,
            'created_at' => $profile->created_at?->toIso8601String(),
            'updated_at' => $profile->updated_at?->toIso8601String(),
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

    /**
     * Read-only vehicle/document snapshot from driver_details (role
     * 'driver') or courier_details (role 'courier') — courier_details has
     * no license_number column, so that field is simply null for couriers.
     *
     * @return array<string, mixed>
     */
    private function vehicleData(Profile $profile): array
    {
        if ($profile->role === 'driver') {
            /** @var DriverDetail|null $detail */
            $detail = $profile->driverDetail;

            return [
                'vehicle' => $detail?->vehicle,
                'plate_number' => $detail?->plate_number,
                'license_number' => $detail?->license_number,
                'logistics_company_name' => null,
            ];
        }

        /** @var CourierDetail|null $detail */
        $detail = $profile->courierDetail;

        return [
            'vehicle' => $detail?->vehicle,
            'plate_number' => $detail?->plate_number,
            'license_number' => null,
            'logistics_company_name' => $detail?->logisticsCompany?->company_name,
        ];
    }
}
