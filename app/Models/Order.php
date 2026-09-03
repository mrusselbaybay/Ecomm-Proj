<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Uses HasUuidPrimaryKey (see its docblock) because CheckoutService does
 * Order::create([...]) and immediately reads $order->id back to create the
 * OrderItem/OrderStatusHistory rows in the same transaction.
 */
class Order extends Model
{
    use HasUuidPrimaryKey;

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
        'confirmation_token',
        'cancellation_reason', 'cancelled_by', 'cancelled_at',
        'placed_at',
    ];

    /**
     * Prefix on the string encoded into the parcel confirmation QR, so a
     * scanner can tell one of our codes apart from arbitrary text before it
     * bothers hitting the API. The verify endpoint strips it back off.
     */
    public const QR_PAYLOAD_PREFIX = 'NXP:';

    protected $casts = [
        'subtotal' => 'decimal:2',
        'shipping_fee' => 'decimal:2',
        'tax' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'placed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public const PAYMENT_STATUSES = ['Unpaid', 'Paid', 'Refunded'];

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
        // 'Processing' ("being prepared by seller") deliberately isn't
        // reachable straight from 'New' — a seller must accept the order
        // (-> Confirmed) before it's considered accepted/in preparation.
        'New' => ['Confirmed', 'Cancelled', 'Rejected'],
        // 'In Transit' direct from Confirmed mirrors the Processing ->
        // In Transit edge below: Processing/Packed/Ready for Pickup are
        // optional granular checkpoints, not a required gate, so a seller
        // who accepts an order and packs/dispatches it in one sitting
        // (Seller > Prepare Orders) isn't forced through them one click
        // at a time first.
        'Confirmed' => ['Processing', 'In Transit', 'Cancelled', 'Rejected'],
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

    protected static function booted(): void
    {
        // The first time an order is dispatched — whichever path drives it
        // there (seller Prepare Orders / Courier Handover, logistics
        // intake, admin) — make sure it has a scannable parcel code and a
        // tracking number. Centralised here so no caller can reach 'In
        // Transit' without them, even if it skipped the Prepare Orders
        // "dispatch prep" step that normally fills these in up front.
        static::updating(function (self $order): void {
            $justShipped = $order->status === 'In Transit'
                && $order->getOriginal('status') !== 'In Transit';

            if (! $justShipped) {
                return;
            }

            if (blank($order->confirmation_token)) {
                $order->confirmation_token = self::generateConfirmationToken();
            }

            if (blank($order->tracking_number)) {
                $order->tracking_number = self::generateTrackingNumber();
            }
        });
    }

    /**
     * A URL-safe, unambiguous, single-use-per-order token (160 bits of
     * randomness as lowercase hex). The unique index on the column is the
     * real collision backstop; the retry loop just avoids a surprised
     * QueryException on the ~never.
     */
    public static function generateConfirmationToken(): string
    {
        do {
            $token = bin2hex(random_bytes(20));
        } while (static::where('confirmation_token', $token)->exists());

        return $token;
    }

    /**
     * A courier tracking number of the shape TRK-YYYYMMDD-NNNN, where NNNN
     * is that day's running count (zero-padded), e.g. TRK-20260903-0003.
     * Sellers never type this — it's assigned when an order is prepared
     * for dispatch. Bumps past any same-day collision so it stays unique.
     */
    public static function generateTrackingNumber(): string
    {
        $prefix = 'TRK-'.now()->format('Ymd').'-';
        $seq = static::where('tracking_number', 'like', $prefix.'%')->count() + 1;

        do {
            $candidate = $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $seq++;
        } while (static::where('tracking_number', $candidate)->exists());

        return $candidate;
    }

    /**
     * The exact string to encode into the parcel QR, or null if this order
     * hasn't been dispatched yet.
     */
    public function confirmationQrPayload(): ?string
    {
        return $this->confirmation_token
            ? self::QR_PAYLOAD_PREFIX.$this->confirmation_token
            : null;
    }

    /**
     * Pull the bare token out of a scanned string, tolerating a missing or
     * present QR_PAYLOAD_PREFIX and surrounding whitespace.
     */
    public static function normalizeScannedToken(string $scanned): string
    {
        return Str::of($scanned)->trim()->after(self::QR_PAYLOAD_PREFIX)->toString();
    }

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

    // Append-only log of parcel confirmation QR scans at each checkpoint
    // (newest first). See ParcelScanEvent and Driver\DriverDeliveryController.
    public function scanEvents(): HasMany
    {
        return $this->hasMany(ParcelScanEvent::class, 'order_id')->orderByDesc('scanned_at');
    }

    // The logistics-side handling of this order's parcel (sorting center
    // receipt, rider assignment, handoff) — at most one row per order
    // (parcel_assignments.order_id is unique). Null until the seller's
    // dispatch handover or a sorting-center scan creates it (see
    // ParcelIntakeService). Drives the "Parcel in Sorting Center" vs
    // "Parcel is out for delivery" split in SellerOrderController's
    // timeline — Order::status alone can't tell those two apart, only
    // "In Transit" either way.
    public function parcelAssignment(): HasOne
    {
        return $this->hasOne(ParcelAssignment::class, 'order_id');
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
