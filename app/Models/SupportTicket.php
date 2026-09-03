<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\SupportTicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupportTicket extends Model
{
    /** @use HasFactory<SupportTicketFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    public const CATEGORIES = [
        'account',
        'order',
        'payment',
        'refund',
        'delivery',
        'product_seller',
        'compliance',
        'safety',
        'other',
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUSES = [
        'submitted',
        'open',
        'waiting_for_customer',
        'escalated',
        'resolved',
        'closed',
        'reopened',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'ticket_number',
        'created_by',
        'category',
        'subject',
        'description',
        'priority',
        'status',
        'order_id',
        'assigned_admin_id',
        'escalated_at',
        'resolved_at',
        'closed_at',
        'resolution_summary',
    ];

    protected $casts = [
        'escalated_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'created_by');
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'assigned_admin_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    public function internalNotes(): HasMany
    {
        return $this->hasMany(SupportTicketInternalNote::class);
    }
}
