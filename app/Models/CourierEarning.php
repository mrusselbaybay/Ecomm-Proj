<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class CourierEarning extends Model
{
    use HasUuidPrimaryKey;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected $casts = ['amount_cents' => 'integer', 'remaining_cents' => 'integer', 'source_leg_cents' => 'integer', 'rate_bps' => 'integer', 'task_weights' => 'array', 'available_at' => 'datetime'];

    public function courier()
    {
        return $this->belongsTo(Profile::class, 'courier_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
