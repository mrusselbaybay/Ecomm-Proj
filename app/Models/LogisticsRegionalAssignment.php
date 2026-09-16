<?php

namespace App\Models;

use Database\Factories\LogisticsRegionalAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A company's region-wide fallback rider pool (van riders only) — see the
 * migration docblock and App\Services\ParcelAutoAssignService. At most one
 * per company (its own region), auto-provisioned rather than created by
 * hand.
 */
class LogisticsRegionalAssignment extends Model
{
    /** @use HasFactory<LogisticsRegionalAssignmentFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'logistics_company_id',
        'region_name',
        'is_active',
        'last_auto_assigned_rider_profile_id',
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

    public function riders(): BelongsToMany
    {
        return $this->belongsToMany(
            Profile::class,
            'logistics_regional_assignment_riders',
            'regional_assignment_id',
            'rider_profile_id',
        )->withPivot('created_at');
    }
}
