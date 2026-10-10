<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $complainant_id
 * @property string|null $respondent_id
 * @property string|null $order_id
 * @property string|null $assigned_admin_id
 * @property string $type
 * @property string $subject
 * @property string $description
 * @property list<string>|null $evidence
 * @property string $status
 * @property string $priority
 * @property string|null $resolution
 * @property Carbon|null $resolved_at
 * @property-read Profile $complainant
 * @property-read Profile|null $respondent
 * @property-read Profile|null $assignedAdmin
 * @property-read Order|null $order
 * @property-read Collection<int, ComplaintUpdate> $updates
 */
class Complaint extends Model
{
    use HasUuidPrimaryKey;

    public const STATUSES = ['pending', 'under_review', 'awaiting_response', 'resolved', 'dismissed'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const ALLOWED_TRANSITIONS = [
        'pending' => ['under_review', 'resolved', 'dismissed'],
        'under_review' => ['awaiting_response', 'resolved', 'dismissed'],
        'awaiting_response' => ['under_review', 'resolved', 'dismissed'],
        'resolved' => [],
        'dismissed' => [],
    ];

    public const DISMISSAL_REASONS = ['no_violation', 'duplicate', 'insufficient_evidence', 'out_of_scope', 'other'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'complainant_id', 'respondent_id', 'order_id', 'assigned_admin_id',
        'target_product_id', 'target_store_id', 'report_reason',
        'reporter_is_anonymous', 'reporter_name_snapshot', 'target_name_snapshot',
        'seller_name_snapshot', 'dismissal_reason',
        'reporter_dismissed_count', 'urgent_review_due_at',
        'type', 'subject', 'description', 'evidence', 'status', 'priority',
        'resolution', 'resolved_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'reporter_is_anonymous' => 'boolean',
        'urgent_review_due_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /** @return BelongsTo<Profile, $this> */
    public function complainant(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'complainant_id');
    }

    /** @return BelongsTo<Profile, $this> */
    public function respondent(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'respondent_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function targetProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'target_product_id');
    }

    public function targetStore(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'target_store_id');
    }

    /** @return BelongsTo<Profile, $this> */
    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'assigned_admin_id');
    }

    /** @return HasMany<ComplaintUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(ComplaintUpdate::class)->latest();
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }
}
