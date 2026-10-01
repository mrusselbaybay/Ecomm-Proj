<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductCoupon extends Model
{
    use HasUuidPrimaryKey, SoftDeletes;

    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED = 'fixed';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'product_id', 'seller_id', 'code', 'discount_type', 'discount_value',
        'max_discount', 'usage_limit', 'used_count', 'expires_at', 'status',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isExhausted(): bool
    {
        return $this->usage_limit !== null && $this->used_count >= $this->usage_limit;
    }

    /** Active, not past expiry, not deleted, redemptions left. */
    public function isUsable(): bool
    {
        return ! $this->trashed()
            && $this->status === self::STATUS_ACTIVE
            && $this->expires_at->isFuture()
            && ! $this->isExhausted();
    }

    public function remaining(): ?int
    {
        return $this->usage_limit === null ? null : max(0, $this->usage_limit - $this->used_count);
    }

    /** "X left" urgency: remaining ≤ 20% of the limit, or ≤ 10 when 20% is too small to matter. */
    public function isRunningLow(): bool
    {
        $remaining = $this->remaining();

        if ($remaining === null || $remaining === 0) {
            return false;
        }

        $threshold = max((int) floor($this->usage_limit * 0.2), min(10, $this->usage_limit - 1));

        return $remaining <= $threshold;
    }

    /** Discount on ONE unit at $unitPrice, capped by max_discount and by the price itself. */
    public function discountFor(float $unitPrice): float
    {
        $discount = $this->discount_type === self::TYPE_PERCENTAGE
            ? round($unitPrice * (float) $this->discount_value / 100, 2)
            : (float) $this->discount_value;

        if ($this->discount_type === self::TYPE_PERCENTAGE && $this->max_discount !== null) {
            $discount = min($discount, (float) $this->max_discount);
        }

        return round(max(0.0, min($discount, $unitPrice)), 2);
    }

    public function label(): string
    {
        $value = rtrim(rtrim(number_format((float) $this->discount_value, 2, '.', ','), '0'), '.');

        return $this->discount_type === self::TYPE_PERCENTAGE ? "{$value}% OFF" : "₱{$value} OFF";
    }
}
