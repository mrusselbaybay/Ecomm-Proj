<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerDetail extends Model
{
    public const APPLICATION_PENDING = 'pending';

    public const APPLICATION_APPROVED = 'approved';

    public const APPLICATION_REJECTED = 'rejected';

    protected $table = 'seller_details';
    protected $primaryKey = 'profile_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'profile_id', 'business_name', 'line_of_business',
        'application_status', 'application_reason', 'applied_at',
        'report_hold',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
        'report_hold' => 'boolean',
    ];

    public function isApproved(): bool
    {
        // Rows created before the column existed (or by older signup paths
        // that don't set it) count as approved, matching the column default.
        return ($this->application_status ?? self::APPLICATION_APPROVED) === self::APPLICATION_APPROVED;
    }
}
