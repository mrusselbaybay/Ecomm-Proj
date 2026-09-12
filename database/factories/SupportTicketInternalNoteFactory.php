<?php

namespace Database\Factories;

use App\Models\SupportTicketInternalNote;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SupportTicketInternalNote>
 */
class SupportTicketInternalNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'support_ticket_id' => (string) Str::uuid(),
            'admin_id' => (string) Str::uuid(),
            'body' => fake()->paragraph(),
        ];
    }
}
