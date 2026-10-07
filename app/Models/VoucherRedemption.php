<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One voucher applied to one (seller) order. released_at = order cancelled, usage given back. */
class VoucherRedemption extends Model
{
    use HasUuidPrimaryKey;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['voucher_id', 'buyer_profile_id', 'order_id', 'amount', 'released_at', 'checkout_id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'released_at' => 'datetime',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }
}
