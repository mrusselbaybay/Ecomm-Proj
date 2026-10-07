<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A voucher from a seller (seller-funded; shop | product scope) or from the
 * platform (platform-funded; platform | category scope, with buyer
 * eligibility and a distribution method). Only facts are stored; the
 * lifecycle status is derived on read (see status()), so nothing drifts.
 */
class Voucher extends Model
{
    use HasUuidPrimaryKey;

    public const TYPE_DISCOUNT = 'discount';

    public const TYPE_SHIPPING = 'shipping';

    public const SCOPE_SHOP = 'shop';

    public const SCOPE_PRODUCT = 'product';

    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_CATEGORY = 'category';

    public const SOURCE_SELLER = 'seller';

    public const SOURCE_PLATFORM = 'platform';

    public const ELIGIBILITY_ALL = 'all';

    public const ELIGIBILITY_NEW = 'new';

    public const ELIGIBILITY_LAPSED = 'lapsed';

    public const ELIGIBILITY_REGION = 'region';

    public const DISTRIBUTION_CLAIM = 'claim';

    public const DISTRIBUTION_AUTO_CLAIM = 'auto_claim';

    public const DISTRIBUTION_PUSH = 'push';

    public const DISTRIBUTION_AUTO_APPLY = 'auto_apply';

    public const DEFAULT_LAPSED_DAYS = 60;

    public const MAX_CATEGORIES = 50;

    public const DISCOUNT_PERCENTAGE = 'percentage';

    public const DISCOUNT_FIXED = 'fixed';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_DEACTIVATED = 'deactivated';

    public const STATUS_FULLY_REDEEMED = 'fully_redeemed';

    public const STATUS_EXPIRED = 'expired';

    /** Category voucher whose categories have nothing in stock right now. */
    public const STATUS_UNAVAILABLE = 'unavailable';

