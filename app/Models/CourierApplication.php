<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CourierApplication extends Model
{
    use HasFactory;

    protected $table = 'courier_applications';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'id',
        'courier_profile_id',
        'logistics_company_id',
        'status',
        'resume_original_name',
        'resume_path',
        'resume_size',
        'license_original_name',
        'license_path',
        'license_size',
        'cover_note',
        'applied_at',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'interview_invited_at',
        'interview_scheduled_at',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'resume_size' => 'integer',
        'license_size' => 'integer',
        'interview_invited_at' => 'datetime',
        'interview_scheduled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $application): void {
            if (! $application->getKey()) {
                $application->{$application->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    // ✅ Add this relationship for the Vue component
    public function courier()
    {
        return $this->belongsTo(Profile::class, 'courier_profile_id', 'id');
    }

    // ✅ Keep this for backward compatibility
    public function user()
    {
        return $this->belongsTo(Profile::class, 'courier_profile_id', 'id');
    }

    public function company()
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id');
    }

    // Alias of company() - Api\Courier\CourierApplicationController eager-loads
    // this name specifically; kept separate from company() so existing callers
    // (e.g. PickupCourierController) don't need to change.
    public function logisticsCompany()
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id');
    }

    public function courier_details()
    {
        return $this->hasOne(CourierDetail::class, 'profile_id', 'courier_profile_id');
    }

    /**
     * An "active" application is one that still blocks a new application
     * to the same company (everything except withdrawn).
     */
    public function scopeActive($query)
    {
        return $query->where('status', '!=', self::STATUS_WITHDRAWN);
    }
}