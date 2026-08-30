<?php

namespace App\Models;

use Database\Factories\LogisticsDeliveryAreaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'rider_profile_id',
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

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'rider_profile_id');
    }
}
