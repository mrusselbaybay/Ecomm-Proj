<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\StoreDeliveryAreaRequest;
use App\Http\Requests\Logistics\UpdateDeliveryAreaRequest;
use App\Http\Resources\Logistics\DeliveryAreaResource;
use App\Models\CourierApplication;
use App\Models\LogisticsCompany;
use App\Models\LogisticsDeliveryArea;
use App\Models\LogisticsDeliveryAreaMunicipality;
use App\Models\ParcelAssignment;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryAreaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $company = $this->companyFor($request);
        $areas = LogisticsDeliveryArea::query()
            ->with(['riders.courierDetail', 'riders.address', 'municipalities'])
            ->where('logistics_company_id', $company->id)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

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
            'areas' => DeliveryAreaResource::collection($areas),
            'riders' => $riders,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreDeliveryAreaRequest $request): JsonResponse
    {
        $company = $this->companyFor($request);
        $validated = $request->validated();
        $municipalities = $validated['municipalities'];
        unset($validated['municipalities']);

        $this->guardDuplicateName($company, $validated['name']);
        $this->guardMunicipalitiesUnclaimed($company, $municipalities);

        try {
            $area = DB::transaction(function () use ($validated, $company, $municipalities): LogisticsDeliveryArea {
                $area = LogisticsDeliveryArea::query()->create([
                    ...$validated,
                    'logistics_company_id' => $company->id,
                ]);

                $this->syncMunicipalities($area, $municipalities);

                return $area;
            });
        } catch (QueryException $exception) {
            throw $this->duplicateAreaException($exception);
        }

        return (new DeliveryAreaResource($area->load(['riders.courierDetail', 'riders.address', 'municipalities'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateDeliveryAreaRequest $request, LogisticsDeliveryArea $deliveryArea): DeliveryAreaResource
    {
        $company = $this->companyFor($request);
        $this->ensureAreaBelongsToCompany($deliveryArea, $company);
        $validated = $request->validated();
        $municipalities = $validated['municipalities'] ?? null;
        unset($validated['municipalities']);

        if (array_key_exists('name', $validated)) {
            $this->guardDuplicateName($company, $validated['name'], $deliveryArea->id);
        }

        if ($municipalities !== null) {
            $this->guardMunicipalitiesUnclaimed($company, $municipalities, $deliveryArea->id);
        }

        try {
            DB::transaction(function () use ($deliveryArea, $validated, $municipalities): void {
                $deliveryArea->update($validated);

                if ($municipalities !== null) {
                    $this->syncMunicipalities($deliveryArea, $municipalities);
                }
            });
        } catch (QueryException $exception) {
            throw $this->duplicateAreaException($exception);
        }

        return new DeliveryAreaResource($deliveryArea->refresh()->load(['riders.courierDetail', 'riders.address', 'municipalities']));
    }

    /**
     * Replace an area's covered municipalities wholesale — simplest
     * correct approach given how few rows are involved (typically a
     * handful of municipalities per area).
     *
     * @param  array<int, array{name: string, code?: string|null, barangay?: string|null}>  $municipalities
     */
    private function syncMunicipalities(LogisticsDeliveryArea $area, array $municipalities): void
    {
        $area->municipalities()->delete();

        $area->municipalities()->createMany(collect($municipalities)->map(fn (array $m): array => [
            'municipality_code' => $m['code'] ?? null,
            'municipality_name' => $m['name'],
            'barangay' => $m['barangay'] ?? null,
        ])->all());
    }

    /**
     * The (logistics_company_id, name) pair is unique in the database.
     * Check it up front so a repeat name comes back as a readable
     * validation message on the "Area name" field instead of a raw
     * SQLSTATE 23505 driver error. Case-insensitive so "Area A" and
     * "area a" are treated as the same name.
     */
    private function guardDuplicateName(LogisticsCompany $company, ?string $name, ?string $exceptAreaId = null): void
    {
        if (! filled($name)) {
            return;
        }

        $exists = LogisticsDeliveryArea::query()
            ->where('logistics_company_id', $company->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($exceptAreaId, fn (Builder $query, string $id) => $query->where('id', '!=', $id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => "You already have a delivery area named \"{$name}\". Choose a different name.",
            ]);
        }
    }

    /**
     * A municipality/city may only be covered by one of this company's
     * areas — routing a scanned parcel to two areas at once is
     * ambiguous. Reject up front, naming the area that already claims it,
     * rather than letting a half-written set of rows land.
     *
     * @param  array<int, array{name?: string|null, code?: string|null, barangay?: string|null}>  $municipalities
     */
    private function guardMunicipalitiesUnclaimed(LogisticsCompany $company, array $municipalities, ?string $exceptAreaId = null): void
    {
        $names = collect($municipalities)
            ->pluck('name')
            ->filter(fn ($name): bool => filled($name))
            ->map(fn (string $name): string => mb_strtolower(trim($name)))
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return;
        }

        $clash = LogisticsDeliveryAreaMunicipality::query()
            ->select([
                'logistics_delivery_area_municipalities.municipality_name',
                'logistics_delivery_areas.name as area_name',
            ])
            ->join(
                'logistics_delivery_areas',
                'logistics_delivery_areas.id',
                '=',
                'logistics_delivery_area_municipalities.delivery_area_id',
            )
            ->where('logistics_delivery_areas.logistics_company_id', $company->id)
            ->when(
                $exceptAreaId,
                fn (Builder $query, string $id) => $query->where('logistics_delivery_area_municipalities.delivery_area_id', '!=', $id),
            )
            ->whereIn(
                DB::raw('LOWER(logistics_delivery_area_municipalities.municipality_name)'),
                $names->all(),
            )
            ->first();

        if ($clash) {
            throw ValidationException::withMessages([
                'municipalities' => "{$clash->municipality_name} is already covered by another area (\"{$clash->area_name}\"). Remove it there first, or pick a different municipality.",
            ]);
        }
    }

    /**
     * Last-resort translation of a unique-constraint violation that slips
     * past the up-front guards (e.g. a concurrent save) into a readable
     * 422 rather than a 500 with a raw driver message.
     */
    private function duplicateAreaException(QueryException $exception): ValidationException
    {
        $message = $exception->getMessage();
        $isUnique = $exception->getCode() === '23505'
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Unique violation');

        if ($isUnique && str_contains(mb_strtolower($message), 'municipalit')) {
            return ValidationException::withMessages([
                'municipalities' => 'One of those municipalities is already covered by another area.',
            ]);
        }

        if ($isUnique) {
            return ValidationException::withMessages([
                'name' => 'You already have a delivery area with that name.',
            ]);
        }

        return ValidationException::withMessages([
            'area' => 'That delivery area could not be saved. Review the details and try again.',
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, LogisticsDeliveryArea $deliveryArea): JsonResponse
    {
        $company = $this->companyFor($request);
        $this->ensureAreaBelongsToCompany($deliveryArea, $company);
        $deliveryArea->delete();

        return response()->json(['message' => 'Delivery area deleted.']);
    }

    /**
     * Appoint an accepted rider to this area (idempotent — appointing the
     * same rider twice is a no-op, not a duplicate/error).
     */
    public function addRider(Request $request, LogisticsDeliveryArea $deliveryArea): DeliveryAreaResource
    {
        $company = $this->companyFor($request);
        $this->ensureAreaBelongsToCompany($deliveryArea, $company);

        $riderProfileId = $request->validate([
            'rider_profile_id' => ['required', 'uuid'],
        ])['rider_profile_id'];

        $this->ensureRiderIsAccepted($company, $riderProfileId);
        $this->ensureRiderNotAssignedElsewhere($company, $riderProfileId, $deliveryArea->id);

        $deliveryArea->riders()->syncWithoutDetaching([$riderProfileId]);

        return new DeliveryAreaResource($deliveryArea->refresh()->load(['riders.courierDetail', 'riders.address', 'municipalities']));
    }

    /**
     * Remove a rider's appointment to this area.
     */
    public function removeRider(Request $request, LogisticsDeliveryArea $deliveryArea, string $riderProfileId): DeliveryAreaResource
    {
        $company = $this->companyFor($request);
        $this->ensureAreaBelongsToCompany($deliveryArea, $company);

        $deliveryArea->riders()->detach($riderProfileId);

        return new DeliveryAreaResource($deliveryArea->refresh()->load(['riders.courierDetail', 'riders.address']));
    }

    /**
     * Riders this company has accepted who are NOT yet appointed to this
     * area — backs the "Add driver" side panel on the area modal
     * (Couriers.vue). Deliberately its own paginated, searched-server-side
     * endpoint rather than reusing index()'s full company-wide roster:
     * that roster is fine unpaginated for a summary table, but handing
     * the whole thing to the browser every time someone opens "Add
     * driver" doesn't scale with the size of the rider pool, and isn't
     * what's being asked for anyway (5 at a time, matching what's
     * actually shown).
     */
    public function availableRiders(Request $request, LogisticsDeliveryArea $deliveryArea): JsonResponse
    {
        $company = $this->companyFor($request);
        $this->ensureAreaBelongsToCompany($deliveryArea, $company);

        $search = trim((string) $request->query('search', ''));

        // A rider belongs to at most one of this company's areas, so the
        // panel offers only riders not appointed anywhere yet — not just
        // those missing from this one area.
        $assignedIds = DB::table('logistics_delivery_area_riders')
            ->join(
                'logistics_delivery_areas',
                'logistics_delivery_areas.id',
                '=',
                'logistics_delivery_area_riders.delivery_area_id',
            )
            ->where('logistics_delivery_areas.logistics_company_id', $company->id)
            ->pluck('logistics_delivery_area_riders.rider_profile_id');

        $riders = Profile::query()
            ->select('profiles.*')
            ->distinct()
            ->join('courier_applications', 'courier_applications.courier_profile_id', '=', 'profiles.id')
            ->where('courier_applications.logistics_company_id', $company->id)
            ->where('courier_applications.status', CourierApplication::STATUS_ACCEPTED)
            ->whereNotIn('profiles.id', $assignedIds)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $needle = '%'.mb_strtolower($search).'%';
                $query->where(function (Builder $q) use ($needle): void {
                    // First/last separately (so "One" finds "Rider One")
                    // and concatenated (so a full "Rider One" search
                    // works too) — `||` concatenation works the same on
                    // sqlite and pgsql.
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
            'data' => collect($riders->items())->map(fn (Profile $rider): array => [
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
            ])->values(),
            'meta' => [
                'current_page' => $riders->currentPage(),
                'last_page' => $riders->lastPage(),
                'per_page' => $riders->perPage(),
                'total' => $riders->total(),
            ],
        ]);
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

    private function ensureAreaBelongsToCompany(LogisticsDeliveryArea $deliveryArea, LogisticsCompany $company): void
    {
        abort_unless($deliveryArea->logistics_company_id === $company->id, 404);
    }

    /**
     * A rider may be appointed to only one of a company's delivery areas.
     * Reject up front, naming the area that already has them, rather than
     * spreading one rider across several rotations.
     */
    private function ensureRiderNotAssignedElsewhere(LogisticsCompany $company, string $riderProfileId, string $exceptAreaId): void
    {
        $clashAreaName = LogisticsDeliveryArea::query()
            ->join(
                'logistics_delivery_area_riders',
                'logistics_delivery_area_riders.delivery_area_id',
                '=',
                'logistics_delivery_areas.id',
            )
            ->where('logistics_delivery_areas.logistics_company_id', $company->id)
            ->where('logistics_delivery_areas.id', '!=', $exceptAreaId)
            ->where('logistics_delivery_area_riders.rider_profile_id', $riderProfileId)
            ->value('logistics_delivery_areas.name');

        if ($clashAreaName !== null) {
            throw ValidationException::withMessages([
                'rider_profile_id' => "This rider is already assigned to \"{$clashAreaName}\". A rider can only cover one delivery area — remove them there first.",
            ]);
        }
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
