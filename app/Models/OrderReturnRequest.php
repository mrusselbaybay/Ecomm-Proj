<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A buyer's return/refund request against a delivered order item.
 *
 * Cross-role: created by the buyer (Buyer\ReturnController), later
 * read/approved by the seller and admin sides on their own branches. Keep
 * this file in sync across branches.
 */
class OrderReturnRequest extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'order_return_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'buyer_profile_id',
        'seller_id',
        'request_type',
        'reason',
        'other_reason',
        'details',
        'quantity',
        'estimated_amount',
        'refunded_amount',
        'return_shipping_fee',
        'evidence',
        'status',
        'resolution_note',
        'reviewed_by',
        'resolved_at',
        'returned_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'estimated_amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'return_shipping_fee' => 'decimal:2',
        'returned_at' => 'datetime',
        'evidence' => 'array',
        'resolved_at' => 'datetime',
    ];

    public const REQUEST_TYPES = ['return_and_refund', 'refund_only'];

    public const REASONS = ['damaged', 'wrong_item', 'incomplete', 'not_as_described', 'quality_issue', 'other'];

    public const TYPE_RETURN_AND_REFUND = 'return_and_refund';

    // Human label for the buyer's reason — shared by every portal that shows
    // why an item is being returned.
    public const REASON_LABELS = [
        'damaged' => 'Arrived damaged',
        'wrong_item' => 'Wrong item received',
        'incomplete' => 'Missing parts or items',
        'not_as_described' => 'Not as described',
        'quality_issue' => 'Quality issue',
        'other' => 'Other',
    ];

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'completed'];

    public function needsPhysicalReturn(): bool
    {
        return $this->request_type === self::TYPE_RETURN_AND_REFUND;
    }

    public function reasonLabel(): string
    {
        return $this->reason === 'other' && filled($this->other_reason)
            ? $this->other_reason
            : (self::REASON_LABELS[$this->reason] ?? (string) $this->reason);
    }

    public function parcelAssignments(): HasMany
    {
        return $this->hasMany(ParcelAssignment::class, 'return_request_id')->orderBy('created_at');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'buyer_profile_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }
}
