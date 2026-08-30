<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    protected $table = 'addresses';
    protected $keyType = 'string';

    protected $fillable = [
        'owner_kind', 'profile_id', 'logistics_company_id',
        'region_code', 'region_name',
        'province_code', 'province_name', 'municipality_code', 'municipality_name',
        'barangay', 'street', 'house_no',
    ];

    public function getFullAddressAttribute(): string
    {
        return collect([
            $this->house_no, $this->street, $this->barangay,
            $this->municipality_name, $this->province_name, $this->region_name,
        ])->filter()->implode(', ');
    }
}