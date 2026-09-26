<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Document;
use App\Models\Profile;
use App\Models\SellerDetail;
use App\Models\StatusAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in user's own account rows (profile, personal address, seller
 * details, documents, activity). Replaces the browser's direct Supabase
 * table queries so every portal reads/writes the app database.
 */
class AccountController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        return response()->json(['data' => self::serializeProfile($request->user())]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();
        $profile->update($request->validate(self::personalRules()));

        return response()->json(['data' => self::serializeProfile($profile->refresh())]);
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();

        // Must be a file the client just uploaded into this user's own avatars folder.
        $data = $request->validate([
            'avatar_path' => ['required', 'string', 'max:255', 'regex:/^'.preg_quote($profile->id, '/').'\/[A-Za-z0-9_.-]+$/'],
        ]);

        $profile->update($data);

        return response()->json(['data' => self::serializeProfile($profile)]);
    }

    public function deactivate(Request $request): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();

        if ($profile->account_status === 'deactivated') {
            return response()->json(['message' => 'Your account is already deactivated.'], 422);
        }

        DB::transaction(function () use ($profile): void {
            $oldStatus = $profile->account_status;
            $profile->update(['account_status' => 'deactivated']);

            StatusAuditLog::create([
                'entity_type' => 'profile',
                'entity_id' => $profile->id,
                'old_status' => $oldStatus,
                'new_status' => 'deactivated',
                'reason' => "Self-deactivated by {$profile->role} from account settings",
                'changed_by' => $profile->id,
            ]);
        });

        return response()->json(['message' => 'Account deactivated.']);
    }

    /** Seller Account Settings data (address, business details, documents, recent activity). */
    public function sellerAccount(Request $request): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();

        return response()->json(['data' => [
            'address' => $profile->address,
            'seller_details' => SellerDetail::find($profile->id),
            'documents' => Document::where('profile_id', $profile->id)->latest('created_at')->get(),
            'activity' => StatusAuditLog::where('entity_type', 'profile')
                ->where('entity_id', $profile->id)
                ->latest('created_at')
                ->limit(8)
                ->get(),
        ]]);
    }

    public function updateSellerAccount(Request $request): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();

        $data = $request->validate([
            ...self::personalRules(),
            ...self::addressRules(withRegion: true),
            'business_name' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($profile, $data): void {
            $profile->update(collect($data)->only(array_keys(self::personalRules()))->all());

            self::saveAddress(
                Address::where('owner_kind', 'profile')->where('profile_id', $profile->id)->first(),
                ['owner_kind' => 'profile', 'profile_id' => $profile->id],
                collect($data)->only(array_keys(self::addressRules(withRegion: true)))->all(),
            );

            // line_of_business is the seller's fixed product category — changed via support only.
            SellerDetail::where('profile_id', $profile->id)->update(['business_name' => $data['business_name']]);
        });

        return response()->json(['data' => self::serializeProfile($profile->refresh())]);
    }

    /** @return array<string, mixed> Profile row as the portals expect it (dates as Y-m-d). */
    public static function serializeProfile(Profile $profile): array
    {
        return [
            ...$profile->attributesToArray(),
            'birthday' => $profile->birthday?->format('Y-m-d'),
        ];
    }

    /** @return array<string, list<string>> */
    public static function personalRules(): array
    {
        return [
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_initial' => ['nullable', 'string', 'max:5'],
            'sex' => ['nullable', 'in:Male,Female'],
            'contact_no' => ['nullable', 'string', 'max:30'],
            'birthday' => ['nullable', 'date', 'before:today'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function addressRules(bool $withRegion = false): array
    {
        return [
            ...($withRegion ? [
                'region_code' => ['nullable', 'string', 'max:20'],
                'region_name' => ['nullable', 'string', 'max:100'],
            ] : []),
            'province_code' => ['required', 'string', 'max:20'],
            'province_name' => ['required', 'string', 'max:100'],
            'municipality_code' => ['required', 'string', 'max:20'],
            'municipality_name' => ['required', 'string', 'max:100'],
            'barangay' => ['required', 'string', 'max:150'],
            'street' => ['required', 'string', 'max:255'],
            'house_no' => ['nullable', 'string', 'max:50'],
        ];
    }

    /** Update the existing address row, or create it with $owner keys. */
    public static function saveAddress(?Address $address, array $owner, array $fields): Address
    {
        if ($address) {
            $address->update($fields);

            return $address;
        }

        return Address::create([...$owner, ...$fields]);
    }
}
