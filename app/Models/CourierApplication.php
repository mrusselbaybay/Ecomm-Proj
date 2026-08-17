<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourierApplication extends Model
{
    protected $table = 'courier_applications';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'courier_profile_id',
        'logistics_company_id',
        'status',
        'applied_at',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'applied_at'  => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function courier()
    {
        return $this->belongsTo(Profile::class, 'courier_profile_id');
    }

    public function logisticsCompany()
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(Profile::class, 'reviewed_by');
    }
}