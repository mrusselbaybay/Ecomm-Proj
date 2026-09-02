<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buyer's saved checkout payment method. Only tokenised/non-sensitive
 * fields are stored (see the create_buyer_payment_methods migration).
 * Buyer-only.
 */
class BuyerPaymentMethod extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'buyer_payment_methods';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'buyer_profile_id',
        'type',
        'brand',
        'last4',
        'holder',
        'exp_month',
        'exp_year',
        'provider',
        'phone_masked',
        'label',
        'provider_token',
        'is_primary',
    ];

    protected $casts = [
        'exp_year' => 'integer',
        'is_primary' => 'boolean',
    ];

    protected $hidden = [
        'provider_token',
    ];

    public const TYPES = ['card', 'wallet'];

    public const WALLET_PROVIDERS = ['GCash', 'Maya'];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'buyer_profile_id');
    }
}
