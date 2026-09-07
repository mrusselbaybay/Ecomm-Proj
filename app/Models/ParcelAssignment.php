<?php

namespace App\Models;

use Database\Factories\ParcelAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class ParcelAssignment extends Model
{
    /** @use HasFactory<ParcelAssignmentFactory> */
    use HasFactory;

    public const STATUS_RECEIVED = 'received';

    public const STATUS_SORTED = 'sorted';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_HANDED_OFF = 'handed_off';

    // A picked-up parcel this company has asked another company to take
    // over (a pending row in parcel_transfer_requests). It sits here —
    // out of the local dispatch flow — until the receiving company
    // accepts (this row then closes as STATUS_TRANSFERRED) or rejects /
    // the request is cancelled (this row drops back to STATUS_HANDED_OFF
    // with no rider). See Api\Logistics\ParcelAssignmentController.
    public const STATUS_TRANSFER_PENDING = 'transfer_pending';

    // Terminal state for a parcel this company handed to another one.
    // Every parcel starts life the same way — received/sorted, waiting on
    // a courier to pick it up. Only once that courier has collected it
    // (handed_off, rider released) does dispatch decide between a local
    // delivery (assign()) and offering it to another company
    // (requestTransfer()). The offer is a transfer *request*: this row
    // parks at STATUS_TRANSFER_PENDING and only reaches STATUS_TRANSFERRED
    // once the receiving company accepts, at which point a fresh
    // handed_off row opens at that company, which delivers it from there.
    // No courier is involved in the handover itself.
    public const STATUS_TRANSFERRED = 'transferred';

    // "Currently in this rider's hands" for the quota counter — assigned
    // (dispatched, not yet physically with them) plus handed_off (they
    // have it, en route). Display-only (see Couriers.vue /
    // LogisticsApplicationResource) — never enforced as a hard cap.
    public const ACTIVE_STATUSES_FOR_QUOTA = [
        self::STATUS_ASSIGNED,
        self::STATUS_HANDED_OFF,
    ];

    public const COURIER_QUOTA = 20;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'order_id',
        'logistics_company_id',
        'delivery_area_id',
        'rider_profile_id',
        'picked_up_by',
        'status',
        'is_transfer',
        'transfer_to_company_id',
        'previous_assignment_id',
        'received_by',
        'assigned_by',
        'received_at',
        'scanned_at',
        'sorted_at',
        'assigned_at',
        'handed_off_at',
        'pickup_photo_path',
        'delivered_at',
        'delivery_photo_path',
        'transferred_at',
        'transfer_photo_path',
    ];

    protected $casts = [
        'is_transfer' => 'boolean',
        'received_at' => 'datetime',
        'scanned_at' => 'datetime',
        'sorted_at' => 'datetime',
        'assigned_at' => 'datetime',
        'handed_off_at' => 'datetime',
        'delivered_at' => 'datetime',
        'transferred_at' => 'datetime',
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

    public function pickedUpBy(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'picked_up_by');
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'rider_profile_id');
    }

    public function transferToCompany(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class, 'transfer_to_company_id');
    }

    public function transferRequests(): HasMany
    {
        return $this->hasMany(ParcelTransferRequest::class, 'parcel_assignment_id')
            ->orderByDesc('requested_at');
    }

    // The one request still awaiting an answer, if any — drives the
    // STATUS_TRANSFER_PENDING row's "requested X ago / cancel" affordance
    // (see ParcelAssignmentResource / ParcelOperations.vue). At most one
    // pending request per row exists (enforced in the controller);
    // plain orderBy()+HasOne rather than latestOfMany() because the
    // latter forces a MAX(id) tie-breaker and id is a uuid — Postgres
    // has no max(uuid) (same reasoning as Order::parcelAssignment()).
    public function pendingTransferRequest(): HasOne
    {
        return $this->hasOne(ParcelTransferRequest::class, 'parcel_assignment_id')
            ->where('status', ParcelTransferRequest::STATUS_PENDING)
            ->orderByDesc('requested_at');
    }

    // The row at the previous company, when this one exists because that
    // company transferred the parcel here.
    public function previousAssignment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_assignment_id');
    }

    /**
     * How many parcels are currently in this rider's hands — dispatched
     * (assigned) or physically with them (handed_off). Display-only
     * counter against ParcelAssignment::COURIER_QUOTA.
     */
    public static function activeCountFor(string $riderProfileId): int
    {
        return static::query()
            ->where('rider_profile_id', $riderProfileId)
            ->whereIn('status', self::ACTIVE_STATUSES_FOR_QUOTA)
            ->count();
    }

    /**
     * activeCountFor() for a whole roster in one grouped query, keyed by
     * rider profile id. Riders with nothing in hand are absent from the
     * result rather than present as 0, so read it with `?? 0`.
     *
     * Used by ParcelAutoAssignService, which needs the count for every
     * rider in an area on every parcel of a sweep — one query per rider
     * would be counts × parcels.
     *
     * @param  list<string>  $riderProfileIds
     * @return array<string, int>
     */
    public static function activeCountsFor(array $riderProfileIds): array
    {
        if ($riderProfileIds === []) {
            return [];
        }

        return static::query()
            ->whereIn('rider_profile_id', $riderProfileIds)
            ->whereIn('status', self::ACTIVE_STATUSES_FOR_QUOTA)
            ->groupBy('rider_profile_id')
            ->selectRaw('rider_profile_id, count(*) as aggregate')
            ->pluck('aggregate', 'rider_profile_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * The current (most recently created) row for a given order, scoped
     * to one company. Order::parcelAssignment() (latestOfMany) is the
     * right tool for "what's the current state of this order's parcel,
     * anywhere" — this is for the narrower "does *this* company already
     * have a row for it" check intake/receive need, now that a transfer
     * means an order can have more than one row over its lifetime.
     */
    public static function currentForOrderAndCompany(string $orderId, string $logisticsCompanyId): ?self
    {
        return static::query()
            ->where('order_id', $orderId)
            ->where('logistics_company_id', $logisticsCompanyId)
            ->latest('created_at')
            ->first();
    }

    /**
     * The current (most recently created) row for a given order, across
     * every company it has passed through — used where the caller only
     * has an order and needs to know which company currently holds it.
     */
    public static function currentForOrder(string $orderId): ?self
    {
        return static::query()
            ->where('order_id', $orderId)
            ->latest('created_at')
            ->first();
    }
}
