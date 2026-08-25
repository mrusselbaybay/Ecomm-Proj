<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductVariant extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'product_variants';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'product_id', 'sku', 'price', 'stock', 'image', 'status',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
        'image' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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

    /**
     * Effective selling price: the variant's own price if set, otherwise
     * falls back to the parent product's price.
     */
    public function effectivePrice(): float
    {
        return (float) ($this->price ?? $this->product->price);
    }
}