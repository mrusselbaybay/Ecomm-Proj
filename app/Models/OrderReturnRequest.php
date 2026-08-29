<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'details',
        'quantity',
        'estimated_amount',
        'evidence',
        'status',
        'resolution_note',
        'reviewed_by',
        'resolved_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'estimated_amount' => 'decimal:2',
        'evidence' => 'array',
        'resolved_at' => 'datetime',
    ];

    public const REQUEST_TYPES = ['return_and_refund', 'refund_only'];

    public const REASONS = ['damaged', 'wrong_item', 'incomplete', 'not_as_described', 'quality_issue', 'other'];

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'completed'];

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
