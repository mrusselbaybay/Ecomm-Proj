<?php

namespace Database\Factories;

use App\Models\SupportTicket;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SupportTicket>
 */
class SupportTicketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_number' => 'CS-'.now()->format('Y').'-'.Str::upper(Str::random(8)),
            'created_by' => (string) Str::uuid(),
            'category' => fake()->randomElement(SupportTicket::CATEGORIES),
            'subject' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'priority' => 'normal',
            'status' => 'submitted',
        ];
    }
}
