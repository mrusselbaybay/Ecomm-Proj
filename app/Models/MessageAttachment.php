<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\SupabaseStorageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staged / linked message attachment. See the
 * create_message_attachments_table migration for the two-step upload flow.
 */
class MessageAttachment extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'message_attachments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'seller_id',
        'message_id',
        'name',
        'mime',
        'size',
        'url',
        'path',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    /**
     * The shape the messaging API contract (useMessaging.js) expects back
     * from POST /messages/attachments and inside message.attachments[].
     * `url` is always a FRESH signed URL, generated here rather than
     * read back from the `url` column — message-attachments is a
     * private bucket, so a cached URL would go stale (or, before this,
     * a permanently-public one would have been a real privacy problem:
     * private conversation content readable by anyone with the link).
     * Also included in the `messages.attachments` snapshot this feeds
     * (see MessageController::sendMessage()) so transformMessage() can
     * re-sign it again on every later read of that same message,
     * however old.
     */
    public function toContractArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->signedUrl(),
            'path' => $this->path,
            'mime' => $this->mime,
            'size' => $this->size,
        ];
    }

    public function signedUrl(int $expiresInSeconds = 3600): ?string
    {
        if (! $this->path) {
            // A row from before this bucket went private, with no path
            // on record — nothing to sign, fall back to whatever's in
            // `url` rather than returning a dead link outright.
            return $this->url;
        }

        return app(SupabaseStorageService::class)
            ->createSignedUrl('message-attachments', $this->path, $expiresInSeconds);
    }
}
