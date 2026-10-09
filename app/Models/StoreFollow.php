<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buyer following a store (a seller profile). Unique per
 * (buyer_profile_id, seller_id); written only by
 * Buyer\StoreFollowController.
 */
class StoreFollow extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'store_follows';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'buyer_profile_id',
        'seller_id',
    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'buyer_profile_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }
}
