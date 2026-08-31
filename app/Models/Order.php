<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $table = 'orders';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'order_number', 'seller_id', 'buyer_profile_id',
        'recipient_name', 'recipient_contact_no',
        'shipping_province_name', 'shipping_municipality_name',
        'shipping_barangay', 'shipping_street', 'shipping_house_no',
        'status', 'payment_method', 'payment_status',
        'subtotal', 'shipping_fee', 'tax', 'discount', 'total',
        'shipping_carrier', 'shipping_service', 'tracking_number',
        'cancellation_reason', 'cancelled_by', 'cancelled_at',
        'placed_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'shipping_fee' => 'decimal:2',
        'tax' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'placed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Fulfilment status flow
    |--------------------------------------------------------------------------
    |
    | ADDITIVE, compatible layer over the original 5 statuses. The stored
    | values buyer-checkout / logistics / admin already use are unchanged:
    | 'New' is still what a placed order is created as (shown as "Pending"
    | to the seller) and 'In Transit' is still what logistics sets (shown
    | as "Shipped"). 'Confirmed', 'Packed', 'Ready for Pickup' and
    | 'Rejected' are NEW intermediate/terminal states the seller drives.
    |
    |   Pending -> Confirmed -> Processing -> Packed -> Ready for Pickup
    |   -> Shipped -> Delivered              (+ Cancelled / Rejected)
    */
    public const STATUSES = [
        'New', 'Confirmed', 'Processing', 'Packed', 'Ready for Pickup',
        'In Transit', 'Delivered', 'Cancelled', 'Rejected',
    ];

    /** Seller-facing labels for the stored status values. */
    public const STATUS_LABELS = [
        'New' => 'Pending',
        'Confirmed' => 'Confirmed',
        'Processing' => 'Processing',
        'Packed' => 'Packed',
        'Ready for Pickup' => 'Ready for Pickup',
        'In Transit' => 'Shipped',
        'Delivered' => 'Delivered',
        'Cancelled' => 'Cancelled',
        'Rejected' => 'Rejected',
    ];

    // What an order in a given status may move to. A superset: it keeps
    // the original edges (e.g. Processing -> In Transit) so logistics /
    // legacy callers still work, and adds the granular seller path.
    public const ALLOWED_TRANSITIONS = [
        'New' => ['Confirmed', 'Processing', 'Cancelled', 'Rejected'],
        'Confirmed' => ['Processing', 'Cancelled', 'Rejected'],
        'Processing' => ['Packed', 'In Transit', 'Cancelled'],
        'Packed' => ['Ready for Pickup', 'In Transit', 'Cancelled'],
        'Ready for Pickup' => ['In Transit', 'Cancelled'],
        'In Transit' => ['Delivered'],
        'Delivered' => [],
        'Cancelled' => [],
        'Rejected' => [],
    ];

    // Statuses the SELLER is allowed to set. 'In Transit'/'Delivered' are
    // deliberately excluded — per the spec those belong to logistics.
    public const SELLER_SETTABLE_STATUSES = [
        'Confirmed', 'Processing', 'Packed', 'Ready for Pickup', 'Cancelled', 'Rejected',
    ];

    // Once here, an order never goes back to an earlier status.
    public const TERMINAL_STATUSES = ['Delivered', 'Cancelled', 'Rejected', 'Refunded'];

    // A seller may cancel or reject only this early — before fulfilment
    // has substantially started (spec: "pending or confirmed").
    public const SELLER_CANCELLABLE_FROM = ['New', 'Confirmed'];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'buyer_profile_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id')->orderBy('created_at');
    }

    // Added for the buyer backend (returns + messaging). Additive — no
    // existing relation, const, cast or method changed.
    public function returnRequests(): HasMany
    {
        return $this->hasMany(OrderReturnRequest::class, 'order_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'order_id');
    }

    // Live tracking pings (newest first). See OrderTrackingService.
    public function parcelLocations(): HasMany
    {
        return $this->hasMany(ParcelLocation::class, 'order_id')->orderByDesc('recorded_at');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }

    public function statusLabel(): string
    {
        return self::labelFor($this->status);
    }

    public static function labelFor(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function sellerMaySet(string $status): bool
    {
        return in_array($status, self::SELLER_SETTABLE_STATUSES, true);
    }

    public function sellerMayCancel(): bool
    {
        return in_array($this->status, self::SELLER_CANCELLABLE_FROM, true);
    }
}
