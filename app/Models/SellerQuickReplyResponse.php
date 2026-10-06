<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\SellerQuickReplyResponseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerQuickReplyResponse extends Model
{
    /** @use HasFactory<SellerQuickReplyResponseFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['seller_id', 'question_key', 'response', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ChatQuickQuestion::class, 'question_key');
    }
}
