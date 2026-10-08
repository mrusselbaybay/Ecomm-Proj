<?php

namespace Database\Factories;

use App\Models\ChatAutomationSuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChatAutomationSuggestion>
 */
class ChatAutomationSuggestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => (string) Str::uuid(),
            'source_message_id' => (string) Str::uuid(),
            'rule_id' => null,
            'suggested_response' => fake()->sentence(),
            'status' => 'pending',
            'used_at' => null,
        ];
    }
}
