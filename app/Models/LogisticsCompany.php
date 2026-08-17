<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogisticsCompany extends Model
{
    protected $table = 'logistics_companies';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'owner_profile_id',
        'company_name',
        'company_email',
        'company_contact_no',
        'tin',
        'sec_registration',
        'status',
        'account_status',
    ];

    public function owner()
    {
        return $this->belongsTo(Profile::class, 'owner_profile_id');
    }

    public function applications()
    {
        return $this->hasMany(CourierApplication::class, 'logistics_company_id');
    }

    public function couriers()
    {
        return $this->hasMany(CourierDetail::class, 'logistics_company_id');
    }

    public function drivers()
    {
        return $this->hasMany(DriverDetail::class, 'logistics_company_id');
    }

    public function admins()
    {
        return $this->hasMany(LogisticsAdminDetail::class, 'logistics_company_id');
    }
}