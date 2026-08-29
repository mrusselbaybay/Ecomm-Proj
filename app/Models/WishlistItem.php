<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product a buyer has saved to their wishlist. Unique per
 * (buyer_profile_id, product_id). Buyer-only.
 */
class WishlistItem extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'buyer_wishlist_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'buyer_profile_id',
        'product_id',
    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'buyer_profile_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
