<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A non-owner member of a logistics company's team. The owner is implicit
 * (logistics_companies.owner_profile_id) and never has a row here.
 */
class LogisticsAdminDetail extends Model
{
    public const ROLE_OWNER = 'owner';

    /** Assignable member roles, highest privilege first. */
    public const ROLES = ['admin', 'manager', 'operator', 'viewer'];

    /** Roles allowed to manage the team. */
    public const TEAM_MANAGER_ROLES = [self::ROLE_OWNER, 'admin'];

    protected $table = 'logistics_admin_details';
    protected $primaryKey = 'profile_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'profile_id',
        'logistics_company_id',
        'role',
        'status',
        'invited_by',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public $timestamps = false;

    public function profile()
    {
        return $this->belongsTo(Profile::class, 'profile_id', 'id');
    }

    public function logisticsCompany()
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id', 'id');
    }
}
