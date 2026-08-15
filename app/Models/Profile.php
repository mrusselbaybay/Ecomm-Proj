<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Profile extends Model
{
    protected $table = 'profiles';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'role', 'status', 'account_status',
        'last_name', 'first_name', 'middle_initial', 'sex',
        'contact_no', 'birthday', 'email',
    ];

    protected $casts = [
        'birthday' => 'date',
    ];

    // Roles that go through the registration/approval workflow.
    public const REGISTRABLE_ROLES = ['buyer', 'seller', 'courier'];

    public function address(): HasOne
    {
        return $this->hasOne(Address::class, 'profile_id')->where('owner_kind', 'profile');
    }

    public function sellerDetail(): HasOne
    {
        return $this->hasOne(SellerDetail::class, 'profile_id');
    }

    public function courierDetail(): HasOne
    {
        return $this->hasOne(CourierDetail::class, 'profile_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'profile_id')->where('owner_kind', 'profile');
    }

    public function statusAuditLogs(): HasMany
    {
        return $this->hasMany(StatusAuditLog::class, 'entity_id')
            ->where('entity_type', 'profile')
            ->orderByDesc('created_at');
    }

    public function getFullNameAttribute(): string
    {
        $mi = $this->middle_initial ? "{$this->middle_initial}. " : '';
        return trim("{$this->first_name} {$mi}{$this->last_name}");
    }
}