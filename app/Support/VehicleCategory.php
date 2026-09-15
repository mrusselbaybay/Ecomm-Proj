<?php

namespace App\Support;

/**
 * Normalizes the free-text `vehicle` column on courier_details/
 * driver_details (two independently-maintained signup dropdowns that have
 * already drifted — courier: Motorcycle/Car/Van/Bicycle, driver:
 * Motorcycle/Car/Van/Truck) into the categories
 * App\Services\ParcelAutoAssignService gates transfer parcels by.
 *
 * Deliberately a plain matcher rather than a database enum: `vehicle` is
 * live, unvalidated data across two tables, and this field is about to gain
 * exactly one new consumer (the eligibility filter) — fails safe instead
 * (an unrecognized label matches nothing, so a rider is excluded from a
 * vehicle-gated pool rather than wrongly matched or crashing the request).
 */
final class VehicleCategory
{
    public const CAR = 'car';

    public const VAN = 'van';

    public const TRUCK = 'truck';

    public const MOTORCYCLE = 'motorcycle';

    public const BICYCLE = 'bicycle';

    public static function fromLabel(?string $label): ?string
    {
        return match (mb_strtolower(trim((string) $label))) {
            'car' => self::CAR,
            'van' => self::VAN,
            'truck' => self::TRUCK,
            'motorcycle' => self::MOTORCYCLE,
            'bicycle' => self::BICYCLE,
            default => null,
        };
    }

    /**
     * Whether this vehicle can take a "requires a Car" parcel (a
     * municipality-boundary trigger) — a Van or Truck can obviously also
     * manage a same-province leg a Car could.
     */
    public static function satisfiesCar(?string $label): bool
    {
        return in_array(self::fromLabel($label), [self::CAR, self::VAN, self::TRUCK], true);
    }

    /**
     * Whether this vehicle can take a "requires a Van or Truck" parcel (a
     * province- or region-boundary trigger).
     */
    public static function satisfiesVanOrTruck(?string $label): bool
    {
        return in_array(self::fromLabel($label), [self::VAN, self::TRUCK], true);
    }

    /**
     * Dispatches to the right check for a parcel's `required_vehicle_type`
     * column ('car' | 'van_or_truck' | null). Null (no requirement) always
     * passes — every vehicle can take a non-transfer parcel.
     */
    public static function satisfies(?string $requiredVehicleType, ?string $label): bool
    {
        return match ($requiredVehicleType) {
            'car' => self::satisfiesCar($label),
            'van_or_truck' => self::satisfiesVanOrTruck($label),
            default => true,
        };
    }
}
