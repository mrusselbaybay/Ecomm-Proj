<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverDetail extends Model
{
    protected $table = 'driver_details';
    protected $primaryKey = 'profile_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['profile_id', 'logistics_company_id', 'vehicle', 'plate_number', 'license_number'];
}