<?php

namespace Database\Factories;

use App\Models\ConversationParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConversationParticipant>
 */
class ConversationParticipantFactory extends Factory
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
            'user_id' => (string) Str::uuid(),
            'joined_at' => now(),
            'last_read_at' => null,
            'left_at' => null,
        ];
    }
}
