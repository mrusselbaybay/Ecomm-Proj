<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
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
    use HasUuids;

    protected $table = 'products';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'seller_id', 'name', 'description', 'category', 'sku',
        'price', 'compare_price', 'promo_code', 'stock', 'images', 'status',
        'brand', 'condition', 'dimensions', 'weight', 'low_stock_threshold',
        'has_variants', 'specifications', 'subcategory',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'compare_price' => 'decimal:2',
        'stock' => 'integer',
        'images' => 'array',
        'dimensions' => 'array',
        'weight' => 'decimal:3',
        'low_stock_threshold' => 'integer',
        'has_variants' => 'boolean',
        'specifications' => 'array',
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
}
