<?php

namespace App\Services;

use App\Models\LogisticsCompany;
use App\Models\LogisticsDeliveryArea;
use App\Models\Order;
use App\Models\ParcelAssignment;
use Illuminate\Database\Eloquent\Builder;

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

        $area = $this->matchingArea($company, $order);

        // Every new parcel starts the same way — waiting for a courier to
        // pick it up. Whether it can be delivered locally or has to be
        // transferred to another company isn't decided here: nobody has
        // physically collected the parcel yet, and that call belongs to
        // the dispatch step *after* pickup (see
        // Api\Logistics\ParcelAssignmentController::assign /
        // ::requestTransfer). `is_transfer` is recorded purely as a hint
        // for staff — it drives no status of its own.
        return ParcelAssignment::query()->create([
            'order_id' => $order->id,
            'logistics_company_id' => $company->id,
            'delivery_area_id' => $area?->id,
            // An area can have several appointed riders now (see
            // LogisticsDeliveryArea::riders) — only auto-fill when there's
            // exactly one, so this never guesses between several.
            'rider_profile_id' => $this->soleRiderOf($area),
            'is_transfer' => $this->needsTransferFrom($company, $order),
            'status' => $area ? ParcelAssignment::STATUS_SORTED : ParcelAssignment::STATUS_RECEIVED,
            'received_by' => $receivedByProfileId,
            'received_at' => now(),
            'scanned_at' => $receivedByProfileId ? now() : null,
            'sorted_at' => $area ? now() : null,
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
     * decision is which delivery area and rider takes it to the buyer,
     * which is exactly what assign()'s delivery-dispatch branch handles.
     *
     * The delivery area is pre-matched where one covers the address, but
     * the rider is deliberately left empty even when an area has a sole
     * appointed rider — dispatch at the receiving company makes that
     * call, and auto-filling it here would push the parcel straight to
     * "out for delivery" without anyone there seeing it.
     *
     * The transfer hint is recalculated for the *receiving* company
     * rather than forced off: normally the whole point of picking that
     * company was that it covers the buyer's region, so this comes out
     * false and they simply deliver it. If the parcel was routed
     * somewhere that still can't reach the buyer, they'll see the same
     * hint this company did and can pass it on again.
     */
    public function createTransferReceipt(ParcelAssignment $origin, LogisticsCompany $targetCompany): ParcelAssignment
    {
        $order = $origin->order;
        $area = $this->matchingArea($targetCompany, $order);

        return ParcelAssignment::query()->create([
            'order_id' => $order->id,
            'logistics_company_id' => $targetCompany->id,
            'previous_assignment_id' => $origin->id,
            'delivery_area_id' => $area?->id,
            'is_transfer' => $this->needsTransferFrom($targetCompany, $order),
            'status' => ParcelAssignment::STATUS_HANDED_OFF,
            'received_at' => now(),
            'sorted_at' => $area ? now() : null,
            'handed_off_at' => now(),
        ]);
    }

    /**
     * Whether the company holding this parcel operates in a different
     * island-group region than the buyer — i.e. it probably can't deliver
     * this itself and the parcel will need handing to a company that can.
     *
     * Deliberately compares the *holding company's* region rather than
     * the seller's: it's the same answer at the seller's own local
     * company (which is where a parcel starts), but it stays correct
     * after a transfer, where the seller's region says nothing about
     * whether the company now holding the parcel can reach the buyer.
     *
     * Case-insensitive and blank-safe — if either side has no region on
     * file the parcel is treated as deliverable rather than flagged,
     * since guessing "needs a transfer" from missing data would strand it.
     */
    private function needsTransferFrom(LogisticsCompany $company, Order $order): bool
    {
        $buyerRegion = $order->shipping_region_name;
        $companyRegion = $company->region;

        if (! filled($buyerRegion) || ! filled($companyRegion)) {
            return false;
        }

        return mb_strtolower(trim($companyRegion)) !== mb_strtolower(trim($buyerRegion));
    }

    /**
     * The one active area of this company whose province AND one of whose
     * municipalities exactly matches the order's shipping address.
     *
     * Strictly exact (case-insensitive) on both parts — never a nearest
     * or "close enough" match. An address in a municipality no area
     * covers returns null and the parcel simply stays unsorted, which is
     * what both intake and ParcelAutoAssignService rely on.
     *
     * Public because auto-assignment re-runs the same match on demand:
     * a parcel taken in before its area existed has no area recorded, and
     * re-matching is exactly how "Auto assign" fills that in.
     */
    public function matchingArea(LogisticsCompany $company, Order $order): ?LogisticsDeliveryArea
    {
        if (! filled($order->shipping_province_name) || ! filled($order->shipping_municipality_name)) {
            return null;
        }

        // An area now lists any number of municipalities (see
        // LogisticsDeliveryArea::municipalities) — match the province on
        // the area itself, and at least one of its municipalities against
        // the order's shipping municipality (+ optional barangay, same
        // "no barangay filter" vs "matches this barangay" choice as
        // before, just moved one level down).
        return LogisticsDeliveryArea::query()
            ->where('logistics_company_id', $company->id)
            ->where('is_active', true)
            ->whereRaw('LOWER(province_name) = ?', [mb_strtolower($order->shipping_province_name)])
            ->whereHas('municipalities', function (Builder $query) use ($order): void {
                $query->whereRaw('LOWER(municipality_name) = ?', [mb_strtolower($order->shipping_municipality_name)])
                    ->where(function (Builder $query) use ($order): void {
                        $query->whereNull('barangay');

                        if (filled($order->shipping_barangay)) {
                            $query->orWhereRaw('LOWER(barangay) = ?', [mb_strtolower($order->shipping_barangay)]);
                        }
                    });
            })
            ->with('municipalities')
            ->get()
            ->sortBy(function (LogisticsDeliveryArea $area) use ($order): int {
                // Prefer an area whose matching municipality row is
                // barangay-specific over one that matches "any barangay".
                $specific = $area->municipalities->first(function ($m) use ($order): bool {
                    return $m->barangay !== null
                        && mb_strtolower($m->municipality_name) === mb_strtolower($order->shipping_municipality_name)
                        && filled($order->shipping_barangay)
                        && mb_strtolower($m->barangay) === mb_strtolower($order->shipping_barangay);
                });

                return $specific ? 0 : 1;
            })
            ->first();
    }

    /**
     * Pre-fills the rider on a freshly sorted parcel ONLY when the area
     * has exactly one appointed rider AND that rider can actually take it
     * right now (on shift, under quota). An area with several riders, or
     * one whose sole rider is off shift / at quota, is left rider-less
     * for "Auto assign" (App\Services\ParcelAutoAssignService) or manual
     * assignment to handle — those report an unavailable rider instead of
     * silently attaching one and stalling the parcel.
     */
    private function soleRiderOf(?LogisticsDeliveryArea $area): ?string
    {
        if (! $area) {
            return null;
        }

        // Area riders are appointed through a courier application, so
        // courier_details is the shift flag isAvailableForDelivery() reads
        // for them — same as ParcelAutoAssignService::eligibleRiders().
        $riders = $area->riders()->with('courierDetail')->get();

        if ($riders->count() !== 1) {
            return null;
        }

        $rider = $riders->first();
        $withinQuota = ParcelAssignment::activeCountFor($rider->id) < ParcelAssignment::COURIER_QUOTA;

        return $rider->isAvailableForDelivery() && $withinQuota
            ? $rider->id
            : null;
    }
}
