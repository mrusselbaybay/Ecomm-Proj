<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\SellerPayoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SellerPayout extends Model
{
    /** @use HasFactory<SellerPayoutFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'seller_id', 'gross_amount', 'withholding_tax', 'net_amount', 'period', 'created_at',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'withholding_tax' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];
}
