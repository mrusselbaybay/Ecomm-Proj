<?php

namespace App\Services;

use App\Models\LogisticsBarangayAssignment;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\ParcelAssignment;
use App\Models\Profile;

/**
 * Puts a parcel into a logistics company's sorting queue — the single
 * ParcelAssignment row behind the "Parcel sorting & rider assignment"
 * page (ParcelAssignmentController::index / resources/js/logistics/
 * components/ParcelOperations.vue).
 *
 * Two callers feed the same queue through this one path, so a parcel
 * never ends up duplicated or attached to the wrong company:
 *   - SellerOrderController::updateStatus, the moment a seller confirms
 *     handover to a registered courier — no $receivedByProfileId, since
 *     nobody at the sorting center has physically scanned it yet, it's
 *     just expected. This is what makes a handover "reflect" on the
 *     sorting page immediately instead of waiting on a manual scan.
 *   - ParcelAssignmentController::receive, when logistics staff scans
 *     the parcel in at the sorting center — stamps $receivedByProfileId.
 */
class ParcelIntakeService
{
    public function __construct(private readonly TransferTriggerService $transferTrigger) {}

    /**
     * Case-insensitive match against Order::shipping_carrier. Sellers
     * pick a carrier by name (see SellerLogisticsController) — there's
     * no FK from `orders` back to `logistics_companies` — so this is the
     * same lookup ParcelAssignmentController::receive already relied on
     * to confirm a scanned parcel belongs to the scanning company.
     */
    public function findActiveCompanyByName(?string $name): ?LogisticsCompany
    {
        if (! filled($name)) {
            return null;
        }

        return LogisticsCompany::active()
            ->whereRaw('LOWER(company_name) = ?', [mb_strtolower($name)])
            ->first();
    }

    /**
     * Idempotent per company: calling this again for an order this
     * *same* company already has a row for just fills in
     * $receivedByProfileId if that's new information (a physical scan
     * arriving after the seller-handover row already exists) — it never
     * re-sorts or re-creates the row.
     *
     * A parcel can legitimately gain a *second* row at a *different*
     * company once it's been transferred cross-region (see
     * createTransferReceipt()) — parcel_assignments.order_id is no
     * longer unique for exactly that reason — so the idempotency check
     * here is scoped to (order, company), not to the order alone.
     */
    public function intake(Order $order, LogisticsCompany $company, ?string $receivedByProfileId = null): ParcelAssignment
    {
        $existing = ParcelAssignment::currentForOrderAndCompany($order->id, $company->id);

        if ($existing) {
            if ($receivedByProfileId && ! $existing->received_by) {
                $existing->update([
                    'received_by' => $receivedByProfileId,
                    'scanned_at' => $existing->scanned_at ?? now(),
                ]);
            }

            return $existing;
        }

        $assignment = $this->matchingBarangayAssignment($company, $order);
        $trigger = $this->transferTrigger->evaluate($order);

        // Every new parcel starts the same way — waiting for a courier to
        // pick it up. `is_transfer`/`required_vehicle_type` are recorded
        // purely as hints for staff and for ParcelAutoAssignService's
        // vehicle filter — they drive no status of their own here.
        return ParcelAssignment::query()->create([
            'order_id' => $order->id,
            'logistics_company_id' => $company->id,
            'barangay_assignment_id' => $assignment?->id,
            // A barangay has at most one assigned rider now — only
            // auto-fill when that rider can actually take it right now
            // (on shift, under quota), same "never guess" rule as before.
            'rider_profile_id' => $this->directRiderOf($assignment),
            'is_transfer' => $trigger['is_transfer'],
            'transfer_trigger' => $trigger['trigger'],
            'required_vehicle_type' => $trigger['required_vehicle_type'],
            'status' => $assignment ? ParcelAssignment::STATUS_SORTED : ParcelAssignment::STATUS_RECEIVED,
            'received_by' => $receivedByProfileId,
            'received_at' => now(),
            'scanned_at' => $receivedByProfileId ? now() : null,
            'sorted_at' => $assignment ? now() : null,
        ]);
    }

