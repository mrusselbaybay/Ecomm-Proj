<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ResignationRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'resignation_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'courier_profile_id',
        'logistics_company_id',
        'courier_application_id',
        'status',
        'letter_original_name',
        'letter_path',
        'letter_size',
        'reason',
        'decision_note',
        'reviewed_by',
        'reviewed_at',
        'submitted_at',
    ];

    protected $casts = [
        'letter_size' => 'integer',
        'reviewed_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            if (! $request->getKey()) {
                $request->{$request->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'courier_profile_id');
    }

    public function logisticsCompany(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id');
    }

    public function courierApplication(): BelongsTo
    {
        return $this->belongsTo(CourierApplication::class, 'courier_application_id');
    }
}
