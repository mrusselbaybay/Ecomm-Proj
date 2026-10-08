<?php

namespace Database\Factories;

use App\Models\ConsentRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsentRecord>
 */
class ConsentRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guest_id' => fake()->uuid(),
            'consent_type' => 'cookies',
            'policy_version' => '[POLICY_VERSION]',
            'categories_json' => [
                'strictly_necessary' => true,
                'functional' => false,
                'analytics' => false,
                'marketing' => false,
            ],
            'action' => 'granted',
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'created_at' => now(),
        ];
    }
}
