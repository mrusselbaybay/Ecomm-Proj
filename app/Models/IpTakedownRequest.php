<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\IpTakedownRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IpTakedownRequest extends Model
{
    /** @use HasFactory<IpTakedownRequestFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'claimant_id', 'claimant_name', 'claimant_email', 'listing_id',
        'work_description', 'evidence_url', 'statement', 'status',
    ];
}
