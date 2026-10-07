<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryAttempt extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['failed_at' => 'immutable_datetime', 'attempt_number' => 'integer'];

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'courier_id');
    }
}
