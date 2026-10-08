<?php

namespace Database\Factories;

use App\Models\SellerChatRule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SellerChatRule>
 */
class SellerChatRuleFactory extends Factory
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
            'match_type' => 'keyword',
            'question_key' => null,
            'keyword' => 'warranty',
            'normalized_keyword' => 'warranty',
            'response_template' => fake()->sentence(),
            'is_active' => true,
            'priority' => 100,
        ];
    }
}
