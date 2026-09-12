<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

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
        'uploader_id',
        'message_id',
        'name',
        'mime',
        'size',
        'url',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    /** @return BelongsTo<Profile, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    /** @return BelongsTo<Profile, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'uploader_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    /**
     * The shape the messaging API contract (useMessaging.js) expects back
     * from POST /messages/attachments and inside message.attachments[].
     *
     * @return array{id: string, name: string, url: string, mime: string, size: int}
     */
    public function toContractArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->contractUrl(),
            'mime' => $this->mime,
            'size' => $this->size,
        ];
    }

    /**
     * @return array{id: string, name: string, url: string, mime: string, size: int}
     */
    public function toStoredArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'mime' => $this->mime,
            'size' => $this->size,
        ];
    }

    public function contractUrl(): string
    {
        if (str_starts_with($this->url, 'data:') || str_starts_with($this->url, 'http')) {
            return $this->url;
        }

        return URL::temporarySignedRoute(
            'message-attachments.show',
            now()->addMinutes(15),
            ['attachment' => $this->id],
        );
    }

    /** @param array{id?: mixed, url?: mixed} $attachment */
    public static function contractUrlFor(array $attachment): ?string
    {
        $url = $attachment['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        if (str_starts_with($url, 'data:') || str_starts_with($url, 'http')) {
            return $url;
        }

        $id = $attachment['id'] ?? null;

        return is_string($id) && $id !== ''
            ? URL::temporarySignedRoute('message-attachments.show', now()->addMinutes(15), ['attachment' => $id])
            : null;
    }
}
