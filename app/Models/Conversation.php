<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'type',
        'created_by',
        'context_key',
        'buyer_id',
        'seller_id',
        'order_id',
        'product_id',
        'support_ticket_id',
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

    public const STATUSES = ['open', 'active', 'resolved', 'archived', 'closed', 'blocked', 'under_review'];

    public const WRITABLE_STATUSES = ['open', 'active'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'created_by');
    }

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

    public function supportTicket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }

    public function participantRecords(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'conversation_participants', 'conversation_id', 'user_id')
            ->withPivot(['joined_at', 'last_read_at', 'left_at'])
            ->withTimestamps();
    }

    public function hasActiveParticipant(string $userId): bool
    {
        return $this->participantRecords()
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->exists();
    }

    public function isWritable(): bool
    {
        return in_array($this->status, self::WRITABLE_STATUSES, true);
    }

    /**
     * @param  array<int, string>  $participantIds
     */
    public static function makeContextKey(string $type, string $contextId, array $participantIds): string
    {
        sort($participantIds);

        return hash('sha256', $type.':'.$contextId.':'.implode(':', $participantIds));
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id')->orderBy('created_at');
    }

    // latestOfMany() defaults to MAX(id); ids are uuids and Postgres has
    // no max(uuid), so this must aggregate on created_at. (The list/detail
    // payloads read the denormalised last_message_* columns instead and
    // don't load this relation — it's kept for ad-hoc use.)
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'conversation_id')->latestOfMany('created_at');
    }
}
