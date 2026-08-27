<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'reviews';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'product_id', 'seller_id', 'buyer_id', 'order_item_id',
        'product_name', 'rating', 'comment', 'images',
        'seller_response', 'responded_at', 'response_edited_at', 'responded_by',
    ];

    protected $casts = [
        'rating' => 'integer',
        'images' => 'array',
        'responded_at' => 'datetime',
        'response_edited_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'buyer_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'responded_by');
    }

    public function getIsRespondedAttribute(): bool
    {
        return !is_null($this->seller_response);
    }

    public function getIsEditedAttribute(): bool
    {
        return !is_null($this->response_edited_at);
    }
}