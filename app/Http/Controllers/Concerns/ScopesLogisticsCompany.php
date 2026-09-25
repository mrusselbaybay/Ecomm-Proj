<?php

namespace App\Http\Controllers\Concerns;

use App\Models\LogisticsCompany;
use App\Models\ParcelAssignment;
use App\Models\Profile;
use Illuminate\Http\Request;

/**
 * Resolves the signed-in `logistics`-role profile's own company and scopes
 * a ParcelAssignment to it — shared by every Logistics controller that
 * acts on parcel_assignments rows
 * (Api\Logistics\ParcelAssignmentController, Api\Logistics\
 * ParcelInventoryController) so the "which company, and does this row
 * belong to it" check stays identical everywhere it's enforced.
 */
trait ScopesLogisticsCompany
{
    private function companyFor(Request $request): LogisticsCompany
    {
        /** @var Profile $profile */
        $profile = $request->user();

        return LogisticsCompany::query()
            ->forMember($profile->id)
            ->where('status', 'approved')
            ->where('account_status', 'active')
            ->firstOrFail();
    }

    private function ensureAssignmentBelongsToCompany(
        ParcelAssignment $parcelAssignment,
        LogisticsCompany $company,
    ): void {
        abort_unless($parcelAssignment->logistics_company_id === $company->id, 404);
    }
}
