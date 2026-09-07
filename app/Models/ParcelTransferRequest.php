<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One company asking another to take custody of a cross-region parcel it
 * has picked up but can't deliver. The receiving company accepts or
 * rejects; only on accept does the parcel actually move. See the
 * 2026_09_06_000004 migration and
 * Api\Logistics\ParcelAssignmentController.
 */
class ParcelTransferRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'parcel_assignment_id',
        'order_id',
        'from_company_id',
        'to_company_id',
        'status',
        'requested_by',
        'requested_at',
        'reviewed_by',
        'reviewed_at',
        'response_note',
        'resulting_assignment_id',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            if (! $request->getKey()) {
                $request->{$request->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    public function parcelAssignment(): BelongsTo
    {
        return $this->belongsTo(ParcelAssignment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function fromCompany(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class, 'from_company_id');
    }

    public function toCompany(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class, 'to_company_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'reviewed_by');
    }
}
