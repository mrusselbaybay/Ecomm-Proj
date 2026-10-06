<?php

namespace App\Models;

use Database\Factories\ChatQuickQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatQuickQuestion extends Model
{
    /** @use HasFactory<ChatQuickQuestionFactory> */
    use HasFactory;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'question', 'default_response', 'context_type', 'sort_order', 'enabled'];

    protected $casts = [
        'sort_order' => 'integer',
        'enabled' => 'boolean',
    ];

    public function rules(): HasMany
    {
        return $this->hasMany(SellerChatRule::class, 'question_key');
    }
}
