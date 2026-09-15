<?php

namespace Database\Factories;

use App\Models\LogisticsBarangayAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LogisticsBarangayAssignment>
 */
class LogisticsBarangayAssignmentFactory extends Factory
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
            'province_name' => 'Laguna',
            'municipality_code' => null,
            'municipality_name' => $this->faker->randomElement([
                'Santa Cruz',
                'Pagsanjan',
                'Los Baños',
            ]),
            'barangay' => $this->faker->randomElement([
                'Poblacion I',
                'Poblacion II',
                'Bubukal',
            ]),
            'rider_profile_id' => null,
            'is_active' => true,
        ];
    }
}
