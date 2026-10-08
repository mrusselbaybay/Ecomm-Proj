<?php

namespace Database\Factories;

use App\Models\BreachIncident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BreachIncident>
 */
class BreachIncidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'detected_at' => now(),
            'affected_count' => 1,
            'description' => fake()->sentence(),
            'status' => 'investigating',
        ];
    }
}
