<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Api\Logistics\Concerns\ChecksRiderCoverage;
use App\Http\Controllers\Controller;
use App\Models\CourierApplication;
use App\Models\LogisticsCompany;
use App\Models\LogisticsRegionalAssignment;
use App\Models\ParcelAssignment;
use App\Models\Profile;
use App\Services\ServiceAreaProvisioner;
use App\Support\VehicleCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The company's region-wide fallback rider pool — anything outside its own
 * region falls back here. Van (or truck) riders only — see
 * App\Support\VehicleCategory. At most one per company, auto-provisioned
 * off its own address (App\Services\ServiceAreaProvisioner) rather than
 * created by hand here.
 */
class LogisticsRegionalAssignmentController extends Controller
{
    use ChecksRiderCoverage;

    public function show(Request $request): JsonResponse
    {
        $company = $this->companyFor($request);
        $assignment = $this->assignmentFor($company);

        if (! $assignment) {
            return response()->json([
                'assignment' => null,
                'message' => 'Add a business address in Account Settings first — the regional pool is scoped to your own region.',
            ]);
        }

        return response()->json(['assignment' => $this->transform($assignment)]);
    }

    /**
     * Accepted riders eligible for this pool — not already covering any
     * area, and driving a van or truck.
     */
    public function availableRiders(Request $request): JsonResponse
    {
        $company = $this->companyFor($request);
        $search = trim((string) $request->query('search', ''));

        $riders = $this->eligibleRiderQuery($company, $search)->paginate(10);

        return response()->json([
            'data' => collect($riders->items())->map(fn (Profile $rider) => $this->riderPayload($rider))->values(),
            'meta' => [
                'current_page' => $riders->currentPage(),
                'last_page' => $riders->lastPage(),
                'per_page' => $riders->perPage(),
                'total' => $riders->total(),
            ],
        ]);
    }

    public function addRiders(Request $request): JsonResponse
    {
        $company = $this->companyFor($request);
        $assignment = $this->assignmentFor($company);

        if (! $assignment) {
            return response()->json(['message' => 'Add a business address in Account Settings first.'], 422);
        }

        $data = $request->validate([
            'rider_profile_ids' => ['required', 'array', 'min:1'],
            'rider_profile_ids.*' => ['required', 'uuid'],
        ]);

        foreach ($data['rider_profile_ids'] as $riderProfileId) {
            $this->ensureRiderIsAccepted($company, $riderProfileId);
            $this->assertRiderIsFreeToAssign($company, $riderProfileId);
            $this->ensureVehicleEligible($riderProfileId);
        }

        $assignment->riders()->syncWithoutDetaching($data['rider_profile_ids']);

        return response()->json(['assignment' => $this->transform($assignment->fresh())]);
    }

    public function removeRider(Request $request, string $riderProfileId): JsonResponse
    {
        $company = $this->companyFor($request);
        $assignment = $this->assignmentFor($company);

        if ($assignment) {
            $assignment->riders()->detach($riderProfileId);
        }

        return response()->json(['assignment' => $assignment ? $this->transform($assignment->fresh()) : null]);
    }

    private function assignmentFor(LogisticsCompany $company): ?LogisticsRegionalAssignment
    {
        $provisioned = app(ServiceAreaProvisioner::class)->ensureForCompany($company);

        return $provisioned['regional'];
    }

