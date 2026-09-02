<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One GPS ping for an order in transit. See the create_parcel_locations
 * migration for who writes these and why there's no uniqueness.
 */
class ParcelLocation extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'parcel_locations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'order_id',
        'lat',
        'lng',
        'recorded_at',
        'source',
        'speed_kph',
        'heading',
        'note',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'speed_kph' => 'float',
        'heading' => 'integer',
        'recorded_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
