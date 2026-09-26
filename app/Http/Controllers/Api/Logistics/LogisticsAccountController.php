<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\LogisticsAdminDetail;
use App\Models\LogisticsCompany;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Logistics portal account data that the browser used to read/write
 * straight from Supabase: which company the user belongs to, the owner's
 * Account Settings (profile + company + company address), and the company
 * address used by the barangay assignment screen.
 */
class LogisticsAccountController extends Controller
{
    /**
     * The company this user owns or actively staffs. Suspended members get
     * team_role "suspended" and no company — the layout shows no-access.
     */
    public function membership(Request $request): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();

        $owned = LogisticsCompany::where('owner_profile_id', $profile->id)->first(['id', 'company_name']);

        if ($owned) {
            return response()->json(['data' => [
                'company_id' => $owned->id,
                'company_name' => $owned->company_name,
                'team_role' => 'owner',
            ]]);
        }

        $staff = LogisticsAdminDetail::where('profile_id', $profile->id)
            ->first(['logistics_company_id', 'role', 'status']);

        if ($staff?->status === 'active') {
            return response()->json(['data' => [
                'company_id' => $staff->logistics_company_id,
                'company_name' => LogisticsCompany::whereKey($staff->logistics_company_id)->value('company_name') ?? '',
                'team_role' => $staff->role,
            ]]);
        }

        return response()->json(['data' => [
            'company_id' => null,
            'company_name' => '',
            'team_role' => $staff ? 'suspended' : null,
        ]]);
    }

    /** Owner Account Settings: profile, owned company and its address. */
    public function account(Request $request): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();
        $company = LogisticsCompany::where('owner_profile_id', $profile->id)->first();

        return response()->json(['data' => [
            'profile' => AccountController::serializeProfile($profile),
            'company' => $company,
            'address' => $company ? $this->companyAddressQuery($company->id)->first() : null,
        ]]);
    }

    public function updateAccount(Request $request): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();
        $company = LogisticsCompany::where('owner_profile_id', $profile->id)->first();

        $companyRules = [
            'company_name' => ['required', 'string', 'max:255'],
            'company_email' => ['required', 'email', 'max:255'],
            'company_contact_no' => ['required', 'string', 'max:30'],
            'region' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'monthly_salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'is_hiring' => ['boolean'],
        ];

        $data = $request->validate([
            ...AccountController::personalRules(),
            ...($company ? [...$companyRules, ...AccountController::addressRules()] : []),
        ]);

        DB::transaction(function () use ($profile, $company, $data, $companyRules): void {
            $profile->update(collect($data)->only(array_keys(AccountController::personalRules()))->all());

            if (! $company) {
                return;
            }

            $company->update([
                ...collect($data)->only(array_keys($companyRules))->all(),
                'is_hiring' => (bool) ($data['is_hiring'] ?? false),
            ]);

            AccountController::saveAddress(
                $this->companyAddressQuery($company->id)->first(),
                ['owner_kind' => 'logistics_company', 'logistics_company_id' => $company->id],
                collect($data)->only(array_keys(AccountController::addressRules()))->all(),
            );
        });

        return $this->account($request);
    }

    /** Address of the company the user belongs to (owner or active staff). */
    public function companyAddress(Request $request): JsonResponse
    {
        $companyId = LogisticsCompany::forMember($request->user()->id)->value('id');

        return response()->json([
            'data' => $companyId
                ? $this->companyAddressQuery($companyId)
                    ->first(['province_code', 'province_name', 'municipality_code', 'municipality_name'])
                : null,
        ]);
    }

    private function companyAddressQuery(string $companyId)
    {
        return Address::where('owner_kind', 'logistics_company')->where('logistics_company_id', $companyId);
    }
}
