<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item in a seller's notification inbox. Created only by
 * App\Services\SellerNotifier (after the related transaction commits).
 */
class SellerNotification extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'seller_notifications';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'seller_id',
        'type',
        'title',
        'body',
        'data',
        'order_id',
        'dedupe_key',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }
}
