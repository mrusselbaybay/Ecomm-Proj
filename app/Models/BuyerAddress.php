<?php

namespace App\Models;

use App\Models\Concerns\HasMapPin;
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
    use HasMapPin, HasUuidPrimaryKey;

    protected $table = 'buyer_addresses';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'buyer_profile_id',
        'recipient_name',
        'contact_no',
        'house_no',
        'line1',
        'region_name',
        'province_code',
        'province',
        'municipality_code',
        'city',
        'barangay',
        'postal_code',
        'label',
        'is_default',
        'last_used_at',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public const REGIONS = ['Luzon', 'Visayas', 'Mindanao'];

    /** Has the structured PSGC destination checkout needs to route an order. */
    public function isRoutable(): bool
    {
        return filled($this->province_code) && filled($this->municipality_code) && filled($this->barangay);
    }

    public const LABELS = ['Home', 'Work', 'Other'];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'buyer_profile_id');
    }
}
