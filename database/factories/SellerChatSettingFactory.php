<?php

namespace Database\Factories;

use App\Models\SellerChatSetting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SellerChatSetting>
 */
class SellerChatSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_id' => (string) Str::uuid(),
            'auto_reply_enabled' => true,
            'presence_mode' => 'away',
            'generic_away_response' => fake()->sentence(),
            'generic_reply_cooldown_minutes' => 240,
        ];
    }
}