    /**
     * Opens the target company's own row the moment that company accepts
     * a transfer request
     * (Api\Logistics\ParcelAssignmentController::acceptTransferRequest).
     *
     * The row starts at STATUS_HANDED_OFF with no rider — the "to be
     * delivered" tag — rather than the STATUS_RECEIVED a scanned-in
     * parcel gets. A transferred parcel has already been collected from
     * the seller during the origin company's pickup leg, so there is
     * nothing left to pick up: the receiving company's only outstanding
     * decision is which barangay and rider takes it to the buyer, which
     * is exactly what assign()'s delivery-dispatch branch handles.
     *
     * The barangay assignment is pre-matched where one covers the
     * address, but the rider is deliberately left empty even when that
     * barangay has an assigned rider — dispatch at the receiving company
     * makes that call, and auto-filling it here would push the parcel
     * straight to "out for delivery" without anyone there seeing it.
     *
     * The transfer trigger is re-evaluated here too, but since it's now
     * purely seller-vs-buyer (not tied to which company currently holds
     * the parcel), it comes out identically at every hop of a multi-hop
     * transfer — `is_transfer` stays true all the way to delivery rather
     * than clearing once a same-region company picks it up. That's
     * intentional: the hint describes the parcel's fixed origin and
     * destination, not who currently holds it.
     */
    public function createTransferReceipt(ParcelAssignment $origin, LogisticsCompany $targetCompany): ParcelAssignment
    {
        $order = $origin->order;
        $assignment = $this->matchingBarangayAssignment($targetCompany, $order);
        $trigger = $this->transferTrigger->evaluate($order);

        return ParcelAssignment::query()->create([
            'order_id' => $order->id,
            'logistics_company_id' => $targetCompany->id,
            'previous_assignment_id' => $origin->id,
            'barangay_assignment_id' => $assignment?->id,
            'is_transfer' => $trigger['is_transfer'],
            'transfer_trigger' => $trigger['trigger'],
            'required_vehicle_type' => $trigger['required_vehicle_type'],
            'status' => ParcelAssignment::STATUS_HANDED_OFF,
            'received_at' => now(),
            'sorted_at' => $assignment ? now() : null,
            'handed_off_at' => now(),
        ]);
    }

    /**
     * The one active barangay assignment of this company whose
     * municipality AND barangay exactly match the order's shipping
     * address. Strictly exact (case-insensitive) — never a nearest or
     * "close enough" match. An address in a barangay nobody's assigned
     * returns null and the parcel simply stays unsorted for its direct
     * rider, which is what both intake() and ParcelAutoAssignService's
     * fallback pool rely on.
     *
     * Public because auto-assignment re-runs the same match on demand:
     * a parcel taken in before its assignment existed has no assignment
     * recorded, and re-matching is exactly how "Auto assign" fills that
     * in.
     */
    public function matchingBarangayAssignment(LogisticsCompany $company, Order $order): ?LogisticsBarangayAssignment
    {
        if (! filled($order->shipping_municipality_name) || ! filled($order->shipping_barangay)) {
            return null;
        }

        return LogisticsBarangayAssignment::query()
            ->where('logistics_company_id', $company->id)
            ->where('is_active', true)
            ->whereRaw('LOWER(municipality_name) = ?', [mb_strtolower($order->shipping_municipality_name)])
            ->whereRaw('LOWER(barangay) = ?', [mb_strtolower($order->shipping_barangay)])
            ->first();
    }

    /**
     * Pre-fills the rider on a freshly sorted parcel ONLY when the
     * matched barangay has an assigned rider AND that rider can actually
     * take it right now (on shift, under quota). No assignment, or one
     * whose rider is off shift / at quota / unset, is left rider-less for
     * "Auto assign" (App\Services\ParcelAutoAssignService) or manual
     * assignment to handle.
     */
    private function directRiderOf(?LogisticsBarangayAssignment $assignment): ?string
    {
        if (! $assignment?->rider_profile_id) {
            return null;
        }

        $rider = Profile::query()->with('courierDetail')->find($assignment->rider_profile_id);

        if (! $rider) {
            return null;
        }

        $withinQuota = ParcelAssignment::activeCountFor($rider->id) < ParcelAssignment::COURIER_QUOTA;

        return $rider->isAvailableForDelivery() && $withinQuota
            ? $rider->id
            : null;
    }
}
