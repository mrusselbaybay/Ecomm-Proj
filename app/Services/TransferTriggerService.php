<?php

namespace App\Services;

use App\Models\Order;

/**
 * The three seller-vs-buyer boundary checks that decide whether a parcel
 * needs a bigger vehicle, and/or is flagged for the cross-company handoff
 * workflow (Api\Logistics\ParcelAssignmentController::requestTransfer).
 *
 * Region is checked first: it's the outermost boundary, and a region
 * mismatch already implies province and municipality also differ, so it
 * must win over the narrower triggers even though those would also
 * technically be true.
 *
 * `is_transfer` is deliberately set ONLY by the region trigger — this is
 * the same "the holding company probably can't reach the buyer at all"
 * hint the flag carried before (now computed from the seller's address
 * instead of the holding company's region column), and is what
 * ParcelAssignmentController::transferOptions() offers to another company.
 * A province or municipality mismatch is a normal in-company delivery that
 * just needs a Van/Truck or Car — never offered to another company.
 */
class TransferTriggerService
{
    /**
     * @return array{is_transfer: bool, trigger: ?string, required_vehicle_type: ?string}
     */
    public function evaluate(Order $order): array
    {
        if ($this->differs($order->pickup_region_name, $order->shipping_region_name)) {
            return [
                'is_transfer' => true,
                'trigger' => 'region',
                'required_vehicle_type' => 'van_or_truck',
            ];
        }

        if ($this->differs($order->pickup_province_name, $order->shipping_province_name)) {
            return [
                'is_transfer' => false,
                'trigger' => 'province',
                'required_vehicle_type' => 'van_or_truck',
            ];
        }

        if ($this->differs($order->pickup_municipality_name, $order->shipping_municipality_name)) {
            return [
                'is_transfer' => false,
                'trigger' => 'municipality',
                'required_vehicle_type' => 'car',
            ];
        }

        return [
            'is_transfer' => false,
            'trigger' => null,
            'required_vehicle_type' => null,
        ];
    }

    /**
     * Case-insensitive, blank-safe — if either side has no value on file
     * (pickup_* not yet backfilled on an older order, or a genuinely
     * missing address part) this is treated as "not a mismatch" rather
     * than guessing a trigger from incomplete data, same rule the old
     * needsTransferFrom() used.
     */
    private function differs(?string $a, ?string $b): bool
    {
        if (! filled($a) || ! filled($b)) {
            return false;
        }

        return mb_strtolower(trim($a)) !== mb_strtolower(trim($b));
    }
}
