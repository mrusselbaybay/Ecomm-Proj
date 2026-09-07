<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\AssignParcelRequest;
use App\Http\Requests\Logistics\AssignTransferRequest;
use App\Http\Requests\Logistics\ReceiveParcelRequest;
use App\Http\Resources\Logistics\ParcelAssignmentResource;
use App\Http\Resources\Logistics\ParcelTransferRequestResource;
use App\Models\CourierApplication;
use App\Models\LogisticsCompany;
use App\Models\LogisticsDeliveryArea;
use App\Models\Order;
use App\Models\ParcelAssignment;
use App\Models\ParcelTransferRequest;
use App\Models\Profile;
use App\Services\ParcelAutoAssignService;
use App\Services\ParcelIntakeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ParcelAssignmentController extends Controller
{
    public function __construct(
        private readonly ParcelIntakeService $parcelIntake,
        private readonly ParcelAutoAssignService $autoAssign,
    ) {}

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
                ParcelAssignment::STATUS_TRANSFER_PENDING,
                ParcelAssignment::STATUS_TRANSFERRED,
            ])],
        ]);

        $assignments = ParcelAssignment::query()
            ->with(['order', 'deliveryArea', 'rider', 'transferToCompany', 'pendingTransferRequest'])
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

        $existing = ParcelAssignment::currentForOrder($order->id);
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
                'data' => new ParcelAssignmentResource($existing->load(['order', 'deliveryArea', 'rider', 'transferToCompany', 'pendingTransferRequest'])),
                'message' => 'This parcel is already in your sorting queue.',
            ]);
        }

        $assignment = DB::transaction(fn (): ParcelAssignment => $this->parcelIntake->intake($order, $company, $profile->id));

        return (new ParcelAssignmentResource($assignment->load(['order', 'deliveryArea', 'rider', 'transferToCompany', 'pendingTransferRequest'])))
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

        // A handed-off parcel that STILL has a rider is out for delivery —
        // genuinely terminal here. But once the pickup courier confirms
        // collection the rider is released (see
        // Driver\DriverDeliveryController::pickup) and the parcel comes
        // back to this queue tagged "To be delivered"; assigning it now is
        // how a delivery rider gets picked. Keep it 'handed_off' in that
        // case — it's already collected, so the delivery rider goes
        // straight to "mark delivered", not back through pickup.
        $isDeliveryDispatch = $parcelAssignment->status === ParcelAssignment::STATUS_HANDED_OFF
            && $parcelAssignment->rider_profile_id === null;

        if ($parcelAssignment->status === ParcelAssignment::STATUS_HANDED_OFF && ! $isDeliveryDispatch) {
            throw ValidationException::withMessages([
                'parcel' => 'A parcel already out for delivery can no longer be reassigned.',
            ]);
        }

        $areaId = $request->validated('delivery_area_id');

        // The delivery area is only meaningful once this is committed to
        // a local delivery — i.e. the post-pickup dispatch. The first
        // assignment is just "send a courier to collect this", which
        // happens before anyone knows whether the parcel is even staying
        // with this company (see requestTransfer), so an area is optional
        // there and stays whatever intake matched, if anything.
        if ($isDeliveryDispatch && ! $areaId) {
            throw ValidationException::withMessages([
                'delivery_area_id' => 'Select an active delivery area owned by your company.',
            ]);
        }

        $area = null;

        if ($areaId) {
            $area = LogisticsDeliveryArea::query()
                ->whereKey($areaId)
                ->where('logistics_company_id', $company->id)
                ->where('is_active', true)
                ->first();

            if (! $area) {
                throw ValidationException::withMessages([
                    'delivery_area_id' => 'Select an active delivery area owned by your company.',
                ]);
            }
        }

        $riderProfileId = $request->validated('rider_profile_id');
        $this->ensureRiderIsAccepted($company, $riderProfileId);
        /** @var Profile $profile */
        $profile = $request->user();

        $parcelAssignment->update([
            'delivery_area_id' => $area?->id ?? $parcelAssignment->delivery_area_id,
            'rider_profile_id' => $riderProfileId,
            // Delivery dispatch (parcel already collected) stays 'handed_off';
            // a first-leg pickup assignment moves to 'assigned'.
            'status' => $isDeliveryDispatch
                ? ParcelAssignment::STATUS_HANDED_OFF
                : ParcelAssignment::STATUS_ASSIGNED,
            'assigned_by' => $profile->id,
            'sorted_at' => $parcelAssignment->sorted_at ?? now(),
            'assigned_at' => now(),
        ]);

        return new ParcelAssignmentResource(
            $parcelAssignment->refresh()->load(['order', 'deliveryArea', 'rider', 'transferToCompany', 'pendingTransferRequest'])
        );
    }

    /**
     * "Auto assign" — route one parcel with no input from staff:
     * address -> matching area -> available riders -> round-robin pick.
     * See App\Services\ParcelAutoAssignService for the rules.
     *
     * Deliberately one parcel per request. The sorting page sweeps its
     * queue by calling this in sequence, which is what lets it report
     * live progress ("4 of 5 parcels assigned") and lets a parcel that
     * can't be routed be reported individually instead of failing the
     * whole batch. Never an error response: "no area covers this address"
     * and "nobody in that area is free" are ordinary outcomes staff need
     * to see per parcel, not failures.
     */
    public function autoAssign(Request $request, ParcelAssignment $parcelAssignment): JsonResponse
    {
        $company = $this->companyFor($request);
        $this->ensureAssignmentBelongsToCompany($parcelAssignment, $company);

        /** @var Profile $profile */
        $profile = $request->user();

        $result = $this->autoAssign->assign($parcelAssignment, $company, $profile->id);

        return response()->json([
            'data' => new ParcelAssignmentResource(
                $result['parcel']->refresh()->load(['order', 'deliveryArea', 'rider', 'transferToCompany', 'pendingTransferRequest'])
            ),
            'outcome' => $result['outcome'],
            'message' => $result['message'],
        ]);
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
            $parcelAssignment->refresh()->load(['order', 'deliveryArea', 'rider', 'transferToCompany', 'pendingTransferRequest'])
        );
    }

    /**
     * Logistics companies eligible to receive a transfer of this parcel.
     * Scoped to companies operating in the *buyer's* region — the whole
     * point of transferring is to hand the parcel to someone who can
     * actually reach the destination, so a company in the same region
     * this one already couldn't deliver to is no use. Falls back to every
     * other active company when the order has no region recorded, rather
     * than showing an empty picker.
     *
     * Backs the target-company picker in ParcelOperations.vue's routing
     * modal, offered alongside "assign a delivery rider" once a parcel
     * has been picked up.
     */
    public function transferOptions(Request $request, ParcelAssignment $parcelAssignment): JsonResponse
    {
        $company = $this->companyFor($request);
        $this->ensureAssignmentBelongsToCompany($parcelAssignment, $company);

        if (! $this->awaitingDispatchDecision($parcelAssignment)) {
            return response()->json([
                'message' => 'This parcel has to be picked up by a courier before it can be routed.',
            ], 422);
        }

        $buyerRegion = $parcelAssignment->order?->shipping_region_name;

        $companies = LogisticsCompany::query()
            ->where('status', 'approved')
            ->where('account_status', 'active')
            ->whereKeyNot($company->id)
            ->when(filled($buyerRegion), fn (Builder $query) => $query->whereRaw(
                'LOWER(TRIM(region)) = ?',
                [mb_strtolower(trim($buyerRegion))],
            ))
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'region']);

        return response()->json([
            'data' => $companies,
            'meta' => ['buyer_region' => $buyerRegion],
        ]);
    }

    /**
     * Ask another logistics company to take an already-picked-up parcel
     * this company can't deliver itself. Custody does NOT move here — the
     * parcel is only *offered*: a pending row opens in
     * parcel_transfer_requests and this parcel_assignments row parks at
     * STATUS_TRANSFER_PENDING, out of the local dispatch flow, until the
     * receiving company accepts (acceptTransferRequest) or rejects
     * (rejectTransferRequest). Only on accept does a fresh "to be
     * delivered" row open at the target
     * (ParcelIntakeService::createTransferReceipt) and this row close as
     * STATUS_TRANSFERRED.
     *
     * The request is deliberately raised only here, after pickup: until a
     * courier has actually collected the parcel there's nothing to route,
     * so a new parcel just sits in the pickup queue like any other (see
     * ParcelIntakeService::intake).
     *
     * This is the sibling of assign() at the same point in the lifecycle
     * — that one commits the parcel to a local delivery area + rider,
     * this one offers it to another company instead.
     */
    public function requestTransfer(AssignTransferRequest $request, ParcelAssignment $parcelAssignment): ParcelAssignmentResource
    {
        $company = $this->companyFor($request);
        $this->ensureAssignmentBelongsToCompany($parcelAssignment, $company);

        if (! $this->awaitingDispatchDecision($parcelAssignment)) {
            throw ValidationException::withMessages([
                'parcel' => 'This parcel has to be picked up by a courier before it can be routed to another company.',
            ]);
        }

        $targetCompanyId = $request->validated('transfer_to_company_id');
        $targetCompany = LogisticsCompany::query()
            ->where('status', 'approved')
            ->where('account_status', 'active')
            ->whereKeyNot($company->id)
            ->find($targetCompanyId);

        if (! $targetCompany) {
            throw ValidationException::withMessages([
                'transfer_to_company_id' => 'Select an active logistics company other than your own.',
            ]);
        }

        /** @var Profile $profile */
        $profile = $request->user();

        DB::transaction(function () use ($parcelAssignment, $targetCompany, $profile): void {
            $parcelAssignment->update([
                'transfer_to_company_id' => $targetCompany->id,
                // Recorded now, but nothing has actually moved — the
                // receiving company still has to accept.
                'is_transfer' => true,
                'status' => ParcelAssignment::STATUS_TRANSFER_PENDING,
                'assigned_by' => $profile->id,
            ]);

            ParcelTransferRequest::query()->create([
                'parcel_assignment_id' => $parcelAssignment->id,
                'order_id' => $parcelAssignment->order_id,
                'from_company_id' => $parcelAssignment->logistics_company_id,
                'to_company_id' => $targetCompany->id,
                'status' => ParcelTransferRequest::STATUS_PENDING,
                'requested_by' => $profile->id,
                'requested_at' => now(),
            ]);
        });

        return new ParcelAssignmentResource(
            $parcelAssignment->refresh()->load(['order', 'deliveryArea', 'rider', 'transferToCompany', 'pendingTransferRequest'])
        );
    }

    /**
     * The "Transfer requests" inbox on the Parcel sorting page. Requests
     * addressed TO the signed-in company (pending first, newest first),
     * plus a company-wide pending count for the header badge. Requests
     * this company *raised* are represented on their own parcel rows
     * (STATUS_TRANSFER_PENDING), so they're not repeated here.
     */
    public function transferRequests(Request $request): JsonResponse
    {
        $company = $this->companyFor($request);

        $requests = ParcelTransferRequest::query()
            ->with(['order', 'fromCompany', 'toCompany', 'requester'])
            ->where('to_company_id', $company->id)
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('requested_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => ParcelTransferRequestResource::collection($requests),
            'meta' => [
                'pending_total' => ParcelTransferRequest::query()
                    ->where('to_company_id', $company->id)
                    ->where('status', ParcelTransferRequest::STATUS_PENDING)
                    ->count(),
            ],
        ]);
    }

    /**
     * Receiving company accepts: custody moves now. The origin row closes
     * as STATUS_TRANSFERRED and a fresh "to be delivered" row opens here
     * (ParcelIntakeService::createTransferReceipt), linked back to the
     * request through `resulting_assignment_id`.
     */
    public function acceptTransferRequest(Request $request, ParcelTransferRequest $parcelTransferRequest): ParcelTransferRequestResource
    {
        $company = $this->companyFor($request);
        $this->ensureIncomingRequest($parcelTransferRequest, $company);

        /** @var Profile $profile */
        $profile = $request->user();

        $origin = $parcelTransferRequest->parcelAssignment()->firstOrFail();

        if ($origin->status !== ParcelAssignment::STATUS_TRANSFER_PENDING) {
            throw ValidationException::withMessages([
                'parcel' => 'This parcel is no longer waiting on a transfer decision.',
            ]);
        }

        DB::transaction(function () use ($origin, $company, $parcelTransferRequest, $profile): void {
            $origin->update([
                'status' => ParcelAssignment::STATUS_TRANSFERRED,
                'transferred_at' => now(),
            ]);

            $receipt = $this->parcelIntake->createTransferReceipt($origin, $company);

            $parcelTransferRequest->update([
                'status' => ParcelTransferRequest::STATUS_ACCEPTED,
                'reviewed_by' => $profile->id,
                'reviewed_at' => now(),
                'resulting_assignment_id' => $receipt->id,
            ]);
        });

        return new ParcelTransferRequestResource(
            $parcelTransferRequest->refresh()->load(['order', 'fromCompany', 'toCompany', 'requester'])
        );
    }

    /**
     * Receiving company rejects: nothing moves. The origin parcel drops
     * back to STATUS_HANDED_OFF (no rider) so the origin company can pick
     * another company or deliver it after all. An optional note is
     * passed back as the reason.
     */
    public function rejectTransferRequest(Request $request, ParcelTransferRequest $parcelTransferRequest): ParcelTransferRequestResource
    {
        $company = $this->companyFor($request);
        $this->ensureIncomingRequest($parcelTransferRequest, $company);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var Profile $profile */
        $profile = $request->user();

        DB::transaction(function () use ($parcelTransferRequest, $profile, $data): void {
            $this->releaseOrigin($parcelTransferRequest);

            $parcelTransferRequest->update([
                'status' => ParcelTransferRequest::STATUS_REJECTED,
                'reviewed_by' => $profile->id,
                'reviewed_at' => now(),
                'response_note' => $data['note'] ?? null,
            ]);
        });

        return new ParcelTransferRequestResource(
            $parcelTransferRequest->refresh()->load(['order', 'fromCompany', 'toCompany', 'requester'])
        );
    }

    /**
     * Origin company withdraws a request the receiving company hasn't
     * answered yet — e.g. it was sent to the wrong company. The origin
     * parcel drops back to STATUS_HANDED_OFF, same as a rejection.
     */
    public function cancelTransferRequest(Request $request, ParcelTransferRequest $parcelTransferRequest): ParcelTransferRequestResource
    {
        $company = $this->companyFor($request);

        if ($parcelTransferRequest->from_company_id !== $company->id) {
            abort(404);
        }

        if ($parcelTransferRequest->status !== ParcelTransferRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'request' => 'This transfer request has already been answered.',
            ]);
        }

        /** @var Profile $profile */
        $profile = $request->user();

        DB::transaction(function () use ($parcelTransferRequest, $profile): void {
            $this->releaseOrigin($parcelTransferRequest);

            $parcelTransferRequest->update([
                'status' => ParcelTransferRequest::STATUS_CANCELLED,
                'reviewed_by' => $profile->id,
                'reviewed_at' => now(),
            ]);
        });

        return new ParcelTransferRequestResource(
            $parcelTransferRequest->refresh()->load(['order', 'fromCompany', 'toCompany', 'requester'])
        );
    }

    /**
     * Put the origin parcel back on its own company's desk after a
     * transfer request is rejected or cancelled: handed off, no rider,
     * no target — exactly where it sat before the request was raised.
     * `is_transfer` is left as-is: the region mismatch that prompted the
     * request is still true, so staff should still see the hint.
     */
    private function releaseOrigin(ParcelTransferRequest $parcelTransferRequest): void
    {
        $origin = $parcelTransferRequest->parcelAssignment()->first();

        if ($origin && $origin->status === ParcelAssignment::STATUS_TRANSFER_PENDING) {
            $origin->update([
                'status' => ParcelAssignment::STATUS_HANDED_OFF,
                'transfer_to_company_id' => null,
            ]);
        }
    }

    private function ensureIncomingRequest(ParcelTransferRequest $parcelTransferRequest, LogisticsCompany $company): void
    {
        abort_unless($parcelTransferRequest->to_company_id === $company->id, 404);

        if ($parcelTransferRequest->status !== ParcelTransferRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'request' => 'This transfer request has already been answered.',
            ]);
        }
    }

    /**
     * True once a courier has collected the parcel and handed it back to
     * this desk — the point where "deliver it locally or transfer it to
     * another company" is finally an answerable question. Mirrors the
     * `$isDeliveryDispatch` condition in assign(): handed off, with the
     * pickup courier already released.
     */
    private function awaitingDispatchDecision(ParcelAssignment $parcelAssignment): bool
    {
        return $parcelAssignment->status === ParcelAssignment::STATUS_HANDED_OFF
            && $parcelAssignment->rider_profile_id === null;
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
