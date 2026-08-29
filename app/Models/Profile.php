<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public const ROLE_ADMIN = 'admin';

    /**
     * Defense-in-depth: block any Eloquent-level attempt to (re)assign
     * the admin role from an HTTP request lifecycle. Admin accounts are
     * provisioned out-of-band (console/seeder) only. This does not cover
     * profile rows inserted directly by the Supabase auth.users trigger -
     * that path is guarded separately in AuthController::register().
     */
    protected static function booted(): void
    {
        static::saving(function (Profile $profile) {
            if (
                $profile->role === self::ROLE_ADMIN
                && $profile->isDirty('role')
                && ! app()->runningInConsole()
            ) {
                throw new \RuntimeException(
                    'Admin accounts cannot be created or modified through the application. Provision them via the console.'
                );
            }
        });
    }

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

    // ---- Buyer-owned collections (added for the buyer backend; additive,
    // no existing relation/method/cast changed) ----

    public function buyerAddresses(): HasMany
    {
        return $this->hasMany(BuyerAddress::class, 'buyer_profile_id');
    }

    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class, 'buyer_profile_id');
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(BuyerPaymentMethod::class, 'buyer_profile_id');
    }

    public function returnRequests(): HasMany
    {
        return $this->hasMany(OrderReturnRequest::class, 'buyer_profile_id');
    }

    public function conversationsAsBuyer(): HasMany
    {
        return $this->hasMany(Conversation::class, 'buyer_id');
    }

    public function conversationsAsSeller(): HasMany
    {
        return $this->hasMany(Conversation::class, 'seller_id');
    }

    public function getFullNameAttribute(): string
    {
        $mi = $this->middle_initial ? "{$this->middle_initial}. " : '';

        return trim("{$this->first_name} {$mi}{$this->last_name}");
    }
}
