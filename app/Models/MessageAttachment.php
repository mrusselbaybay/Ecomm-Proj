<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
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
     */
    public function toContractArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'mime' => $this->mime,
            'size' => $this->size,
        ];
    }
}
