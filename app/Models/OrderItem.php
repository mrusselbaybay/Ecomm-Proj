<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Uses HasUuidPrimaryKey like Order/OrderStatusHistory/Product/Review —
 * order_items.id has a gen_random_uuid() default on pgsql, but assigning
 * the uuid in PHP before insert means the key is known in-memory right
 * away (and lets a DB without that default, e.g. the sqlite test DB,
 * still insert). The seller branch's copy of this model lacks the trait;
 * adding it is additive — see Order.php's docblock for the same note.
 */
class OrderItem extends Model
{
    use HasUuidPrimaryKey;

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

    // Added for the buyer backend. A buyer may open more than one request
    // for a line item over time (e.g. after a rejection), so this is a
    // hasMany; OrderController surfaces only the most recent one.
    public function returnRequests(): HasMany
    {
        return $this->hasMany(OrderReturnRequest::class, 'order_item_id');
    }
}
