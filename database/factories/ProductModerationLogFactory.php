<?php

namespace Database\Factories;

use App\Enums\AiModerationStatus;
use App\Enums\ProductModerationOutcome;
use App\Models\ProductModerationLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductModerationLog>
 */
class ProductModerationLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => null,
            'ai_status' => AiModerationStatus::NeedsReview,
            'ai_confidence_score' => fake()->randomFloat(4, 0, 1),
            'ai_flagged_signals' => [],
            'ai_reasoning' => fake()->sentence(),
            'final_status' => ProductModerationOutcome::PendingHumanReview,
            'needs_human_review' => true,
        ];
    }
}
