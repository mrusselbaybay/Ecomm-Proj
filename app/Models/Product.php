<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $seller_id
 * @property string $name
 * @property string|null $description
 * @property string|null $category
 * @property string|null $subcategory
 * @property string|null $brand
 * @property string|null $condition
 * @property string $price
 * @property list<array<string, mixed>>|null $images
 * @property string $status
 */
class Product extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'products';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'seller_id', 'name', 'description', 'category', 'subcategory', 'sku',
        'brand', 'condition', 'dimensions', 'weight', 'low_stock_threshold',
        'specifications',
        'price', 'compare_price', 'promo_code', 'stock', 'images', 'status',
        'has_variants',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'compare_price' => 'decimal:2',
        'stock' => 'integer',
        'low_stock_threshold' => 'integer',
        'weight' => 'decimal:3',
        'images' => 'array',
        'dimensions' => 'array',
        'specifications' => 'array',
        'has_variants' => 'boolean',
    ];

    /** @return BelongsTo<Profile, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    /** @return HasMany<SellerComplianceAction, $this> */
    public function complianceActions(): HasMany
    {
        return $this->hasMany(SellerComplianceAction::class);
    }

    /** @return HasMany<ProductModerationLog, $this> */
    public function moderationLogs(): HasMany
    {
        return $this->hasMany(ProductModerationLog::class);
    }

    /** @return HasOne<ProductModerationLog, $this> */
    public function latestModeration(): HasOne
    {
        return $this->hasOne(ProductModerationLog::class)->latest('created_at');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('position');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'product_id')->latest();
    }

    /**
     * Products buyers are allowed to browse/purchase. Sellers can set
     * status to something other than 'active' (e.g. 'disabled') to hide a
     * listing without deleting it.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // ---- Stock (added for inventory management; additive, buyer-safe) ----

    /** Fallback threshold when neither the product nor a variant sets one. */
    public const DEFAULT_LOW_STOCK_THRESHOLD = 10;

    public function lowStockThreshold(): int
    {
        return (int) ($this->low_stock_threshold ?? self::DEFAULT_LOW_STOCK_THRESHOLD);
    }

    /**
     * Available quantity. For a variant product this is the sum of its
     * ACTIVE variants' stock (the source of truth); products.stock is a
     * denormalised cache of the same number kept in sync by
     * InventoryService::syncProductStock().
     */
    public function effectiveStock(): int
    {
        if ($this->has_variants && $this->relationLoaded('variants')) {
            return (int) $this->variants
                ->where('status', 'active')
                ->sum('stock');
        }

        if ($this->has_variants) {
            return (int) $this->variants()->where('status', 'active')->sum('stock');
        }

        return (int) $this->stock;
    }

    public function isOutOfStock(): bool
    {
        return $this->effectiveStock() <= 0;
    }

    /** 'out_of_stock' | 'low_stock' | 'in_stock' */
    public function stockStatus(): string
    {
        $qty = $this->effectiveStock();

        if ($qty <= 0) {
            return 'out_of_stock';
        }

        return $qty <= $this->lowStockThreshold() ? 'low_stock' : 'in_stock';
    }
}
