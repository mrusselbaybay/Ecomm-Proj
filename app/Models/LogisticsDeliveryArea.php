<?php

namespace App\Models;

use Database\Factories\LogisticsDeliveryAreaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class LogisticsDeliveryArea extends Model
{
    /** @use HasFactory<LogisticsDeliveryAreaFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'logistics_company_id',
        'name',
        'province_name',
        'municipality_name',
        'barangay',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $area): void {
            if (! $area->getKey()) {
                $area->{$area->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    public function logisticsCompany(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class);
    }

    // An area can have any number of appointed riders (the "Assigned
    // drivers" tab on the area modal) — replaces the old single
    // rider_profile_id column/rider() relation.
    public function riders(): BelongsToMany
    {
        return $this->belongsToMany(
            Profile::class,
            'logistics_delivery_area_riders',
            'delivery_area_id',
            'rider_profile_id',
        );
    }
}
