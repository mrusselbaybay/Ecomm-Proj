<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An admin's verified-seller grant (or its revocation), one row per
 * seller. Distinct from account approval. Written only by
 * Admin\SellerVerificationController; see the create-table migration for
 * why it isn't a column on profiles / seller_details.
 */
class SellerVerification extends Model
{
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'seller_verifications';

    protected $primaryKey = 'seller_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'seller_id',
        'status',
        'verified_by',
        'verified_at',
        'revoked_by',
        'revoked_at',
        'note',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'verified_by');
    }
}
