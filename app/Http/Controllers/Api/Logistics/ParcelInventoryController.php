<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Concerns\ScopesLogisticsCompany;
use App\Http\Controllers\Controller;
use App\Http\Resources\Logistics\ParcelInventoryResource;
use App\Models\Order;
use App\Models\ParcelAssignment;
use App\Models\ParcelScanEvent;
use App\Models\Profile;
use App\Services\ParcelAutoAssignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backs the Logistics mobile app's Inventory section — the two-tab
 * "Inventory" (already scanned in) / "For Inventory" (awaiting a scan)
 * screen, its parcel detail view, and the QR scan that moves a parcel from
 * one to the other.
 *
 * Every parcel a NEW company takes custody of — the original seller
 * pickup (Driver\DriverDeliveryController::pickup /
 * Api\Logistics\ParcelAssignmentController::handoff's local-leg branch),
 * or a transfer courier arriving with one from another company
 * (App\Services\ParcelIntakeService::createTransferReceipt) — lands on
 * ParcelAssignment::STATUS_FOR_INVENTORY instead of a dispatch-ready
 * status. [scan] is the only way off that status. A transfer courier
 * merely leaving this same company's own hub (handoff()'s transfer-leg
 * branch) is NOT gated here — that parcel was already scanned into this
 * company's inventory once; the checkpoint only re-fires where a
 * *different* company is taking custody.
 */
class ParcelInventoryController extends Controller
{
    use ScopesLogisticsCompany;

    public function __construct(private readonly ParcelAutoAssignService $autoAssign) {}

    private const EAGER_LOADS = [
        'order.items.product',
        'order.seller.sellerDetail',
        'barangayAssignment',
        'rider',
        'transferToCompany',
        'pickedUpBy',
        'inventoryScannedBy',
        'logisticsCompany',
    ];

    /**
     * Tab 1 — "Inventory": every parcel this company has ever scanned in,
     * regardless of its current status. Deliberately keyed on
     * inventory_scanned_at rather than status, so a row keeps showing here
     * once it's moved on to STATUS_HANDED_OFF/STATUS_READY_TO_TRANSFER/
     * beyond — the permanent record that it passed through inventory.
     */
    public function index(Request $request): JsonResponse
    {
        $company = $this->companyFor($request);

        $assignments = ParcelAssignment::query()
            ->with(self::EAGER_LOADS)
            ->where('logistics_company_id', $company->id)
            ->whereNotNull('inventory_scanned_at')
            ->orderByDesc('inventory_scanned_at')
            ->paginate(20);

        return response()->json([
            'data' => ParcelInventoryResource::collection($assignments->items()),
            'meta' => [
                'current_page' => $assignments->currentPage(),
                'last_page' => $assignments->lastPage(),
                'total' => $assignments->total(),
            ],
        ]);
    }

    /**
     * Tab 2 — "For Inventory": parcels a courier has physically handed
     * this company that Logistics hasn't scanned in yet.
     */
    public function pending(Request $request): JsonResponse
    {
        $company = $this->companyFor($request);

        $assignments = ParcelAssignment::query()
            ->with(self::EAGER_LOADS)
            ->where('logistics_company_id', $company->id)
            ->where('status', ParcelAssignment::STATUS_FOR_INVENTORY)
            ->orderByDesc('for_inventory_at')
            ->get();

        return response()->json([
            'data' => ParcelInventoryResource::collection($assignments),
        ]);
    }

    public function show(Request $request, ParcelAssignment $parcelAssignment): JsonResponse
    {
        $company = $this->companyFor($request);
        $this->ensureAssignmentBelongsToCompany($parcelAssignment, $company);

        return response()->json([
            'data' => new ParcelInventoryResource($parcelAssignment->load(self::EAGER_LOADS)),
        ]);
    }

    /**
     * Scan a STATUS_FOR_INVENTORY parcel's confirmation QR to move it into
     * inventory. The scanned code must match this exact parcel's order —
     * mismatches are rejected outright, never silently corrected — and a
     * successful scan is the only way this status changes, so there is no
     * manual "mark as scanned" affordance anywhere in the app.
     */
    public function scan(Request $request, ParcelAssignment $parcelAssignment): JsonResponse
    {
        $company = $this->companyFor($request);
        $this->ensureAssignmentBelongsToCompany($parcelAssignment, $company);

        /** @var Profile $profile */
        $profile = $request->user();

        $data = $request->validate([
            'confirmation_token' => ['required', 'string', 'max:128'],
        ], [
            'confirmation_token.required' => 'Scan the parcel QR code first.',
        ]);

        if ($parcelAssignment->status !== ParcelAssignment::STATUS_FOR_INVENTORY) {
            return response()->json([
                'message' => 'This parcel is not awaiting an inventory scan.',
            ], 422);
        }

        $parcelAssignment->loadMissing('order');
        $scanned = Order::normalizeScannedToken($data['confirmation_token']);
        $actual = (string) $parcelAssignment->order?->confirmation_token;

        if ($actual === '' || ! hash_equals($actual, $scanned)) {
            return response()->json([
                'message' => "The scanned code doesn't match this parcel.",
            ], 422);
        }

        DB::transaction(function () use ($parcelAssignment, $company, $profile): void {
            $locked = ParcelAssignment::whereKey($parcelAssignment->id)->lockForUpdate()->first();

            if ($locked->status !== ParcelAssignment::STATUS_FOR_INVENTORY) {
                return;
            }

            // Both remaining origins (a plain pickup, or a transfer
            // receipt arriving at this company — see ParcelAssignment::
            // $inventory_origin) land on STATUS_HANDED_OFF once scanned:
            // dispatch-ready, needing the same buyer-address rider match
            // pickup()/handoff() used to run eagerly, just deferred to
            // this point instead. The transfer-courier-departure leg
            // never reaches STATUS_FOR_INVENTORY at all any more (see
            // ParcelAssignmentController::handoff()'s transfer-leg
            // branch), so there is no other destination to route to here.
            $order = $locked->order;
            $match = $order
                ? $this->autoAssign->matchRider($order, $company, $locked->required_vehicle_type, false)
                : ['rider' => null, 'barangayAssignment' => null];

            $locked->update([
                'status' => ParcelAssignment::STATUS_HANDED_OFF,
                'inventory_scanned_at' => now(),
                'inventory_scanned_by' => $profile->id,
                'barangay_assignment_id' => $match['barangayAssignment']?->id,
                'rider_profile_id' => $match['rider']?->id,
                'assigned_at' => $match['rider'] ? now() : null,
            ]);
        });

        try {
            ParcelScanEvent::create([
                'order_id' => $parcelAssignment->order_id,
                'parcel_assignment_id' => $parcelAssignment->id,
                'checkpoint' => ParcelScanEvent::CHECKPOINT_INVENTORY,
                'scanned_by' => $profile->id,
                'scanned_by_role' => $profile->role,
                'scanned_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Parcel inventory scan event not recorded: '.$e->getMessage());
        }

        return response()->json([
            'data' => new ParcelInventoryResource($parcelAssignment->refresh()->load(self::EAGER_LOADS)),
        ]);
    }
}
