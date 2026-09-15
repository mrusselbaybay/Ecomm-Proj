<?php

namespace App\Models;

use Database\Factories\LogisticsBarangayAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One barangay a logistics company covers, with at most one assigned
 * courier — replaces LogisticsDeliveryArea (which spanned several
 * municipalities and a rider roster with a round-robin rotation). A rider
 * may be assigned to several of these rows; see
 * App\Services\ParcelIntakeService::matchingBarangayAssignment() and
 * App\Services\ParcelAutoAssignService for how parcels are routed against
 * this table.
 */
class LogisticsBarangayAssignment extends Model
{
    /** @use HasFactory<LogisticsBarangayAssignmentFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'logistics_company_id',
        'province_name',
        'municipality_code',
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
        static::creating(function (self $assignment): void {
            if (! $assignment->getKey()) {
                $assignment->{$assignment->getKeyName()} = (string) Str::uuid();
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
