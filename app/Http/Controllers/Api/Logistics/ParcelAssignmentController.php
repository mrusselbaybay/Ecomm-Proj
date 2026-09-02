<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\AssignParcelRequest;
use App\Http\Requests\Logistics\ReceiveParcelRequest;
use App\Http\Resources\Logistics\ParcelAssignmentResource;
use App\Models\CourierApplication;
use App\Models\LogisticsCompany;
use App\Models\LogisticsDeliveryArea;
use App\Models\Order;
use App\Models\ParcelAssignment;
use App\Models\Profile;
use App\Services\ParcelIntakeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ParcelAssignmentController extends Controller
{
    public function __construct(private readonly ParcelIntakeService $parcelIntake)
    {
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $company = $this->companyFor($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([
                ParcelAssignment::STATUS_RECEIVED,
                ParcelAssignment::STATUS_SORTED,
                ParcelAssignment::STATUS_ASSIGNED,
                ParcelAssignment::STATUS_HANDED_OFF,
            ])],
        ]);

        $assignments = ParcelAssignment::query()
            ->with(['order', 'deliveryArea', 'rider'])
            ->where('logistics_company_id', $company->id)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('received_at')
            ->get();

        return response()->json([
            'data' => ParcelAssignmentResource::collection($assignments),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function receive(ReceiveParcelRequest $request): JsonResponse
    {
        $company = $this->companyFor($request);
        /** @var Profile $profile */
        $profile = $request->user();
        $trackingNumber = ltrim($request->validated('tracking_number'), '#');

        $order = Order::query()
            ->where(function (Builder $query) use ($trackingNumber): void {
                $query->where('tracking_number', $trackingNumber)
                    ->orWhere('order_number', $trackingNumber);
            })
            ->first();

        if (! $order) {
            return response()->json(['message' => 'No parcel matches that tracking or order number.'], 404);
        }

        if ($order->status !== 'In Transit') {
            return response()->json([
                'message' => 'Only parcels marked In Transit can be received by a sorting center.',
            ], 422);
        }

        if (
            filled($order->shipping_carrier)
            && mb_strtolower($order->shipping_carrier) !== mb_strtolower($company->company_name)
        ) {
            return response()->json([
                'message' => 'This parcel is assigned to a different logistics company.',
            ], 422);
        }

        $existing = ParcelAssignment::query()->where('order_id', $order->id)->first();
        if ($existing && $existing->logistics_company_id !== $company->id) {
            return response()->json(['message' => 'Another logistics company has already received this parcel.'], 422);
        }

        // A row may already exist here without ever having been scanned —
        // the seller's handover auto-creates it (see ParcelIntakeService)
        // so it shows up in the queue before anyone at the sorting center
        // has physically touched it. Only a row that was already scanned
        // is a genuine duplicate scan.
        $alreadyScanned = (bool) $existing?->received_by;

        if ($alreadyScanned) {
            return response()->json([
                'data' => new ParcelAssignmentResource($existing->load(['order', 'deliveryArea', 'rider'])),
                'message' => 'This parcel is already in your sorting queue.',
            ]);
        }

        $assignment = DB::transaction(fn (): ParcelAssignment => $this->parcelIntake->intake($order, $company, $profile->id));

        return (new ParcelAssignmentResource($assignment->load(['order', 'deliveryArea', 'rider'])))
            ->response()
            ->setStatusCode($existing ? 200 : 201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function assign(AssignParcelRequest $request, ParcelAssignment $parcelAssignment): ParcelAssignmentResource
    {
        $company = $this->companyFor($request);
        $this->ensureAssignmentBelongsToCompany($parcelAssignment, $company);

        if ($parcelAssignment->status === ParcelAssignment::STATUS_HANDED_OFF) {
            throw ValidationException::withMessages([
                'parcel' => 'A handed-off parcel can no longer be reassigned.',
            ]);
        }

        $area = LogisticsDeliveryArea::query()
            ->whereKey($request->validated('delivery_area_id'))
            ->where('logistics_company_id', $company->id)
            ->where('is_active', true)
            ->first();

        if (! $area) {
            throw ValidationException::withMessages([
                'delivery_area_id' => 'Select an active delivery area owned by your company.',
            ]);
        }

        $riderProfileId = $request->validated('rider_profile_id');
        $this->ensureRiderIsAccepted($company, $riderProfileId);
        /** @var Profile $profile */
        $profile = $request->user();

        $parcelAssignment->update([
            'delivery_area_id' => $area->id,
            'rider_profile_id' => $riderProfileId,
            'status' => ParcelAssignment::STATUS_ASSIGNED,
            'assigned_by' => $profile->id,
            'sorted_at' => $parcelAssignment->sorted_at ?? now(),
            'assigned_at' => now(),
        ]);

        return new ParcelAssignmentResource(
            $parcelAssignment->refresh()->load(['order', 'deliveryArea', 'rider'])
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function handoff(Request $request, ParcelAssignment $parcelAssignment): ParcelAssignmentResource
    {
        $company = $this->companyFor($request);
        $this->ensureAssignmentBelongsToCompany($parcelAssignment, $company);

        if ($parcelAssignment->status !== ParcelAssignment::STATUS_ASSIGNED) {
            throw ValidationException::withMessages([
                'parcel' => 'Only an assigned parcel can be handed to a rider.',
            ]);
        }

        $parcelAssignment->update([
            'status' => ParcelAssignment::STATUS_HANDED_OFF,
            'handed_off_at' => now(),
        ]);

        return new ParcelAssignmentResource(
            $parcelAssignment->refresh()->load(['order', 'deliveryArea', 'rider'])
        );
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

    private function ensureAssignmentBelongsToCompany(
        ParcelAssignment $parcelAssignment,
        LogisticsCompany $company,
    ): void {
        abort_unless($parcelAssignment->logistics_company_id === $company->id, 404);
    }

    private function ensureRiderIsAccepted(LogisticsCompany $company, string $riderProfileId): void
    {
        $accepted = CourierApplication::query()
            ->where('logistics_company_id', $company->id)
            ->where('courier_profile_id', $riderProfileId)
            ->where('status', CourierApplication::STATUS_ACCEPTED)
            ->exists();

        if (! $accepted) {
            throw ValidationException::withMessages([
                'rider_profile_id' => 'Select a rider accepted by your logistics company.',
            ]);
        }
    }
}
