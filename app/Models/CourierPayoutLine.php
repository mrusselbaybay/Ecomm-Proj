<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourierPayoutLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['amount_cents' => 'integer'];

    public function earning()
    {
        return $this->belongsTo(CourierEarning::class);
    }
}
