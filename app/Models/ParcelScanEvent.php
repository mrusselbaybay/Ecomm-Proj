<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded scan of a parcel's confirmation QR (see the
 * 2026_09_03_000200 migration for the checkpoint vocabulary). Append-only:
 * rows are created, never updated or deleted, by
 * App\Http\Controllers\Driver\DriverDeliveryController.
 */
class ParcelScanEvent extends Model
{
    use HasUuidPrimaryKey;

    public const CHECKPOINT_VERIFY = 'verify';

    public const CHECKPOINT_PICKUP = 'pickup';

    public const CHECKPOINT_DELIVERY = 'delivery';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'order_id',
        'parcel_assignment_id',
        'checkpoint',
        'scanned_by',
        'scanned_by_role',
        'note',
        'scanned_at',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function parcelAssignment(): BelongsTo
    {
        return $this->belongsTo(ParcelAssignment::class);
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'scanned_by');
    }
}
