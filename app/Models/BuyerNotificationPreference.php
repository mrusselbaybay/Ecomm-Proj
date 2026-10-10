<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A buyer's optional email choices. Essential account and security emails
 * aren't preferences and always send.
 */
class BuyerNotificationPreference extends Model
{
    /**
     * Defaults for a buyer who hasn't saved any choices: order updates on
     * (they're about purchases the buyer made), promotions off (marketing
     * needs an explicit opt-in).
     *
     * @var array<string, bool>
     */
    public const DEFAULTS = [
        'order_updates_email' => true,
        'promotions_email' => false,
        'case_updates_email' => true,
    ];

    protected $table = 'buyer_notification_preferences';

    protected $primaryKey = 'buyer_profile_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['buyer_profile_id', 'order_updates_email', 'promotions_email', 'case_updates_email'];

    protected $casts = [
        'order_updates_email' => 'boolean',
        'promotions_email' => 'boolean',
        'case_updates_email' => 'boolean',
    ];
}
