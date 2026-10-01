<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuyerCoupon extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_USED = 'used';
    public const STATUS_EXPIRED = 'expired';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'buyer_profile_id', 'product_coupon_id', 'status', 'order_item_id', 'claimed_at', 'used_at',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /** Includes soft-deleted coupons so the wallet can still show them as unusable. */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(ProductCoupon::class, 'product_coupon_id')->withTrashed();
    }

    /** A deleted/expired/exhausted coupon reads as "expired" even before the worker runs. */
    public function effectiveStatus(): string
    {
        if ($this->status !== self::STATUS_AVAILABLE) {
            return $this->status;
        }

        return $this->coupon && $this->coupon->isUsable() ? self::STATUS_AVAILABLE : self::STATUS_EXPIRED;
    }
}
