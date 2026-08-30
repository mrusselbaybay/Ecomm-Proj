<?php

namespace Database\Factories;

use App\Models\ParcelAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ParcelAssignment>
 */
class ParcelAssignmentFactory extends Factory
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
            'order_id' => (string) Str::uuid(),
            'logistics_company_id' => (string) Str::uuid(),
            'status' => ParcelAssignment::STATUS_RECEIVED,
            'received_by' => (string) Str::uuid(),
            'received_at' => now(),
            'scanned_at' => now(),
        ];
    }
}
