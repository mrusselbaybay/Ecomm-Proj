<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'product_variants';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'product_id', 'sku', 'price', 'stock', 'low_stock_threshold', 'image', 'status',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
        'low_stock_threshold' => 'integer',
        'image' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'variant_id')->latest();
    }

    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductOptionValue::class,
            'product_variant_option_values',
            'product_variant_id',
            'product_option_value_id',
        );
    }

    public function isPurchasable(): bool
    {
        return $this->status === 'active' && $this->stock > 0;
    }

    public function isOutOfStock(): bool
    {
        return (int) $this->stock <= 0;
    }

    /** The variant's own threshold, else the product's, else the default. */
    public function effectiveLowStockThreshold(): int
    {
        if ($this->low_stock_threshold !== null) {
            return (int) $this->low_stock_threshold;
        }

        return $this->product?->lowStockThreshold() ?? Product::DEFAULT_LOW_STOCK_THRESHOLD;
    }

    /** 'out_of_stock' | 'low_stock' | 'in_stock' */
    public function stockStatus(): string
    {
        $qty = (int) $this->stock;

        if ($qty <= 0) {
            return 'out_of_stock';
        }

        return $qty <= $this->effectiveLowStockThreshold() ? 'low_stock' : 'in_stock';
    }

    /**
     * Effective selling price: the variant's own price if set, otherwise
     * falls back to the parent product's price.
     */
    public function effectivePrice(): float
    {
        return (float) ($this->price ?? $this->product->price);
    }
}
