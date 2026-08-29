<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Merged from two branches that each independently wrote this file (it
 * didn't exist at all in the buyer branch's zip — see the git history /
 * earlier notes on CheckoutService, Buyer\CheckoutController and
 * Buyer\OrderController all fatally referencing a missing class):
 *
 *   - Seller branch: ALLOWED_TRANSITIONS / canTransitionTo() — real logic
 *     Seller\SellerOrderController relies on to reject invalid status
 *     jumps (e.g. New -> Delivered).
 *   - Buyer branch: HasUuidPrimaryKey — this project's own established
 *     trait for uuid-PK models (see its docblock), used by Product and
 *     friends. Needed here because CheckoutService does
 *     `Order::create([...])` and immediately reads `$order->id` back to
 *     create the OrderItem/OrderStatusHistory rows in the same
 *     transaction. The seller branch's copy of this file never needed
 *     that: it only ever reads/updates existing orders, never creates
 *     one, so the gap was invisible on that branch alone.
 */
class Order extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'orders';

    public $incrementing = false;

    protected $keyType = 'string';

    public const STATUSES = ['New', 'Processing', 'In Transit', 'Delivered', 'Cancelled'];

    public const PAYMENT_STATUSES = ['Unpaid', 'Paid', 'Refunded'];

    // Statuses a seller is allowed to move an order INTO, keyed by the
    // status the order currently has to be in. Used to reject invalid
    // transitions (e.g. skipping straight from "New" to "Delivered") —
    // see canTransitionTo() and Seller\SellerOrderController.
    public const ALLOWED_TRANSITIONS = [
        'New' => ['Processing', 'Cancelled'],
        'Processing' => ['In Transit', 'Cancelled'],
        'In Transit' => ['Delivered'],
        'Delivered' => [],
        'Cancelled' => [],
    ];

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

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id')->orderBy('created_at');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'buyer_profile_id');
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
