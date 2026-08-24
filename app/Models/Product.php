<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $table = 'products';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'seller_id', 'name', 'description', 'category', 'sku',
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

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

<<<<<<< HEAD
    public function complianceActions(): HasMany
    {
        return $this->hasMany(SellerComplianceAction::class);
    }

=======
>>>>>>> bc83c9040949b88f3e15a7c6676765964c45bff3
    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('position');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * Products buyers are allowed to browse/purchase. Sellers can set
     * status to something other than 'active' (e.g. 'disabled') to hide a
     * listing without deleting it — see Seller\ProductController@destroy,
     * which soft-hides rather than hard-deletes for that reason.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
<<<<<<< HEAD
}
=======
}
>>>>>>> bc83c9040949b88f3e15a7c6676765964c45bff3
