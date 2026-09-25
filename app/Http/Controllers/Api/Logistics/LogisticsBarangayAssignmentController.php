<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Api\Logistics\Concerns\ChecksRiderCoverage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\BulkCreateBarangayAssignmentsRequest;
use App\Http\Requests\Logistics\StoreBarangayAssignmentRequest;
use App\Http\Requests\Logistics\UpdateBarangayAssignmentRequest;
use App\Http\Resources\Logistics\BarangayAssignmentResource;
use App\Models\CourierApplication;
use App\Models\LogisticsBarangayAssignment;
use App\Models\LogisticsCompany;
use App\Models\ParcelAssignment;
use App\Models\Profile;
use App\Services\ServiceAreaProvisioner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LogisticsBarangayAssignmentController extends Controller
{
    use ChecksRiderCoverage;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $company = $this->companyFor($request);
        $assignments = LogisticsBarangayAssignment::query()
            ->with(['rider.courierDetail', 'rider.address'])
            ->where('logistics_company_id', $company->id)
            ->orderByDesc('is_active')
            ->orderBy('municipality_name')
            ->orderBy('barangay')
            ->get();

        // Attached rather than a per-row query in the resource — one
        // grouped query for however many barangays this company has
        // (bulk-create can leave it in the hundreds), see
        // ParcelAssignment::deliveredCountsFor().
        $deliveredCounts = ParcelAssignment::deliveredCountsFor($assignments->pluck('id')->all());
        $assignments->each(function (LogisticsBarangayAssignment $assignment) use ($deliveredCounts): void {
            $assignment->setAttribute('delivered_count', $deliveredCounts[$assignment->id] ?? 0);
        });

        $riders = CourierApplication::query()
            ->with('courier.courierDetail')
            ->where('logistics_company_id', $company->id)
            ->where('status', CourierApplication::STATUS_ACCEPTED)
            ->orderBy('applied_at')
            ->get()
            ->map(function (CourierApplication $application): array {
                return [
                    'id' => $application->courier?->id,
                    'first_name' => $application->courier?->first_name,
                    'last_name' => $application->courier?->last_name,
                    'email' => $application->courier?->email,
                    'contact_no' => $application->courier?->contact_no,
                    'vehicle' => $application->courier?->courierDetail?->vehicle,
                    'plate_number' => $application->courier?->courierDetail?->plate_number,
                    // Display-only "how loaded is this rider" counter —
                    // see ParcelAssignment::activeCountFor(). Never enforced.
                    'active_parcels' => $application->courier
                        ? ParcelAssignment::activeCountFor($application->courier->id)
                        : 0,
                    'parcel_quota' => ParcelAssignment::COURIER_QUOTA,
                ];
            })
            ->filter(fn (array $rider): bool => is_string($rider['id']))
            ->values();

        return response()->json([
            'assignments' => BarangayAssignmentResource::collection($assignments),
            'riders' => $riders,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBarangayAssignmentRequest $request): JsonResponse
    {
        $company = $this->companyFor($request);
        $validated = $request->validated();

        $this->guardBarangayUnclaimed($company, $validated['municipality_name'], $validated['barangay']);

        if (! empty($validated['rider_profile_id'])) {
            $this->ensureRiderIsAccepted($company, $validated['rider_profile_id']);
            $this->assertRiderIsFreeToAssign($company, $validated['rider_profile_id']);
        }

        try {
            $assignment = LogisticsBarangayAssignment::query()->create([
                ...$validated,
                'logistics_company_id' => $company->id,
            ]);
        } catch (QueryException $exception) {
            throw $this->duplicateAssignmentException($exception);
        }

        app(ServiceAreaProvisioner::class)->ensureForCompany($company);

        return (new BarangayAssignmentResource($assignment->load(['rider.courierDetail', 'rider.address'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Create one unassigned (no rider) delivery-area row per barangay of a
     * municipality in a single pass — the manual "Add barangay assignment"
     * flow is fine for one-off coverage but a full municipality can run
     * into the hundreds of barangays. Barangays this company already
     * covers there are skipped rather than erroring out.
     */
    public function bulkCreate(BulkCreateBarangayAssignmentsRequest $request): JsonResponse
    {
        $company = $this->companyFor($request);
        $validated = $request->validated();

        $existing = LogisticsBarangayAssignment::query()
            ->where('logistics_company_id', $company->id)
            ->whereRaw('LOWER(municipality_name) = ?', [mb_strtolower(trim($validated['municipality_name']))])
            ->pluck('barangay')
            ->map(fn (string $barangay): string => mb_strtolower(trim($barangay)))
            ->all();

        $toCreate = collect($validated['barangays'])
            ->map(fn (string $barangay): string => trim($barangay))
            ->filter()
            ->unique(fn (string $barangay): string => mb_strtolower($barangay))
            ->reject(fn (string $barangay): bool => in_array(mb_strtolower($barangay), $existing, true))
            ->values();

        $now = now();
        $rows = $toCreate
            ->map(fn (string $barangay): array => [
                'id' => (string) Str::uuid(),
                'logistics_company_id' => $company->id,
                'province_name' => $validated['province_name'],
                'municipality_code' => $validated['municipality_code'] ?? null,
                'municipality_name' => $validated['municipality_name'],
                'barangay' => $barangay,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            LogisticsBarangayAssignment::query()->insert($rows);
        }

        app(ServiceAreaProvisioner::class)->ensureForCompany($company);

        $assignments = LogisticsBarangayAssignment::query()
            ->where('logistics_company_id', $company->id)
            ->whereRaw('LOWER(municipality_name) = ?', [mb_strtolower(trim($validated['municipality_name']))])
            ->orderBy('barangay')
            ->get();

        $deliveredCounts = ParcelAssignment::deliveredCountsFor($assignments->pluck('id')->all());
        $assignments->each(function (LogisticsBarangayAssignment $assignment) use ($deliveredCounts): void {
            $assignment->setAttribute('delivered_count', $deliveredCounts[$assignment->id] ?? 0);
        });

        return response()->json([
            'created_count' => count($rows),
            'skipped_count' => count($validated['barangays']) - count($rows),
            'assignments' => BarangayAssignmentResource::collection($assignments),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBarangayAssignmentRequest $request, LogisticsBarangayAssignment $barangayAssignment): BarangayAssignmentResource
    {
        $company = $this->companyFor($request);
        $this->ensureAssignmentBelongsToCompany($barangayAssignment, $company);
        $validated = $request->validated();

        if (array_key_exists('municipality_name', $validated) || array_key_exists('barangay', $validated)) {
            $this->guardBarangayUnclaimed(
                $company,
                $validated['municipality_name'] ?? $barangayAssignment->municipality_name,
                $validated['barangay'] ?? $barangayAssignment->barangay,
                $barangayAssignment->id,
            );
        }

        if (array_key_exists('rider_profile_id', $validated) && $validated['rider_profile_id']) {
            $this->ensureRiderIsAccepted($company, $validated['rider_profile_id']);
            $this->assertRiderIsFreeToAssign($company, $validated['rider_profile_id'], $barangayAssignment->id);
        }

        try {
            $barangayAssignment->update($validated);
        } catch (QueryException $exception) {
            throw $this->duplicateAssignmentException($exception);
        }

        return new BarangayAssignmentResource($barangayAssignment->refresh()->load(['rider.courierDetail', 'rider.address']));
    }

    /**
     * Set or clear the one rider assigned to this barangay.
     */
    public function assignRider(Request $request, LogisticsBarangayAssignment $barangayAssignment): BarangayAssignmentResource
    {
        $company = $this->companyFor($request);
        $this->ensureAssignmentBelongsToCompany($barangayAssignment, $company);

        $riderProfileId = $request->validate([
            'rider_profile_id' => ['nullable', 'uuid'],
        ])['rider_profile_id'] ?? null;

        if ($riderProfileId) {
            $this->ensureRiderIsAccepted($company, $riderProfileId);
            $this->assertRiderIsFreeToAssign($company, $riderProfileId, $barangayAssignment->id);
        }

        $barangayAssignment->update(['rider_profile_id' => $riderProfileId]);

        return new BarangayAssignmentResource($barangayAssignment->refresh()->load(['rider.courierDetail', 'rider.address']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, LogisticsBarangayAssignment $barangayAssignment): JsonResponse
    {
        $company = $this->companyFor($request);
        $this->ensureAssignmentBelongsToCompany($barangayAssignment, $company);
        $barangayAssignment->delete();

        return response()->json(['message' => 'Barangay assignment deleted.']);
    }

    /**
     * Accepted riders of this company, searchable — backs the rider picker
     * on the barangay assignment form. A rider covers at most one area,
     * company-wide (see ChecksRiderCoverage), so anyone already on a
     * barangay, in the provincial pool, or in the regional pool is
     * excluded outright rather than shown disabled.
     */
    public function availableRiders(Request $request, LogisticsBarangayAssignment $barangayAssignment): JsonResponse
    {
        $company = $this->companyFor($request);
        $this->ensureAssignmentBelongsToCompany($barangayAssignment, $company);

        $search = trim((string) $request->query('search', ''));

        $riders = Profile::query()
            ->select('profiles.*')
            ->distinct()
            ->join('courier_applications', 'courier_applications.courier_profile_id', '=', 'profiles.id')
            ->where('courier_applications.logistics_company_id', $company->id)
            ->where('courier_applications.status', CourierApplication::STATUS_ACCEPTED)
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
            ->orderBy('profiles.last_name')
            ->paginate(5);

        return response()->json([
            'data' => collect($riders->items())->map(function (Profile $rider): array {
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
            })->values(),
            'meta' => [
                'current_page' => $riders->currentPage(),
                'last_page' => $riders->lastPage(),
                'per_page' => $riders->perPage(),
                'total' => $riders->total(),
            ],
        ]);
    }

    /**
     * A municipality+barangay pair may only be covered by one of this
     * company's assignments. Reject up front with a readable message
     * rather than a raw unique-constraint error.
     */
    private function guardBarangayUnclaimed(
        LogisticsCompany $company,
        string $municipalityName,
        string $barangay,
        ?string $exceptAssignmentId = null,
    ): void {
        $exists = LogisticsBarangayAssignment::query()
            ->where('logistics_company_id', $company->id)
            ->whereRaw('LOWER(municipality_name) = ?', [mb_strtolower(trim($municipalityName))])
            ->whereRaw('LOWER(barangay) = ?', [mb_strtolower(trim($barangay))])
            ->when($exceptAssignmentId, fn (Builder $query, string $id) => $query->where('id', '!=', $id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'barangay' => "{$barangay}, {$municipalityName} already has a courier assignment. Edit that one instead of creating a duplicate.",
            ]);
        }
    }

    /**
     * Last-resort translation of a unique-constraint violation that slips
     * past the up-front guard (e.g. a concurrent save) into a readable
     * 422 rather than a 500 with a raw driver message.
     */
    private function duplicateAssignmentException(QueryException $exception): ValidationException
    {
        return ValidationException::withMessages([
            'barangay' => 'That barangay already has a courier assignment for your company.',
        ]);
    }

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

    private function ensureAssignmentBelongsToCompany(LogisticsBarangayAssignment $barangayAssignment, LogisticsCompany $company): void
    {
        abort_unless($barangayAssignment->logistics_company_id === $company->id, 404);
    }

    private function ensureRiderIsAccepted(LogisticsCompany $company, ?string $riderProfileId): void
    {
        if ($riderProfileId === null) {
            return;
        }

        $isAccepted = CourierApplication::query()
            ->where('logistics_company_id', $company->id)
            ->where('courier_profile_id', $riderProfileId)
            ->where('status', CourierApplication::STATUS_ACCEPTED)
            ->exists();

        if (! $isAccepted) {
            throw ValidationException::withMessages([
                'rider_profile_id' => 'The selected rider is not accepted by this logistics company.',
            ]);
        }
    }

}
