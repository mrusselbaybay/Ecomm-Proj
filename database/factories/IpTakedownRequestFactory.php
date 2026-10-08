<?php

namespace Database\Factories;

use App\Models\IpTakedownRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpTakedownRequest>
 */
class IpTakedownRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'claimant_name' => fake()->name(),
            'claimant_email' => fake()->safeEmail(),
            'work_description' => fake()->sentence(),
            'statement' => 'I declare that the information in this request is accurate.',
            'status' => 'submitted',
        ];
    }
}
