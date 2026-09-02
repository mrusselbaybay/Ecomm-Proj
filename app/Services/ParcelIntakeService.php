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
     * Idempotent: an order has at most one ParcelAssignment
     * (parcel_assignments.order_id is unique). Calling this again for an
     * order that already has one just fills in $receivedByProfileId if
     * that's new information (a physical scan arriving after the
     * seller-handover row already exists) — it never re-sorts or
     * re-creates the row.
     */
    public function intake(Order $order, LogisticsCompany $company, ?string $receivedByProfileId = null): ParcelAssignment
    {
        $existing = ParcelAssignment::query()->where('order_id', $order->id)->first();

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

        return ParcelAssignment::query()->create([
            'order_id' => $order->id,
            'logistics_company_id' => $company->id,
            'delivery_area_id' => $area?->id,
            // An area can have several appointed riders now (see
            // LogisticsDeliveryArea::riders) — only auto-fill when there's
            // exactly one, so this never guesses between several.
            'rider_profile_id' => $this->soleRiderOf($area),
            'status' => $area ? ParcelAssignment::STATUS_SORTED : ParcelAssignment::STATUS_RECEIVED,
            'received_by' => $receivedByProfileId,
            'received_at' => now(),
            'scanned_at' => $receivedByProfileId ? now() : null,
            'sorted_at' => $area ? now() : null,
        ]);
    }

    private function matchingArea(LogisticsCompany $company, Order $order): ?LogisticsDeliveryArea
    {
        if (! filled($order->shipping_province_name) || ! filled($order->shipping_municipality_name)) {
            return null;
        }

        return LogisticsDeliveryArea::query()
            ->where('logistics_company_id', $company->id)
            ->where('is_active', true)
            ->whereRaw('LOWER(province_name) = ?', [mb_strtolower($order->shipping_province_name)])
            ->whereRaw('LOWER(municipality_name) = ?', [mb_strtolower($order->shipping_municipality_name)])
            ->where(function (Builder $query) use ($order): void {
                $query->whereNull('barangay');

                if (filled($order->shipping_barangay)) {
                    $query->orWhereRaw('LOWER(barangay) = ?', [mb_strtolower($order->shipping_barangay)]);
                }
            })
            ->orderByRaw('CASE WHEN barangay IS NULL THEN 1 ELSE 0 END')
            ->first();
    }

    private function soleRiderOf(?LogisticsDeliveryArea $area): ?string
    {
        if (! $area) {
            return null;
        }

        $riders = $area->riders()->pluck('profiles.id');

        return $riders->count() === 1 ? $riders->first() : null;
    }
}
