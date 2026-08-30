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
            ->with('rider')
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
        $this->ensureRiderIsAccepted($company, $validated['rider_profile_id'] ?? null);

        $area = LogisticsDeliveryArea::query()->create([
            ...$validated,
            'logistics_company_id' => $company->id,
        ]);

        return (new DeliveryAreaResource($area->load('rider')))
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

        if (array_key_exists('rider_profile_id', $validated)) {
            $this->ensureRiderIsAccepted($company, $validated['rider_profile_id']);
        }

        $deliveryArea->update($validated);

        return new DeliveryAreaResource($deliveryArea->refresh()->load('rider'));
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
