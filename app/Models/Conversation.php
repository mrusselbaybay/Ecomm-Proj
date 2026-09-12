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
        'parcel_assignment_id',
        'logistics_company_id',
        'support_ticket_id',
        'subject',
        'status',
        'last_message_at',
        'last_message_preview',
        'last_message_sender_role',
        'buyer_unread_count',
        'seller_unread_count',
        'logistics_unread_count',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'buyer_unread_count' => 'integer',
        'seller_unread_count' => 'integer',
        'logistics_unread_count' => 'integer',
    ];

    public const STATUSES = ['open', 'active', 'resolved', 'closed', 'blocked', 'under_review'];

    public const WRITABLE_STATUSES = ['open', 'active'];

    // 'archived' is deliberately NOT part of this shared status graph — it's
    // a per-participant flag (see archiveFor()/unarchiveFor()) rather than a
    // real Conversation.status value, so one side archiving a thread can
    // never affect the other side's copy of it.
    public const DIRECT_MESSAGE_TRANSITIONS = [
        'open' => ['resolved'],
        'resolved' => ['open'],
    ];

    // What the buyer/seller/logistics `PUT .../status` endpoints accept as
    // input — a superset of DIRECT_MESSAGE_TRANSITIONS' keys because
    // 'archived' is a valid request value even though it never becomes a
    // real status (the controller intercepts it and calls archiveFor()
    // instead; see each MessageController::setStatus()).
    public const STATUS_REQUEST_VALUES = ['open', 'resolved', 'archived'];

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

    public function parcelAssignment(): BelongsTo
    {
        return $this->belongsTo(ParcelAssignment::class);
    }

    public function logisticsCompany(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class);
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
            ->withPivot(['joined_at', 'last_read_at', 'left_at', 'archived_at'])
            ->withTimestamps();
    }

    public function hasActiveParticipant(string $userId): bool
    {
        return $this->participantRecords()
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->exists();
    }

    /**
     * "Delete conversation" is per-user, not a real row delete — it stamps
     * left_at on that user's own participant record, which every
     * conversation-list/lookup query already filters on
     * (whereNull('left_at')), so it simply stops appearing for them without
     * touching the other participant's copy or the message history.
     */
    public function leaveFor(string $userId): void
    {
        $this->participantRecords()
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->update(['left_at' => now()]);
    }

    /**
     * Called whenever a new message lands — if a participant had
     * previously "deleted" (left) or "archived" this conversation, new
     * activity brings it back into their list rather than silently losing
     * the message (the same way Gmail un-archives a thread on a new reply).
     * Applies to every participant, sender included: if you'd archived a
     * thread and then decided to message into it anyway, that's as good a
     * signal as any that it shouldn't stay archived for you either.
     */
    public function reviveLeftParticipants(): void
    {
        $this->participantRecords()
            ->where(fn ($q) => $q->whereNotNull('left_at')->orWhereNotNull('archived_at'))
            ->update(['left_at' => null, 'archived_at' => null]);
    }

    /**
     * "Archive" is per-user, not a shared Conversation.status — the same
     * left_at pattern leaveFor() uses, on a separate column, so archiving a
     * thread on one side can never hide or block it on the other. Only
     * touches an active (non-left) participant row.
     */
    public function archiveFor(string $userId): void
    {
        $now = now();

        $this->participantRecords()
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->update(['archived_at' => $now]);

        $this->patchLoadedParticipant($userId, $now);
    }

    public function unarchiveFor(string $userId): void
    {
        $this->participantRecords()
            ->where('user_id', $userId)
            ->update(['archived_at' => null]);

        $this->patchLoadedParticipant($userId, null);
    }

    /**
     * archiveFor()/unarchiveFor() run a mass UPDATE, which doesn't touch an
     * already-loaded participantRecords collection — without this, a caller
     * that eager-loaded participantRecords before calling archiveFor() and
     * then reads isArchivedFor()/transformConversation() right after would
     * see the stale pre-update value, forcing a second full re-fetch just
     * to see its own write. Keeping the in-memory copy in sync here means
     * every setStatus() controller can respond with the same $conversation
     * instance it already has instead of a redundant ->fresh([...]) query.
     */
    private function patchLoadedParticipant(string $userId, ?\DateTimeInterface $archivedAt): void
    {
        if ($this->relationLoaded('participantRecords')) {
            $this->participantRecords->firstWhere('user_id', $userId)?->setAttribute('archived_at', $archivedAt);
        }
    }

    /**
     * Prefers an already-eager-loaded participantRecords collection (list/
     * detail endpoints constrain it to the current viewer, see the
     * MessageControllers) over an extra query.
     */
    public function isArchivedFor(string $userId): bool
    {
        if ($this->relationLoaded('participantRecords')) {
            return $this->participantRecords->firstWhere('user_id', $userId)?->archived_at !== null;
        }

        return $this->participantRecords()->where('user_id', $userId)->whereNotNull('archived_at')->exists();
    }

    public function isWritable(): bool
    {
        return in_array($this->status, self::WRITABLE_STATUSES, true);
    }

    public function canTransitionTo(string $status): bool
    {
        return $this->type !== 'support'
            && in_array($status, self::DIRECT_MESSAGE_TRANSITIONS[$this->status] ?? [], true);
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
