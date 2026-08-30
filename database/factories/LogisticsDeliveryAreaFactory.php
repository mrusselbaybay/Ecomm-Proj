<?php

namespace Database\Factories;

use App\Models\LogisticsDeliveryArea;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LogisticsDeliveryArea>
 */
class LogisticsDeliveryAreaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'logistics_company_id' => (string) Str::uuid(),
            'name' => 'Area '.$this->faker->unique()->randomLetter(),
            'province_name' => 'Laguna',
            'municipality_name' => $this->faker->randomElement([
                'Santa Cruz',
                'Pagsanjan',
                'Los Baños',
            ]),
            'barangay' => null,
            'rider_profile_id' => null,
            'is_active' => true,
        ];
    }
}
