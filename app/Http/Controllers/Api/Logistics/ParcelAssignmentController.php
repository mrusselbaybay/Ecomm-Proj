<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Concerns\ScopesLogisticsCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\AssignParcelRequest;
use App\Http\Requests\Logistics\AssignTransferCourierRequest;
use App\Http\Requests\Logistics\AssignTransferRequest;
use App\Http\Requests\Logistics\ReceiveParcelRequest;
use App\Http\Resources\Logistics\ParcelAssignmentResource;
use App\Http\Resources\Logistics\ParcelTransferRequestResource;
use App\Models\CourierApplication;
use App\Models\LogisticsBarangayAssignment;
use App\Models\LogisticsCompany;
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
    use ScopesLogisticsCompany;

    public function __construct(
        private readonly ParcelIntakeService $parcelIntake,
        private readonly ParcelAutoAssignService $autoAssign,
    ) {}

    /**
     * Stamps area_fallback_tier onto a single parcel before it's wrapped
     * in a resource — every action endpoint below patches the frontend's
     * row straight from its response (see useLogistics.js's caching
     * philosophy) rather than forcing a full queue reload, so this has to
     * run here too, not just in index()'s loop, or the Area column goes
     * stale (still showing "Needs sorting"/the old barangay) the moment
     * an action — receive, assign, handoff, auto-assign — changes which
     * tier applies without a page refresh to fall back on.
     */
    private function withAreaFallbackTier(ParcelAssignment $assignment, LogisticsCompany $company): ParcelAssignment
    {
        if (! $assignment->barangay_assignment_id && $assignment->order) {
            $assignment->setAttribute(
                'area_fallback_tier',
                $this->autoAssign->expectedTierFor($company, $assignment->order, ! $assignment->handed_off_at),
            );
        }

        return $assignment;
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
                ParcelAssignment::STATUS_FOR_INVENTORY,
                ParcelAssignment::STATUS_HANDED_OFF,
                ParcelAssignment::STATUS_TRANSFER_PENDING,
                ParcelAssignment::STATUS_TRANSFER_ONGOING,
                ParcelAssignment::STATUS_TRANSFER_ASSIGNED,
                ParcelAssignment::STATUS_READY_TO_TRANSFER,
                ParcelAssignment::STATUS_TRANSFERRED,
            ])],
        ]);

        $assignments = ParcelAssignment::query()
            ->with(['order.seller.sellerDetail', 'barangayAssignment', 'rider', 'transferToCompany', 'pendingTransferRequest'])
            ->where('logistics_company_id', $company->id)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('received_at')
            ->get();

        $assignments->each(fn (ParcelAssignment $assignment) => $this->withAreaFallbackTier($assignment, $company));

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
                'data' => new ParcelAssignmentResource($this->withAreaFallbackTier($existing->load(['order.seller.sellerDetail', 'barangayAssignment', 'rider', 'transferToCompany', 'pendingTransferRequest']), $company)),
                'message' => 'This parcel is already in your sorting queue.',
            ]);
        }

        $assignment = DB::transaction(fn (): ParcelAssignment => $this->parcelIntake->intake($order, $company, $profile->id));

        return (new ParcelAssignmentResource($this->withAreaFallbackTier($assignment->load(['order.seller.sellerDetail', 'barangayAssignment', 'rider', 'transferToCompany', 'pendingTransferRequest']), $company)))
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

        // Awaiting the For Inventory checkpoint — rider_profile_id is null
        // here too (same as a genuine "needs a delivery rider" row below),
        // but it must not be assignable until Logistics scans it in
        // (Api\Logistics\ParcelInventoryController::scan), or this would
        // bypass the checkpoint entirely.
        if ($parcelAssignment->status === ParcelAssignment::STATUS_FOR_INVENTORY) {
            throw ValidationException::withMessages([
                'parcel' => 'This parcel is awaiting an inventory scan and cannot be assigned yet.',
            ]);
        }

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

        $assignmentId = $request->validated('barangay_assignment_id');

        // The barangay assignment is only meaningful once this is
        // committed to a local delivery — i.e. the post-pickup dispatch.
        // The first assignment is just "send a courier to collect this",
        // which happens before anyone knows whether the parcel is even
        // staying with this company (see requestTransfer), so it's
        // optional there and stays whatever intake matched, if anything.
        if ($isDeliveryDispatch && ! $assignmentId) {
            throw ValidationException::withMessages([
                'barangay_assignment_id' => 'Select an active barangay assignment owned by your company.',
            ]);
        }

        $barangayAssignment = null;

        if ($assignmentId) {
            $barangayAssignment = LogisticsBarangayAssignment::query()
                ->whereKey($assignmentId)
                ->where('logistics_company_id', $company->id)
                ->where('is_active', true)
                ->first();

            if (! $barangayAssignment) {
                throw ValidationException::withMessages([
                    'barangay_assignment_id' => 'Select an active barangay assignment owned by your company.',
                ]);
            }
        }

        $riderProfileId = $request->validated('rider_profile_id');
        $this->ensureRiderIsAccepted($company, $riderProfileId);
        /** @var Profile $profile */
        $profile = $request->user();

        $parcelAssignment->update([
            'barangay_assignment_id' => $barangayAssignment?->id ?? $parcelAssignment->barangay_assignment_id,
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
            $this->withAreaFallbackTier($parcelAssignment->refresh()->load(['order.seller.sellerDetail', 'barangayAssignment', 'rider', 'transferToCompany', 'pendingTransferRequest']), $company)
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
                $this->withAreaFallbackTier($result['parcel']->refresh()->load(['order.seller.sellerDetail', 'barangayAssignment', 'rider', 'transferToCompany', 'pendingTransferRequest']), $company)
            ),
            'outcome' => $result['outcome'],
            'message' => $result['message'],
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * Staff-side counterpart to Driver\DriverDeliveryController::pickup()
     * — dispatch confirming a parcel was physically handed to the pickup
     * rider at the counter, rather than the rider confirming it
     * themselves from the app. Same transition, so it does the same
     * thing: release the rider and re-match against the BUYER's address
     * now that the parcel is in hand — see
     * ParcelAutoAssignService::matchRider's $isPickupPhase.
     *
     * Also doubles as the transfer courier's counter handoff
     * (STATUS_TRANSFER_ASSIGNED -> STATUS_READY_TO_TRANSFER) — the ONLY
     * way that leg moves on, unlike the local leg above. A cross-company
     * handoff always needs dispatch's own confirmation here; there's no
     * rider self-serve equivalent (Driver\DriverDeliveryController::pickup
     * refuses it outright, and the row stays off that rider's app
     * entirely until this runs — see that controller's class docblock).
     * That leg keeps the rider attached (unlike the local-delivery
     * branch, which re-matches a fresh rider against the buyer's address)
     * and leaves the barangay match alone, since a transfer leg has none.
     */
    public function handoff(Request $request, ParcelAssignment $parcelAssignment): ParcelAssignmentResource
    {
        $company = $this->companyFor($request);
        $this->ensureAssignmentBelongsToCompany($parcelAssignment, $company);

        $isTransferLeg = $parcelAssignment->status === ParcelAssignment::STATUS_TRANSFER_ASSIGNED;

        if (! $isTransferLeg && $parcelAssignment->status !== ParcelAssignment::STATUS_ASSIGNED) {
            throw ValidationException::withMessages([
                'parcel' => 'Only an assigned parcel can be handed to a rider.',
            ]);
        }

        DB::transaction(function () use ($parcelAssignment, $isTransferLeg): void {
            $locked = ParcelAssignment::whereKey($parcelAssignment->id)->lockForUpdate()->first();

            if ($isTransferLeg) {
                // No inventory gate on this leg — the For Inventory
                // checkpoint only applies where a fresh company is taking
                // custody (the original seller pickup, and the receiving
                // company on arrival — see ParcelIntakeService::
                // createTransferReceipt), not the courier simply leaving
                // this same company's own hub with a parcel it already
                // scanned in once. Straight to STATUS_READY_TO_TRANSFER,
                // same as before that checkpoint existed.
                $locked->update(['status' => ParcelAssignment::STATUS_READY_TO_TRANSFER]);

                return;
            }

            // The parcel is now physically in this company's hands, but no
            // longer lands straight on STATUS_HANDED_OFF — it parks at
            // STATUS_FOR_INVENTORY until Logistics scans it in from the
            // mobile app, which is also where the delivery-rider match
            // (ParcelAutoAssignService::matchRider) now happens — see
            // Driver\DriverDeliveryController::pickup()'s docblock for the
            // rider self-serve equivalent of this same change.
            $locked->update([
                'status' => ParcelAssignment::STATUS_FOR_INVENTORY,
                'handed_off_at' => now(),
                'for_inventory_at' => now(),
                'inventory_origin' => ParcelAssignment::INVENTORY_ORIGIN_PICKUP,
                'rider_profile_id' => null,
                // Recorded before it's cleared above — same permanent
                // "who actually collected it" record Driver\
                // DriverDeliveryController::pickup() keeps, so a later
                // transfer can still reuse this courier even once
                // rider_profile_id has moved on to a delivery match (see
                // acceptTransferRequest).
                'picked_up_by' => $locked->rider_profile_id,
            ]);
        });

        return new ParcelAssignmentResource(
            $this->withAreaFallbackTier($parcelAssignment->refresh()->load(['order.seller.sellerDetail', 'barangayAssignment', 'rider', 'transferToCompany', 'pendingTransferRequest']), $company)
        );
    }

    /**
     * Dispatch picks the courier who will physically carry an accepted
     * transfer to the target company's hub (STATUS_TRANSFER_ONGOING ->
     * STATUS_TRANSFER_ASSIGNED). Only reached when the parcel had no rider
     * already on it when the transfer was requested — acceptTransferRequest
     * skips straight to STATUS_TRANSFER_ASSIGNED and reuses that rider
     * when one exists. No barangay is involved — this is a
     * company-to-company handoff, not a delivery — so it's a narrower
     * sibling of assign() rather than a shared code path. The courier
     * still has to confirm the parcel is physically in hand — either
     * themselves from the app (Driver\DriverDeliveryController::pickup) or
     * via dispatch's own handoff(), same as a local rider — before they
     * can confirm the transfer.
     */
    public function assignTransferCourier(AssignTransferCourierRequest $request, ParcelAssignment $parcelAssignment): ParcelAssignmentResource
    {
        $company = $this->companyFor($request);
        $this->ensureAssignmentBelongsToCompany($parcelAssignment, $company);

        if ($parcelAssignment->status !== ParcelAssignment::STATUS_TRANSFER_ONGOING) {
            throw ValidationException::withMessages([
                'parcel' => 'This parcel is not waiting on a transfer courier.',
            ]);
        }

        $riderProfileId = $request->validated('rider_profile_id');
        $this->ensureRiderIsAccepted($company, $riderProfileId);
        /** @var Profile $profile */
        $profile = $request->user();

        $parcelAssignment->update([
            'rider_profile_id' => $riderProfileId,
            'status' => ParcelAssignment::STATUS_TRANSFER_ASSIGNED,
            'assigned_by' => $profile->id,
            'assigned_at' => now(),
        ]);

        return new ParcelAssignmentResource(
            $this->withAreaFallbackTier($parcelAssignment->refresh()->load(['order.seller.sellerDetail', 'barangayAssignment', 'rider', 'transferToCompany', 'pendingTransferRequest']), $company)
        );
    }

    /**
     * Logistics companies eligible to receive a transfer of this parcel.
     *
     * A regional-tier parcel (buyer outside this company's own region —
     * see ParcelAutoAssignService::expectedTierFor) is scoped to companies
     * operating in the buyer's *region* — coarse-grained on purpose, since
     * the mismatch itself is region-level and a narrower match could rule
     * out a company that would sort it out fine once it's in their own
     * region/provincial pool.
     *
     * A provincial-tier parcel (buyer in-province but outside any barangay
     * this company directly covers) is scoped instead to companies that
     * can actually reach the buyer's exact *municipality* — either a
     * direct active barangay assignment there, or a company whose own
     * address is in the same *province* (their provincial pool, the same
     * reach ParcelAutoAssignService::matchRider grants a company for its
     * own province, covers any municipality in it — including one it's
     * headquartered in but has no barangay rider for yet).
     *
     * Either way, falls back to every other active company when the
     * relevant field is missing, rather than showing an empty picker.
     *
     * Backs the target-company picker in ParcelOperations.vue's routing
     * modal, offered alongside "assign a delivery rider" once a parcel
     * has been picked up.
     */
    public function transferOptions(Request $request, ParcelAssignment $parcelAssignment): JsonResponse
    {
        $company = $this->companyFor($request);
        $this->ensureAssignmentBelongsToCompany($parcelAssignment, $company);

        if (! $this->awaitingDispatchDecision($parcelAssignment, $company)) {
            return response()->json([
                'message' => 'This parcel has to be picked up by a courier before it can be routed.',
            ], 422);
        }

        $order = $parcelAssignment->order;
        $tier = $order ? $this->autoAssign->expectedTierFor($company, $order, false) : null;
        $matchByMunicipality = $tier === 'provincial';

        $buyerRegion = $order?->shipping_region_name;
        $buyerProvince = $order?->shipping_province_name;
        $buyerMunicipality = $order?->shipping_municipality_name;

        $companies = LogisticsCompany::query()
            ->where('status', 'approved')
            ->where('account_status', 'active')
            ->whereKeyNot($company->id)
            ->when(
                $matchByMunicipality && filled($buyerMunicipality),
                fn (Builder $query) => $query->where(function (Builder $q) use ($buyerMunicipality, $buyerProvince): void {
                    $q->whereExists(function ($sub) use ($buyerMunicipality): void {
                        $sub->select(DB::raw('1'))
                            ->from('logistics_barangay_assignments')
                            ->whereColumn('logistics_barangay_assignments.logistics_company_id', 'logistics_companies.id')
                            ->where('logistics_barangay_assignments.is_active', true)
                            ->whereRaw('LOWER(TRIM(municipality_name)) = ?', [mb_strtolower(trim($buyerMunicipality))]);
                    })->when(filled($buyerProvince), fn (Builder $qq) => $qq->orWhereExists(function ($sub) use ($buyerProvince): void {
                        $sub->select(DB::raw('1'))
                            ->from('addresses')
                            ->whereColumn('addresses.logistics_company_id', 'logistics_companies.id')
                            ->where('addresses.owner_kind', 'logistics_company')
                            ->whereRaw('LOWER(TRIM(province_name)) = ?', [mb_strtolower(trim($buyerProvince))]);
                    }));
                }),
                fn (Builder $query) => $query->when(filled($buyerRegion), fn (Builder $q) => $q->whereRaw(
                    'LOWER(TRIM(region)) = ?',
                    [mb_strtolower(trim($buyerRegion))],
                )),
            )
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'region']);

        return response()->json([
            'data' => $companies,
            'meta' => [
                'buyer_region' => $buyerRegion,
                'buyer_municipality' => $buyerMunicipality,
                'matched_by' => $matchByMunicipality ? 'municipality' : 'region',
            ],
        ]);
    }

    /**
     * Ask another logistics company to take an already-picked-up parcel
     * this company can't deliver itself. Custody does NOT move here — the
     * parcel is only *offered*: a pending row opens in
     * parcel_transfer_requests and this parcel_assignments row parks at
     * STATUS_TRANSFER_PENDING, out of the local dispatch flow, until the
     * receiving company accepts (acceptTransferRequest) or rejects
     * (rejectTransferRequest). Accepting still doesn't move custody — see
     * acceptTransferRequest's docblock for the courier leg that has to run
     * first.
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

        if (! $this->awaitingDispatchDecision($parcelAssignment, $company)) {
            throw ValidationException::withMessages([
                'parcel' => $parcelAssignment->status === ParcelAssignment::STATUS_FOR_INVENTORY
                    ? 'This parcel is awaiting an inventory scan and cannot be routed yet.'
                    : 'This parcel has to be picked up by a courier before it can be routed to another company.',
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
                // A regional-pool rider already auto-assigned (see
                // awaitingDispatchDecision()) stays attached — if this
                // request is accepted, that's the courier who carries the
                // parcel on to the other company (see
                // acceptTransferRequest).
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
            $this->withAreaFallbackTier($parcelAssignment->refresh()->load(['order.seller.sellerDetail', 'barangayAssignment', 'rider', 'transferToCompany', 'pendingTransferRequest']), $company)
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
     * Receiving company accepts — but custody does NOT move yet. The same
     * courier who already has the parcel carries it on to the other
     * company: if a rider is on the row (the regional-pool auto-assign —
     * see requestTransfer) that's them, otherwise it's whoever originally
     * collected the parcel from the seller (`picked_up_by` — see
     * handoff()/Driver\DriverDeliveryController::pickup()). Either way the
     * origin row goes straight to STATUS_TRANSFER_ASSIGNED, skipping the
     * manual assignTransferCourier() step — but dispatch still has to
     * physically hand the parcel to that courier at the counter
     * (handoff()) before it counts as ready; the courier's own app has no
     * self-serve equivalent for that step (see Driver\
     * DriverDeliveryController's class docblock) and won't even show this
     * leg until dispatch confirms it. Only when no reusable courier is
     * available (or that courier is no longer accepted by this company)
     * does it park at STATUS_TRANSFER_ONGOING for dispatch to pick one via
     * assignTransferCourier() instead — same handoff() step either way
     * after that. From there the courier has to confirm arrival at this
     * company's hub (Driver\DriverDeliveryController::confirmTransfer);
     * only that step opens the fresh "to be delivered" row here
     * (ParcelIntakeService::createTransferReceipt) and sets
     * `resulting_assignment_id` on this request.
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

        DB::transaction(function () use ($origin, $parcelTransferRequest, $profile): void {
            $courierId = $origin->rider_profile_id ?? $origin->picked_up_by;

            $reuseCourier = $courierId && CourierApplication::query()
                ->where('logistics_company_id', $origin->logistics_company_id)
                ->where('courier_profile_id', $courierId)
                ->where('status', CourierApplication::STATUS_ACCEPTED)
                ->exists();

            $origin->update([
                'status' => $reuseCourier
                    ? ParcelAssignment::STATUS_TRANSFER_ASSIGNED
                    : ParcelAssignment::STATUS_TRANSFER_ONGOING,
                'rider_profile_id' => $reuseCourier ? $courierId : $origin->rider_profile_id,
                'assigned_by' => $reuseCourier ? $profile->id : $origin->assigned_by,
                'assigned_at' => $reuseCourier ? now() : $origin->assigned_at,
            ]);

            $parcelTransferRequest->update([
                'status' => ParcelTransferRequest::STATUS_ACCEPTED,
                'reviewed_by' => $profile->id,
                'reviewed_at' => now(),
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
     * transfer request is rejected or cancelled: handed off, no target —
     * exactly where it sat before the request was raised. Any rider that
     * was already on it (see requestTransfer) is left untouched.
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
     * another company" is finally an answerable question.
     *
     * Normally that means no rider yet (mirrors the $isDeliveryDispatch
     * condition in assign(): handed off, pickup courier released). But a
     * regional- or provincial-tier match (buyer outside this company's own
     * region, or in-province but outside any barangay it directly covers —
     * see ParcelAutoAssignService::expectedTierFor) auto-assigns a
     * regional/provincial pool rider immediately, and staff still need the
     * option to send it to another company instead of using that in-house
     * pool — so this stays true for either match even with a rider already
     * on it. Except on a transfer receipt (previous_assignment_id set):
     * that company was already chosen for covering this area, so its own
     * pool is an ordinary way to deliver it, not a reason to keep
     * offering yet another transfer once it has a rider.
     */
    private function awaitingDispatchDecision(ParcelAssignment $parcelAssignment, LogisticsCompany $company): bool
    {
        if ($parcelAssignment->status !== ParcelAssignment::STATUS_HANDED_OFF) {
            return false;
        }

        if ($parcelAssignment->rider_profile_id === null) {
            return true;
        }

        if ($parcelAssignment->barangay_assignment_id || ! $parcelAssignment->order || $parcelAssignment->previous_assignment_id) {
            return false;
        }

        return in_array(
            $this->autoAssign->expectedTierFor($company, $parcelAssignment->order, false),
            ['regional', 'provincial'],
            true,
        );
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
