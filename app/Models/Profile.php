<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\Notifiable;

/**
 * @property string $id
 * @property string $role
 * @property string $status
 * @property string $account_status
 * @property string $first_name
 * @property string $last_name
 * @property string|null $middle_initial
 * @property string|null $email
 * @property string|null $contact_no
 * @property-read string $full_name
 */
class Profile extends Model
{
    use Notifiable;

    protected $table = 'profiles';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'role', 'status', 'account_status',
        'last_name', 'first_name', 'middle_initial', 'sex',
        'contact_no', 'birthday', 'email', 'avatar_path',
    ];

    protected $casts = [
        'birthday' => 'date',
        'last_active_at' => 'datetime',
    ];

    // Roles that go through the registration/approval workflow.
    public const REGISTRABLE_ROLES = ['buyer', 'seller', 'courier', 'driver', 'logistics'];

    public const ROLE_ADMIN = 'admin';

    // How recently `last_active_at` must have been touched for the
    // messaging UI to show this profile as "Online" rather than "Offline".
    // Kept above AuthenticateSupabaseUser's write-throttle window (60s) —
    // 90s gives a 1.5x margin so a genuinely active user never flickers
    // offline between two throttled writes, while staying as tight as that
    // margin allows.
    public const ONLINE_THRESHOLD_SECONDS = 90;

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

    public function driverDetail(): HasOne
    {
        return $this->hasOne(DriverDetail::class, 'profile_id');
    }

    /**
     * The detail row carrying this rider's shift availability —
     * driver_details for a 'driver', courier_details for a 'courier'.
     * Both roles share the same mobile app and the same "Go online"
     * toggle, so the flag lives on whichever table backs their role.
     */
    public function deliveryDetail(): DriverDetail|CourierDetail|null
    {
        return $this->role === 'driver'
            ? $this->driverDetail
            : $this->courierDetail;
    }

    /**
     * Is this rider on shift right now? Anything other than an explicit
     * 'available' — including a missing detail row — counts as off, so a
     * rider is never handed work by default.
     *
     * Read by Driver\DriverProfileController (the toggle itself) and by
     * ParcelAutoAssignService, which refuses to auto-assign to a rider
     * who isn't on shift.
     */
    public function isAvailableForDelivery(): bool
    {
        return $this->deliveryDetail()?->delivery_status === 'available';
    }

    /**
     * This rider's declared vehicle (free text — see App\Support\
     * VehicleCategory for the normalizer), read from whichever detail row
     * backs their role. Same courier-vs-driver dispatch as
     * isAvailableForDelivery().
     */
    public function vehicleLabel(): ?string
    {
        return $this->deliveryDetail()?->vehicle;
    }

    // For role = logistics: the company this profile registered as owner.
    public function logisticsCompany(): HasOne
    {
        return $this->hasOne(LogisticsCompany::class, 'owner_profile_id');
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

    public function sellerComplianceActions(): HasMany
    {
        return $this->hasMany(SellerComplianceAction::class, 'seller_id');
    }

    public function submittedComplaints(): HasMany
    {
        return $this->hasMany(Complaint::class, 'complainant_id');
    }

    public function complaintsAgainst(): HasMany
    {
        return $this->hasMany(Complaint::class, 'respondent_id');
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

    public function messagingConversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants', 'user_id', 'conversation_id')
            ->withPivot(['joined_at', 'last_read_at', 'left_at'])
            ->withTimestamps();
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'created_by');
    }

    public function getFullNameAttribute(): string
    {
        $mi = $this->middle_initial ? "{$this->middle_initial}. " : '';

        return trim("{$this->first_name} {$mi}{$this->last_name}");
    }

    /**
     * Activity-based presence for the messaging thread header: true if this
     * profile has touched any authenticated endpoint within the last
     * ONLINE_THRESHOLD_SECONDS (see AuthenticateSupabaseUser).
     */
    public function isOnline(): bool
    {
        return $this->last_active_at !== null
            && $this->last_active_at->gt(now()->subSeconds(self::ONLINE_THRESHOLD_SECONDS));
    }

    /**
     * Public URL for the avatar, if one has been uploaded. The `avatars`
     * storage bucket is public, so this is a stable URL — no signed link
     * to regenerate, unlike the private `documents` bucket.
     */
    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        return rtrim(config('services.supabase.url'), '/')
            .'/storage/v1/object/public/avatars/'.$this->avatar_path;
    }
}
