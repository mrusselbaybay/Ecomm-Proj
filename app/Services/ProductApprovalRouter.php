<?php

namespace App\Services;

use App\Enums\AiModerationStatus;
use App\Enums\ProductModerationOutcome;
use App\Enums\ProductStatus;
use InvalidArgumentException;

class ProductApprovalRouter
{
    /**
     * @param  array<string, mixed>  $productData
     * @param  array<string, mixed>  $aiModerationResult
     * @param  bool  $allowAutomaticDecisions  Whether the router may activate or archive the product.
     * @return array{
     *     final_status: string,
     *     product_status: string,
     *     reasoning: string,
     *     needs_human_review: bool
     * }
     */
    public function route(
        array $productData,
        array $aiModerationResult,
        bool $allowAutomaticDecisions = false,
    ): array {
        $validatedResult = $this->validateResult($aiModerationResult);

        if (! $allowAutomaticDecisions) {
            return $this->decision(
                ProductModerationOutcome::PendingHumanReview,
                ProductStatus::PendingReview,
                $validatedResult['reasoning'],
                true,
            );
        }

        if ($validatedResult['flagged_signals'] !== []) {
            return $this->decision(
                ProductModerationOutcome::PendingHumanReview,
                ProductStatus::PendingReview,
                $validatedResult['reasoning'],
                true,
            );
        }

        if (
            $validatedResult['status'] === AiModerationStatus::Approve
            && $validatedResult['confidence_score'] > 0.95
        ) {
            return $this->decision(
                ProductModerationOutcome::AutoApproved,
                ProductStatus::Active,
                $validatedResult['reasoning'],
                false,
            );
        }

        // Currently unreachable via ProductModerationClient — see the doc
        // comment on AiModerationStatus::Reject. Kept so a future moderation
        // provider that returns an explicit reject verdict routes correctly
        // without touching this method.
        if (
            $validatedResult['status'] === AiModerationStatus::Reject
            && $validatedResult['confidence_score'] > 0.95
        ) {
            return $this->decision(
                ProductModerationOutcome::AutoRejected,
                ProductStatus::Archived,
                $validatedResult['reasoning'],
                false,
            );
        }

        return $this->decision(
            ProductModerationOutcome::PendingHumanReview,
            ProductStatus::PendingReview,
            $validatedResult['reasoning'],
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{
     *     status: AiModerationStatus,
     *     confidence_score: float,
     *     flagged_signals: list<string>,
     *     reasoning: string
     * }
     */
    private function validateResult(array $result): array
    {
        $status = isset($result['status']) && is_string($result['status'])
            ? AiModerationStatus::tryFrom($result['status'])
            : null;
        $confidenceScore = $result['confidence_score'] ?? null;
        $flaggedSignals = $result['flagged_signals'] ?? null;
        $reasoning = $result['reasoning'] ?? null;

        if ($status === null) {
            throw new InvalidArgumentException('The AI moderation status is invalid.');
        }

        if (! is_int($confidenceScore) && ! is_float($confidenceScore)) {
            throw new InvalidArgumentException('The AI moderation confidence score must be numeric.');
        }

        $confidenceScore = (float) $confidenceScore;

        if ($confidenceScore < 0 || $confidenceScore > 1) {
            throw new InvalidArgumentException('The AI moderation confidence score must be between 0 and 1.');
        }

        if (! is_array($flaggedSignals) || ! array_is_list($flaggedSignals)) {
            throw new InvalidArgumentException('The AI moderation flagged signals must be a list of strings.');
        }

        foreach ($flaggedSignals as $signal) {
            if (! is_string($signal)) {
                throw new InvalidArgumentException('The AI moderation flagged signals must be a list of strings.');
            }
        }

        if (! is_string($reasoning) || trim($reasoning) === '') {
            throw new InvalidArgumentException('The AI moderation reasoning is required.');
        }

        return [
            'status' => $status,
            'confidence_score' => $confidenceScore,
            'flagged_signals' => $flaggedSignals,
            'reasoning' => $reasoning,
        ];
    }

    /**
     * @return array{
     *     final_status: string,
     *     product_status: string,
     *     reasoning: string,
     *     needs_human_review: bool
     * }
     */
    private function decision(
        ProductModerationOutcome $outcome,
        ProductStatus $productStatus,
        string $reasoning,
        bool $needsHumanReview,
    ): array {
        return [
            'final_status' => $outcome->value,
            'product_status' => $productStatus->value,
            'reasoning' => $reasoning,
            'needs_human_review' => $needsHumanReview,
        ];
    }
}
