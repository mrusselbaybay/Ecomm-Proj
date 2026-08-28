<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Merged the same way as Order.php (see that file's docblock). The
 * seller branch's copy of this file left `$incrementing` at Eloquent's
 * default (`true`) rather than the explicit `false` this project's other
 * uuid-PK models use — that happens to still populate `$model->id` after
 * insert (Postgres RETURNING doesn't care whether the key is numeric),
 * but only by accident, and inconsistently with how every other uuid-PK
 * model in this project (Product, Review, ...) does it deliberately via
 * HasUuidPrimaryKey. This uses that same trait for consistency.
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

    protected $fillable = [
        'order_id', 'status', 'note', 'changed_by',
    ];

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