    private function eligibleRiderQuery(LogisticsCompany $company, string $search): Builder
    {
        return Profile::query()
            ->select('profiles.*')
            ->distinct()
            ->join('courier_applications', 'courier_applications.courier_profile_id', '=', 'profiles.id')
            ->join('courier_details', 'courier_details.profile_id', '=', 'profiles.id')
            ->where('courier_applications.logistics_company_id', $company->id)
            ->where('courier_applications.status', CourierApplication::STATUS_ACCEPTED)
            ->whereIn(DB::raw('LOWER(courier_details.vehicle::text)'), ['van', 'truck'])
            ->whereNotExists(function ($query) use ($company): void {
                $query->select(DB::raw('1'))
                    ->from('logistics_barangay_assignments')
                    ->whereColumn('logistics_barangay_assignments.rider_profile_id', 'profiles.id')
                    ->where('logistics_barangay_assignments.logistics_company_id', $company->id);
            })
            ->whereNotExists(function ($query) use ($company): void {
                $query->select(DB::raw('1'))
                    ->from('logistics_provincial_assignment_riders as par')
                    ->join('logistics_provincial_assignments as pa', 'pa.id', '=', 'par.provincial_assignment_id')
                    ->whereColumn('par.rider_profile_id', 'profiles.id')
                    ->where('pa.logistics_company_id', $company->id);
            })
            ->whereNotExists(function ($query) use ($company): void {
                $query->select(DB::raw('1'))
                    ->from('logistics_regional_assignment_riders as rar')
                    ->join('logistics_regional_assignments as ra', 'ra.id', '=', 'rar.regional_assignment_id')
                    ->whereColumn('rar.rider_profile_id', 'profiles.id')
                    ->where('ra.logistics_company_id', $company->id);
            })
            ->when($search !== '', function (Builder $query) use ($search): void {
                $needle = '%'.mb_strtolower($search).'%';
                $query->where(function (Builder $q) use ($needle): void {
                    $q->whereRaw('LOWER(profiles.first_name) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(profiles.last_name) LIKE ?', [$needle])
                        ->orWhereRaw("LOWER(profiles.first_name || ' ' || profiles.last_name) LIKE ?", [$needle]);
                });
            })
            ->with(['courierDetail', 'address'])
            ->orderBy('profiles.first_name')
            ->orderBy('profiles.last_name');
    }

    private function ensureVehicleEligible(string $riderProfileId): void
    {
        $rider = Profile::query()->with('courierDetail')->find($riderProfileId);

        if (! $rider || ! VehicleCategory::satisfiesVanOrTruck($rider->vehicleLabel())) {
            throw ValidationException::withMessages([
                'rider_profile_ids' => 'Only riders with a van or truck can join the regional pool.',
            ]);
        }
    }

    private function riderPayload(Profile $rider): array
    {
        return [
            'id' => $rider->id,
            'first_name' => $rider->first_name,
            'last_name' => $rider->last_name,
            'email' => $rider->email,
            'contact_no' => $rider->contact_no,
            'vehicle' => $rider->courierDetail?->vehicle,
            'plate_number' => $rider->courierDetail?->plate_number,
            'address' => $rider->address?->full_address ?: null,
            'active_parcels' => ParcelAssignment::activeCountFor($rider->id),
            'parcel_quota' => ParcelAssignment::COURIER_QUOTA,
        ];
    }

    private function transform(LogisticsRegionalAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'region_name' => $assignment->region_name,
            'is_active' => $assignment->is_active,
            'riders' => $assignment->riders->map(fn (Profile $rider) => $this->riderPayload($rider))->values(),
        ];
    }

    private function companyFor(Request $request): LogisticsCompany
    {
        /** @var Profile $profile */
        $profile = $request->user();

        return LogisticsCompany::query()
            ->where('owner_profile_id', $profile->id)
            ->where('status', 'approved')
            ->where('account_status', 'active')
            ->firstOrFail();
    }

    private function ensureRiderIsAccepted(LogisticsCompany $company, string $riderProfileId): void
    {
        $isAccepted = CourierApplication::query()
            ->where('logistics_company_id', $company->id)
            ->where('courier_profile_id', $riderProfileId)
            ->where('status', CourierApplication::STATUS_ACCEPTED)
            ->exists();

        if (! $isAccepted) {
            throw ValidationException::withMessages([
                'rider_profile_ids' => 'One of the selected riders is not accepted by this logistics company.',
            ]);
        }
    }
}
