<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buyer's reusable checkout address (see the create_buyer_addresses
 * migration for why this is separate from the shared public.addresses
 * table). Buyer-only in practice.
 */
class BuyerAddress extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'buyer_addresses';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'buyer_profile_id',
        'recipient_name',
        'contact_no',
        'line1',
        'city',
        'province',
        'postal_code',
        'label',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public const LABELS = ['Home', 'Work', 'Other'];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'buyer_profile_id');
    }
}
