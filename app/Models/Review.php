<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'reviews';

    /**
     * The one definition of a review that counts toward a public rating:
     * it is still attached to a product (a deleted product's reviews are
     * kept but no longer product reviews) and carries a valid 1–5 rating.
     * There is no moderation step in this schema, so every such review is
     * public as soon as it is saved. Raw-SQL twin of scopeEligible(), for
     * correlated sub-selects written as SQL strings.
     */
    public const ELIGIBLE_SQL = 'reviews.product_id is not null and reviews.rating between 1 and 5';

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

    /**
     * Reviews that count toward ratings and appear publicly (ELIGIBLE_SQL).
     * Product cards, product pages, store ratings and the rating filters
     * and sorts all start from this scope, so they always agree.
     */
    public function scopeEligible(Builder $query): Builder
    {
        return $query->whereNotNull('reviews.product_id')->whereBetween('reviews.rating', [1, 5]);
    }

    /**
     * Reviews with at least one photo. A review saved without photos stores
     * an empty list ("[]"), not NULL, so a NULL check alone counts it.
     */
    public function scopeWithPhotos(Builder $query): Builder
    {
        return $query->whereNotNull('reviews.images')
            ->whereRaw("cast(reviews.images as text) not in ('[]', 'null', '')");
    }

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
        return ! is_null($this->seller_response);
    }

    public function getIsEditedAttribute(): bool
    {
        return ! is_null($this->response_edited_at);
    }
}
