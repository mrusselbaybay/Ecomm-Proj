<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EscrowTransaction extends Model
{
    use HasUuidPrimaryKey;

    public const TYPE_CHARGE = 'charge';
    public const TYPE_RELEASE = 'release';
    public const TYPE_REFUND = 'refund';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['order_id', 'type', 'amount_cents', 'idempotency_key', 'meta'];

    protected $casts = ['amount_cents' => 'integer', 'meta' => 'array'];

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
