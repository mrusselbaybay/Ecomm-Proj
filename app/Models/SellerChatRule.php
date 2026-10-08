<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\SellerChatRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerChatRule extends Model
{
    /** @use HasFactory<SellerChatRuleFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'seller_id', 'match_type', 'question_key', 'keyword',
        'normalized_keyword', 'response_template', 'is_active', 'priority',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ChatQuickQuestion::class, 'question_key');
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(ChatAutomationSuggestion::class, 'rule_id');
    }
}
