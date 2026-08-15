<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourierDetail extends Model
{
    protected $table = 'courier_details';
    protected $primaryKey = 'profile_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['profile_id', 'vehicle', 'plate_number'];
}