    public const MAX_PRODUCTS = 500;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'seller_id', 'code', 'type', 'scope', 'discount_type', 'discount_value', 'max_discount', 'min_spend',
        'starts_at', 'expires_at', 'usage_limit', 'used_count', 'per_user_limit', 'budget_cap', 'budget_used',
        'stackable', 'funding_source', 'deactivated_at', 'source', 'eligibility', 'eligibility_meta',
        'distribution', 'created_by', 'deactivated_by',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'min_spend' => 'decimal:2',
        'budget_cap' => 'decimal:2',
        'budget_used' => 'decimal:2',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
        'per_user_limit' => 'integer',
        'stackable' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'eligibility_meta' => 'array',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'created_by');
    }

    public function deactivator(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'deactivated_by');
    }

    public function shopName(): string
    {
        return $this->seller?->sellerDetail?->business_name ?? $this->seller?->full_name ?? 'Shop';
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'voucher_products');
    }

    /** @return list<string> categories of a category-scoped voucher */
    public function categoryNames(): array
    {
        return DB::table('voucher_categories')->where('voucher_id', $this->id)->orderBy('category')->pluck('category')->all();
    }

    public function isPlatform(): bool
    {
        return $this->source === self::SOURCE_PLATFORM;
    }

    /**
     * A category voucher with nothing buyable in its categories. Uses the
     * `has_available_products` column when the query selected it (see
     * scopeWithAvailability), else asks the database.
     */
    public function isUnavailable(): bool
    {
        if ($this->scope !== self::SCOPE_CATEGORY) {
            return false;
        }

        if (array_key_exists('has_available_products', $this->attributes)) {
            return ! $this->attributes['has_available_products'];
        }

        return ! DB::table('products')
            ->join('voucher_categories as vc', 'vc.category', '=', 'products.category')
            ->where('vc.voucher_id', $this->id)
            ->where('products.status', 'active')
            ->where('products.stock', '>', 0)
            ->exists();
    }

    /** Correlated subquery: products in the outer voucher's categories that can be bought now. */
    private static function availableProductsSql(): \Closure
    {
        return fn ($q) => $q->selectRaw('1')->from('products')
            ->join('voucher_categories as vc', 'vc.category', '=', 'products.category')
            ->whereColumn('vc.voucher_id', 'vouchers.id')
            ->where('products.status', 'active')
            ->where('products.stock', '>', 0);
    }

    /** Selects has_available_products so status() needs no extra query per row. */
    public function scopeWithAvailability($query)
    {
        if ($query->getQuery()->columns === null) {
            $query->select('vouchers.*');
        }

        return $query->selectRaw('case when exists ('
            .'select 1 from products inner join voucher_categories as vc on vc.category = products.category '
            ."where vc.voucher_id = vouchers.id and products.status = 'active' and products.stock > 0"
            .') then 1 else 0 end as has_available_products');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(VoucherRedemption::class);
    }

    public function status(): string
    {
        return match (true) {
            $this->expires_at->isPast(), $this->isBudgetExhausted() => self::STATUS_EXPIRED,
            $this->deactivated_at !== null => self::STATUS_DEACTIVATED,
            $this->isExhausted() => self::STATUS_FULLY_REDEEMED,
            $this->isUnavailable() => self::STATUS_UNAVAILABLE,
            $this->starts_at->isFuture() => self::STATUS_SCHEDULED,
            default => self::STATUS_ACTIVE,
        };
    }

    /**
     * SQL mirror of status(). Also: 'live' (Active tab: active, scheduled,
     * fully redeemed, unavailable) and 'inactive' (Inactive tab: expired,
     * deactivated). 'active' includes scheduled.
     */
    public function scopeWhereStatus($query, string $status)
    {
        $now = now();
        $notExpired = fn ($q) => $q->where('expires_at', '>', $now)
            ->where(fn ($q) => $q->whereNull('budget_cap')->orWhereColumn('budget_used', '<', 'budget_cap'));
        $exhausted = fn ($q) => $q->whereNotNull('usage_limit')->whereColumn('used_count', '>=', 'usage_limit');
        $live = fn ($q) => $q->where($notExpired)->whereNull('deactivated_at');
        $unavailable = fn ($q) => $q->where('scope', self::SCOPE_CATEGORY)->whereNotExists(self::availableProductsSql());

        return match ($status) {
            self::STATUS_EXPIRED => $query->whereNot($notExpired),
            self::STATUS_DEACTIVATED => $query->where($notExpired)->whereNotNull('deactivated_at'),
            self::STATUS_FULLY_REDEEMED => $query->where($live)->where($exhausted),
            self::STATUS_ACTIVE => $query->where($live)->whereNot($exhausted)->whereNot($unavailable),
            self::STATUS_UNAVAILABLE => $query->where($live)->whereNot($exhausted)->where($unavailable),
            'live' => $query->where($live),
            'inactive' => $query->whereNot($live),
        };
    }

    public function isUsable(): bool
    {
        return $this->status() === self::STATUS_ACTIVE;
    }

    /** Budget-exhausted vouchers expire the moment the budget runs out (last redemption). */
    public function expiredOn(): Carbon
    {
        return $this->expires_at->isPast() ? $this->expires_at : $this->updated_at;
    }

    public function isExhausted(): bool
    {
        return $this->usage_limit !== null && $this->used_count >= $this->usage_limit;
    }

    public function budgetRemaining(): ?float
    {
        return $this->budget_cap === null ? null : max(0.0, round((float) $this->budget_cap - (float) $this->budget_used, 2));
    }

    public function isBudgetExhausted(): bool
    {
        return $this->budget_cap !== null && $this->budgetRemaining() <= 0;
    }

    public function isProductScoped(): bool
    {
        return $this->scope === self::SCOPE_PRODUCT;
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

    /**
     * Discount on $base (eligible goods subtotal, or the shipping fee),
     * capped by max_discount, the base itself and what's left of the budget.
     */
    public function discountOn(float $base): float
    {
        $discount = $this->discount_type === self::DISCOUNT_PERCENTAGE
            ? round($base * (float) $this->discount_value / 100, 2)
            : (float) $this->discount_value;

        if ($this->discount_type === self::DISCOUNT_PERCENTAGE && $this->max_discount !== null) {
            $discount = min($discount, (float) $this->max_discount);
        }

        $budget = $this->budgetRemaining();

        return round(max(0.0, min($discount, $base, $budget ?? INF)), 2);
    }

    public function label(): string
    {
        $value = rtrim(rtrim(number_format((float) $this->discount_value, 2, '.', ','), '0'), '.');
        $off = $this->discount_type === self::DISCOUNT_PERCENTAGE ? "{$value}% OFF" : "₱{$value} OFF";

        if ($this->type === self::TYPE_SHIPPING) {
            return $this->discount_type === self::DISCOUNT_PERCENTAGE && (float) $this->discount_value >= 100
                ? 'Free Shipping'
                : "{$off} Shipping";
        }

        return $off;
    }
}
