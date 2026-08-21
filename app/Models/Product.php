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
        'price', 'compare_price', 'promo_code', 'stock', 'images', 'status',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'compare_price' => 'decimal:2',
        'stock' => 'integer',
        'images' => 'array',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function complianceActions(): HasMany
    {
        return $this->hasMany(SellerComplianceAction::class);
    }
}
