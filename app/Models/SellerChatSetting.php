<?php

namespace App\Models;

use Database\Factories\SellerChatSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerChatSetting extends Model
{
    /** @use HasFactory<SellerChatSettingFactory> */
    use HasFactory;

    protected $primaryKey = 'seller_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'seller_id',
        'auto_reply_enabled',
        'bot_mode',
        'presence_mode',
        'seller_status',
        'generic_away_response',
        'generic_reply_cooldown_minutes',
    ];

    protected $casts = [
        'auto_reply_enabled' => 'boolean',
        'generic_reply_cooldown_minutes' => 'integer',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }
}
