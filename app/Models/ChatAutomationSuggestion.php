<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\ChatAutomationSuggestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatAutomationSuggestion extends Model
{
    /** @use HasFactory<ChatAutomationSuggestionFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'conversation_id', 'source_message_id', 'rule_id',
        'suggested_response', 'status', 'used_at',
    ];

    protected $casts = ['used_at' => 'datetime'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(SellerChatRule::class, 'rule_id');
    }
}
