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
            'is_active' => true,
        ];
    }

    /**
     * An area covers any number of municipalities now (they live in
     * logistics_delivery_area_municipalities, not on the area row), so
     * give every generated area one by default — otherwise nothing it's
     * used for can ever match a parcel.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (LogisticsDeliveryArea $area): void {
            if ($area->municipalities()->exists()) {
                return;
            }

            $area->municipalities()->create([
                'municipality_name' => $this->faker->randomElement([
                    'Santa Cruz',
                    'Pagsanjan',
                    'Los Baños',
                ]),
            ]);
        });
    }
}
