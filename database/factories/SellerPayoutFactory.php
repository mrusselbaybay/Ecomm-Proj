<?php

namespace Database\Factories;

use App\Models\SellerPayout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SellerPayout>
 */
class SellerPayoutFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_id' => fake()->uuid(),
            'gross_amount' => 1000,
            'withholding_tax' => 0,
            'net_amount' => 1000,
            'period' => now()->format('Y-m'),
            'created_at' => now(),
        ];
    }
}
