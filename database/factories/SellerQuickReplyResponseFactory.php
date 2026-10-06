<?php

namespace Database\Factories;

use App\Models\SellerQuickReplyResponse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SellerQuickReplyResponse>
 */
class SellerQuickReplyResponseFactory extends Factory
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
            'question_key' => 'stock_availability',
            'response' => fake()->sentence(),
            'enabled' => true,
        ];
    }
}
