<?php

namespace App\Models;

use Database\Factories\ParcelAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ParcelAssignment extends Model
{
    /** @use HasFactory<ParcelAssignmentFactory> */
    use HasFactory;

    public const STATUS_RECEIVED = 'received';

    public const STATUS_SORTED = 'sorted';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_HANDED_OFF = 'handed_off';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'order_id',
        'logistics_company_id',
        'delivery_area_id',
        'rider_profile_id',
        'status',
        'received_by',
        'assigned_by',
        'received_at',
        'scanned_at',
        'sorted_at',
        'assigned_at',
        'handed_off_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'scanned_at' => 'datetime',
        'sorted_at' => 'datetime',
        'assigned_at' => 'datetime',
        'handed_off_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $assignment): void {
            if (! $assignment->getKey()) {
                $assignment->{$assignment->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function logisticsCompany(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class);
    }

    public function deliveryArea(): BelongsTo
    {
        return $this->belongsTo(LogisticsDeliveryArea::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'rider_profile_id');
    }
}
