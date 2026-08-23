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
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function owner()
    {
        return $this->belongsTo(Profile::class, 'owner_profile_id', 'id');
    }

    public function applications()
    {
        return $this->hasMany(CourierApplication::class, 'logistics_company_id', 'id');
    }

    public function scopeActive($query)
    {
        return $query->where('account_status', 'active');
    }

    public function admins()
    {
        return $this->hasMany(LogisticsAdminDetail::class, 'logistics_company_id', 'id');
    }
}