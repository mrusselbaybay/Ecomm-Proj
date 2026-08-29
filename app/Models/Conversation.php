<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A buyer <-> seller message thread.
 *
 * Cross-role: written by the buyer messaging endpoints (Buyer\MessageController)
 * and, on the seller branch, by /api/seller/messages/* controllers built
 * against this same table. Keep in sync across branches.
 */
class Conversation extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'conversations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'buyer_id',
        'seller_id',
        'order_id',
        'product_id',
        'subject',
        'status',
        'last_message_at',
        'last_message_preview',
        'last_message_sender_role',
        'buyer_unread_count',
        'seller_unread_count',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'buyer_unread_count' => 'integer',
        'seller_unread_count' => 'integer',
    ];

    public const STATUSES = ['open', 'resolved', 'archived'];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id')->orderBy('created_at');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'conversation_id')->latestOfMany();
    }
}
