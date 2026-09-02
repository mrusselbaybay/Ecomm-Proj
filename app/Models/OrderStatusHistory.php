<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uses HasUuidPrimaryKey (see its docblock) for the same reason Order does:
 * the id column has no DB-level default outside Postgres, so leaving
 * $incrementing at Eloquent's default would insert a null primary key on
 * creation. Every other uuid-PK model in this project (Product, Review,
 * Order, ...) uses this trait deliberately for that reason.
 *
 * created_at is set explicitly in the creating hook below (the DB-level
 * `DEFAULT CURRENT_TIMESTAMP` is a fallback for any insert that bypasses
 * Eloquent) so it's available on the in-memory model immediately after
 * create() — this table has no updated_at column at all, hence
 * `$timestamps = false` rather than Eloquent's normal automatic
 * timestamp handling, which would try to write one.
 */
class OrderStatusHistory extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'order_status_history';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['order_id', 'status', 'previous_status', 'note', 'changed_by'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $history) {
            $history->created_at ??= now();
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'changed_by');
    }
}
