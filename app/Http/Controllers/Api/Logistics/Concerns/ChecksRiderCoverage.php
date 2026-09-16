<?php

namespace App\Http\Controllers\Api\Logistics\Concerns;

use App\Models\LogisticsBarangayAssignment;
use App\Models\LogisticsCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A rider covers at most one area, company-wide — a specific barangay, the
 * provincial pool, or the regional pool, never more than one at a time.
 * Shared by all three "assign a rider" controllers so the rule can't drift
 * between them.
 */
trait ChecksRiderCoverage
{
    private function assertRiderIsFreeToAssign(
        LogisticsCompany $company,
        string $riderProfileId,
        ?string $exceptBarangayAssignmentId = null,
    ): void {
        $barangay = LogisticsBarangayAssignment::query()
            ->where('logistics_company_id', $company->id)
            ->where('rider_profile_id', $riderProfileId)
            ->when(
                $exceptBarangayAssignmentId,
                fn ($query, string $id) => $query->where('id', '!=', $id),
            )
            ->first();

        if ($barangay) {
            throw ValidationException::withMessages([
                'rider_profile_id' => "This rider is already assigned to {$barangay->barangay}, {$barangay->municipality_name}. Remove them from that barangay first.",
            ]);
        }

        $onProvincial = DB::table('logistics_provincial_assignment_riders as par')
            ->join('logistics_provincial_assignments as pa', 'pa.id', '=', 'par.provincial_assignment_id')
            ->where('pa.logistics_company_id', $company->id)
            ->where('par.rider_profile_id', $riderProfileId)
            ->exists();

        if ($onProvincial) {
            throw ValidationException::withMessages([
                'rider_profile_id' => 'This rider is already in your provincial pool. Remove them from it first.',
            ]);
        }

        $onRegional = DB::table('logistics_regional_assignment_riders as rar')
            ->join('logistics_regional_assignments as ra', 'ra.id', '=', 'rar.regional_assignment_id')
            ->where('ra.logistics_company_id', $company->id)
            ->where('rar.rider_profile_id', $riderProfileId)
            ->exists();

        if ($onRegional) {
            throw ValidationException::withMessages([
                'rider_profile_id' => 'This rider is already in your regional pool. Remove them from it first.',
            ]);
        }
    }
}
