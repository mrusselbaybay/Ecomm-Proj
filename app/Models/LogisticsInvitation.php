<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LogisticsInvitation extends Model
{
    use HasUuids;

    public const TTL_DAYS = 7;

    protected $table = 'logistics_invitations';

    protected $fillable = [
        'logistics_company_id',
        'email',
        'role',
        'token_hash',
        'status',
        'invited_by',
        'accepted_by',
        'expires_at',
        'accepted_at',
        'renewal_requested_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'renewal_requested_at' => 'datetime',
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        return static::query()->where('token_hash', static::hashToken($token))->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->status === 'pending' && ! $this->isExpired();
    }

    public function company()
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id', 'id');
    }

    public function inviter()
    {
        return $this->belongsTo(Profile::class, 'invited_by', 'id');
    }
}
