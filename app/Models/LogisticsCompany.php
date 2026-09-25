<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogisticsCompany extends Model
{
    protected $table = 'logistics_companies';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'owner_profile_id',
        'company_name',
        'company_email',
        'company_contact_no',
        'tin',
        'sec_registration',
        'region',
        'status',
        'account_status',
        'description',
        'monthly_salary',
        'is_hiring',
        // Company-wide "Auto assign" rotation cursor — see
        // App\Services\ParcelAutoAssignService's fallback pool.
        'last_auto_assigned_rider_profile_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'monthly_salary' => 'decimal:2',
        'is_hiring' => 'boolean',
    ];

    public function owner()
    {
        return $this->belongsTo(Profile::class, 'owner_profile_id', 'id');
    }

    public function address()
    {
        return $this->hasOne(Address::class, 'logistics_company_id')
            ->where('owner_kind', 'logistics_company');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'logistics_company_id')
            ->where('owner_kind', 'logistics_company');
    }

    public function applications()
    {
        return $this->hasMany(CourierApplication::class, 'logistics_company_id', 'id');
    }

    public function scopeActive($query)
    {
        return $query->where('account_status', 'active');
    }

    /**
     * The company this profile works for — as owner, or as an active
     * (non-suspended) team member.
     */
    public function scopeForMember($query, string $profileId)
    {
        return $query->where(fn ($q) => $q
            ->where('owner_profile_id', $profileId)
            ->orWhereIn('id', LogisticsAdminDetail::query()
                ->select('logistics_company_id')
                ->where('profile_id', $profileId)
                ->where('status', 'active')));
    }

    public function admins()
    {
        return $this->hasMany(LogisticsAdminDetail::class, 'logistics_company_id', 'id');
    }

    public function invitations()
    {
        return $this->hasMany(LogisticsInvitation::class, 'logistics_company_id', 'id');
    }
}
