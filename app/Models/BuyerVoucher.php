<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A voucher claimed into a buyer's wallet. Usage is counted from voucher_redemptions. */
class BuyerVoucher extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_USED = 'used';

    public const STATUS_EXPIRED = 'expired';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['buyer_profile_id', 'voucher_id', 'claimed_at'];

    protected $casts = ['claimed_at' => 'datetime'];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }
}
