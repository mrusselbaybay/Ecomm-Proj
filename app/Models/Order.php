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
        'placed_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'shipping_fee' => 'decimal:2',
        'tax' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'placed_at' => 'datetime',
    ];

    // Statuses a seller is allowed to move an order INTO, keyed by the
    // status the order currently has to be in. Used to reject invalid
    // transitions (e.g. skipping straight from "New" to "Delivered").
    public const ALLOWED_TRANSITIONS = [
        'New' => ['Processing', 'Cancelled'],
        'Processing' => ['In Transit', 'Cancelled'],
        'In Transit' => ['Delivered'],
        'Delivered' => [],
        'Cancelled' => [],
    ];

    public const STATUSES = ['New', 'Processing', 'In Transit', 'Delivered', 'Cancelled'];

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

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }
}
