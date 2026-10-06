<?php

namespace Database\Factories;

use App\Models\ChatQuickQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChatQuickQuestion>
 */
class ChatQuickQuestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => Str::slug(fake()->unique()->words(3, true), '_'),
            'question' => fake()->sentence().'?',
            'default_response' => fake()->sentence(),
            'context_type' => 'general',
            'sort_order' => fake()->numberBetween(1, 100),
            'enabled' => true,
        ];
    }
}
