<?php

namespace App\Models\Concerns;

use App\Support\MapPin;

/**
 * latitude/longitude pin on an address row that also has PSGC
 * province_code / municipality_code / barangay columns.
 *
 * A pin dropped for one barangay is wrong once the area changes, so any
 * save that moves the area without also sending a new pin clears it —
 * this covers every write path (settings forms, BuyerAddressSync, older
 * clients that don't know about pins).
 */
trait HasMapPin
{
    public static function bootHasMapPin(): void
    {
        static::saving(function (self $model): void {
            if ($model->exists
                && $model->isDirty(['province_code', 'municipality_code', 'barangay'])
                && ! $model->isDirty(['latitude', 'longitude'])) {
                $model->latitude = null;
                $model->longitude = null;
            }
        });
    }

    public function initializeHasMapPin(): void
    {
        $this->mergeFillable(['latitude', 'longitude']);
        $this->mergeCasts(['latitude' => 'float', 'longitude' => 'float']);
    }

    /** @return array{lat: float, lng: float}|null */
    public function mapPin(): ?array
    {
        return MapPin::from($this->latitude, $this->longitude);
    }
}
