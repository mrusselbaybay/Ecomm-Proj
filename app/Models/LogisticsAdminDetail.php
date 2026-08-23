<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogisticsAdminDetail extends Model
{
    protected $table = 'logistics_admin_details';
    protected $primaryKey = 'profile_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'profile_id',
        'logistics_company_id',
        'created_at'
    ];

    public $timestamps = false;

    public function profile()
    {
        return $this->belongsTo(Profile::class, 'profile_id', 'id');
    }

    public function logisticsCompany()
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id', 'id');
    }
}