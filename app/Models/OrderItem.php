<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItem extends Model
{
    protected $table = 'order_items';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'order_id', 'product_id', 'product_name', 'category', 'sku',
        'variant', 'variant_id', 'variant_sku', 'variant_options',
        'unit_price', 'quantity', 'subtotal',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'quantity' => 'integer',
        'variant_options' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    // reviews.order_item_id is UNIQUE — at most one review per line item.
    public function review(): HasOne
    {
        return $this->hasOne(Review::class, 'order_item_id');
    }
}