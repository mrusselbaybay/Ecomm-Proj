<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A seller's report that a review on one of their products is
 * inappropriate. See the create_review_reports_table migration for why
 * this is a seller-facing flag + log rather than a full moderation
 * workflow.
 */
class ReviewReport extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'review_reports';

    public $incrementing = false;

    protected $keyType = 'string';

    public const REASONS = [
        'offensive_language',
        'spam',
        'personal_information',
        'off_topic',
        'false_information',
        'other',
    ];

    /** Terminal statuses an admin tool would set; 'pending' is the seller-created state. */
    public const STATUSES = ['pending', 'reviewed', 'dismissed', 'action_taken'];

    protected $fillable = [
        'review_id', 'seller_id', 'reason', 'details', 'status', 'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class, 'review_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'pending';
    }
}
