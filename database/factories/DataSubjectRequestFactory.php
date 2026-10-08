<?php

namespace Database\Factories;

use App\Models\DataSubjectRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSubjectRequest>
 */
class DataSubjectRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fake()->uuid(),
            'request_type' => 'access',
            'details' => fake()->sentence(),
            'status' => 'submitted',
        ];
    }
}
