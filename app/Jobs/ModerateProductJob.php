<?php

namespace App\Jobs;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\ProductApprovalRouter;
use App\Services\ProductModerationClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ModerateProductJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(public string $productId) {}

    public function handle(
        ProductModerationClient $moderationClient,
        ProductApprovalRouter $approvalRouter,
    ): void {
        if (! config('services.product_moderation.enabled', false)) {
            return;
        }

        $product = Product::query()->find($this->productId);

        if (! $product || $product->status !== ProductStatus::PendingReview->value) {
            return;
        }

        $aiResult = $moderationClient->moderate($product);
        $decision = $approvalRouter->route(
            $product->toArray(),
            $aiResult,
            allowAutomaticDecisions: config('services.product_moderation.mode') === 'automatic',
        );

        DB::transaction(function () use ($aiResult, $decision): void {
            $product = Product::query()->lockForUpdate()->find($this->productId);

            if (! $product || $product->status !== ProductStatus::PendingReview->value) {
                return;
            }

            $product->moderationLogs()->create([
                'ai_status' => $aiResult['status'],
                'ai_confidence_score' => $aiResult['confidence_score'],
                'ai_flagged_signals' => $aiResult['flagged_signals'],
                'ai_reasoning' => $aiResult['reasoning'],
                'final_status' => $decision['final_status'],
                'needs_human_review' => $decision['needs_human_review'],
            ]);

            $product->update([
                'status' => $decision['product_status'],
            ]);
        });
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function uniqueId(): string
    {
        return $this->productId;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Product moderation failed after all retry attempts.', [
            'product_id' => $this->productId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
