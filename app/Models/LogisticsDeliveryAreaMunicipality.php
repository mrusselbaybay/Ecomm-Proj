<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One municipality/city (optionally narrowed to a single barangay)
 * covered by a delivery area. A LogisticsDeliveryArea pins one province
 * but can list any number of these — see that model's `municipalities()`
 * relation and ParcelIntakeService::matchingArea().
 */
class LogisticsDeliveryAreaMunicipality extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'delivery_area_id',
        'municipality_code',
        'municipality_name',
        'barangay',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            if (! $row->getKey()) {
                $row->{$row->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    public function deliveryArea(): BelongsTo
    {
        return $this->belongsTo(LogisticsDeliveryArea::class, 'delivery_area_id');
    }
}
