<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\StoreDeliveryAreaRequest;
use App\Http\Requests\Logistics\UpdateDeliveryAreaRequest;
use App\Http\Resources\Logistics\DeliveryAreaResource;
use App\Models\CourierApplication;
use App\Models\LogisticsCompany;
use App\Models\LogisticsDeliveryArea;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->with(['riders.courierDetail', 'riders.address'])
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

        $area = LogisticsDeliveryArea::query()->create([
            ...$validated,
            'logistics_company_id' => $company->id,
        ]);

        return (new DeliveryAreaResource($area->load(['riders.courierDetail', 'riders.address'])))
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

        $deliveryArea->update($validated);

        return new DeliveryAreaResource($deliveryArea->refresh()->load(['riders.courierDetail', 'riders.address']));
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

        $deliveryArea->riders()->syncWithoutDetaching([$riderProfileId]);

        return new DeliveryAreaResource($deliveryArea->refresh()->load(['riders.courierDetail', 'riders.address']));
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
        $assignedIds = $deliveryArea->riders()->pluck('profiles.id');

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
