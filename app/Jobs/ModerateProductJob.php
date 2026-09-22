<?php

namespace App\Jobs;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\SellerComplianceAction;
use App\Services\KeywordProductModerationClient;
use App\Services\ProductApprovalRouter;
use App\Services\ProductModerationClient;
use App\Services\SellerNotifier;
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
        KeywordProductModerationClient $keywordClient,
        ProductApprovalRouter $approvalRouter,
    ): void {
        if (! config('services.product_moderation.enabled', false)) {
            return;
        }

        $product = Product::query()->find($this->productId);

        if (! $product || $product->status !== ProductStatus::PendingReview->value) {
            return;
        }

        // OpenAI is preferred (it actually inspects images/description for
        // harmful content); the keyword client is a zero-cost, name-only
        // fallback for when OpenAI isn't reachable/billed — see its
        // docblock. Falling back here (rather than letting the job fail)
        // means a billing outage doesn't leave every submission stuck in
        // the queue's retry/backoff cycle for no reason.
        try {
            $aiResult = $moderationClient->moderate($product);
        } catch (Throwable $e) {
            Log::warning('OpenAI product moderation unavailable, falling back to keyword check.', [
                'product_id' => $this->productId,
                'error' => $e->getMessage(),
            ]);

            $aiResult = $keywordClient->moderate($product);
        }

        $decision = $approvalRouter->route(
            $product->toArray(),
            $aiResult,
            allowAutomaticDecisions: config('services.product_moderation.mode') === 'automatic',
        );

        // A decisive AI verdict (auto-approved/auto-rejected) is itself a
        // verification event, exactly like an admin clicking Verify/Remove
        // — it must land in seller_compliance_actions or it never shows up
        // in Compliance History and the product silently stays stuck
        // looking "unreviewed" in the main list (its query only excludes
        // active products once a verify action exists). A merely
        // "needs_human_review" verdict is NOT a verification — nothing to
        // record yet, a human still has to decide.
        $complianceAction = null;

        DB::transaction(function () use ($aiResult, $decision, &$complianceAction): void {
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

            if (! $decision['needs_human_review']) {
                $complianceAction = SellerComplianceAction::create([
                    'seller_id' => $product->seller_id,
                    'product_id' => $product->id,
                    'action' => $decision['final_status'] === 'auto_approved' ? 'verify' : 'remove',
                    'reason' => $decision['final_status'] === 'auto_rejected' ? $decision['reasoning'] : null,
                    'notes' => 'Auto-processed by AI moderation: '.$decision['reasoning'],
                    'admin_id' => null,
                ]);
            }
        });

        if ($complianceAction) {
            // SellerNotifier only reads seller_id/name off $product, both
            // already correct on the copy fetched before the transaction
            // — no need to re-fetch (and no dependency on the `seller`
            // relation actually resolving, which matters for the
            // narrow-schema unit tests around this job).
            $notifier = app(SellerNotifier::class);

            if ($complianceAction->action === 'verify') {
                $notifier->productVerified($product, $complianceAction->id);
            } else {
                $notifier->productRemoved($product, $decision['reasoning'], $complianceAction->id);
            }
        }
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
