<?php

namespace App\Services;

use App\Models\LogisticsBarangayAssignment;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\ParcelAssignment;

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
     * pick a carrier by name (see SellerCourierController) — there's
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

        $trigger = $this->transferTrigger->evaluate($order);

        // No manual "Auto assign" step any more — every tier that applies
        // at this phase is tried right here, same "never guess, just fall
        // through" rule as before. A freshly-intaken parcel always starts
        // in the pickup phase (nobody's collected it from the seller
        // yet), so this matches the SELLER's address, not the buyer's —
        // see ParcelAutoAssignService::matchRider() for the full rule.
        $match = app(ParcelAutoAssignService::class)->matchRider($order, $company, $trigger['required_vehicle_type'], true);
        $hasRoute = $match['barangayAssignment'] !== null || $match['rider'] !== null;

        return ParcelAssignment::query()->create([
            'order_id' => $order->id,
            'logistics_company_id' => $company->id,
            'barangay_assignment_id' => $match['barangayAssignment']?->id,
            'rider_profile_id' => $match['rider']?->id,
            'is_transfer' => $trigger['is_transfer'],
            'transfer_trigger' => $trigger['trigger'],
            'required_vehicle_type' => $trigger['required_vehicle_type'],
            'status' => $hasRoute ? ParcelAssignment::STATUS_SORTED : ParcelAssignment::STATUS_RECEIVED,
            'received_by' => $receivedByProfileId,
            'received_at' => now(),
            'scanned_at' => $receivedByProfileId ? now() : null,
            'sorted_at' => $hasRoute ? now() : null,
        ]);
    }

    /**
     * Opens the target company's own row the moment that company accepts
     * a transfer request
     * (Api\Logistics\ParcelAssignmentController::acceptTransferRequest).
     *
     * The row starts at STATUS_FOR_INVENTORY — the transfer courier's
     * arrival here is a courier physically handing this company a parcel,
     * same as any other pickup, so it must be scanned in by Logistics
     * (Api\Logistics\ParcelInventoryController::scan) before it can be
     * dispatched, rather than landing straight on STATUS_HANDED_OFF the
     * way it used to. Nothing left to pick up — a transferred parcel has
     * already been collected from the seller during the origin company's
     * pickup leg — but it still isn't dispatch-ready until Logistics here
     * re-verifies it.
     *
     * Rider and barangay are NOT matched here any more (deferred to the
     * scan action, same as the other two "courier hands this company a
     * parcel" cases) even though the receiving company was chosen
     * specifically because it can reach the buyer's address — matching
     * eagerly would still count as auto-staging it for delivery before
     * it's been scanned in.
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
        $trigger = $this->transferTrigger->evaluate($order);

        return ParcelAssignment::query()->create([
            'order_id' => $order->id,
            'logistics_company_id' => $targetCompany->id,
            'previous_assignment_id' => $origin->id,
            'is_transfer' => $trigger['is_transfer'],
            'transfer_trigger' => $trigger['trigger'],
            'required_vehicle_type' => $trigger['required_vehicle_type'],
            'status' => ParcelAssignment::STATUS_FOR_INVENTORY,
            'received_at' => now(),
            'for_inventory_at' => now(),
            'inventory_origin' => ParcelAssignment::INVENTORY_ORIGIN_TRANSFER_RECEIPT,
        ]);
    }

    /**
     * The one active barangay assignment of this company whose
     * municipality AND barangay exactly match the given address.
     * Strictly exact (case-insensitive) — never a nearest or "close
     * enough" match. Nothing assigned to that barangay returns null and
     * the parcel simply stays unsorted for its direct rider, which is
     * what both intake() and ParcelAutoAssignService's fallback tiers
     * rely on.
     *
     * Public because auto-assignment re-runs the same match on demand —
     * see ParcelAutoAssignService::matchRider(), which calls this with
     * either the seller's or the buyer's address depending on whether
     * the parcel is still awaiting pickup or already out for delivery.
     */
    public function matchingBarangayAssignmentFor(LogisticsCompany $company, ?string $municipality, ?string $barangay): ?LogisticsBarangayAssignment
    {
        if (! filled($municipality) || ! filled($barangay)) {
            return null;
        }

        return LogisticsBarangayAssignment::query()
            ->where('logistics_company_id', $company->id)
            ->where('is_active', true)
            ->whereRaw('LOWER(municipality_name) = ?', [mb_strtolower($municipality)])
            ->whereRaw('LOWER(barangay) = ?', [mb_strtolower($barangay)])
            ->first();
    }
}
