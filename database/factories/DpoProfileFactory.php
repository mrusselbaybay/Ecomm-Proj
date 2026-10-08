<?php

namespace Database\Factories;

use App\Models\DpoProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DpoProfile>
 */
class DpoProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => '[DPO_NAME]',
            'email' => '[DPO_EMAIL]',
            'address' => '[BUSINESS_ADDRESS]',
            'npc_registration_no' => '[NPC_REGISTRATION_NO]',
        ];
    }
}
