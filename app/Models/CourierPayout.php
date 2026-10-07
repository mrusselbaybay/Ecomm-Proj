<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class CourierPayout extends Model
{
    use HasUuidPrimaryKey;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected $casts = ['earnings_cents' => 'integer', 'cod_offset_cents' => 'integer', 'net_cents' => 'integer', 'paid_at' => 'datetime', 'approved_at' => 'datetime'];

    public function courier()
    {
        return $this->belongsTo(Profile::class, 'courier_id');
    }

    public function lines()
    {
        return $this->hasMany(CourierPayoutLine::class, 'payout_id');
    }
}
