<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the append-only stock audit log. Created only by
 * App\Services\InventoryService. See the create_inventory_movements
 * migration for the column meanings.
 */
class InventoryMovement extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'inventory_movements';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'seller_id',
        'product_id',
        'variant_id',
        'order_id',
        'movement_type',
        'reason',
        'note',
        'quantity_before',
        'quantity_change',
        'quantity_after',
        'actor_id',
        'actor_type',
    ];

    protected $casts = [
        'quantity_before' => 'integer',
        'quantity_change' => 'integer',
        'quantity_after' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'actor_id');
    }
}
