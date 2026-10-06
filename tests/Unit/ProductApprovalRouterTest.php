<?php

use App\Services\ProductApprovalRouter;

it('automatically approves a high confidence approval without flags', function () {
    $decision = (new ProductApprovalRouter)->route([], [
        'status' => 'APPROVE',
        'confidence_score' => 0.96,
        'flagged_signals' => [],
        'reasoning' => 'The product complies with marketplace policy.',
    ], allowAutomaticDecisions: true);

    expect($decision)->toMatchArray([
        'final_status' => 'auto_approved',
        'product_status' => 'active',
        'needs_human_review' => false,
    ]);
});

it('automatically rejects a high confidence rejection without flags', function () {
    $decision = (new ProductApprovalRouter)->route([], [
        'status' => 'REJECT',
        'confidence_score' => 0.99,
        'flagged_signals' => [],
        'reasoning' => 'The product is prohibited by marketplace policy.',
    ], allowAutomaticDecisions: true);

    expect($decision)->toMatchArray([
        'final_status' => 'auto_rejected',
        'product_status' => 'archived',
        'needs_human_review' => false,
    ]);
});

it('sends a medium confidence approval to human review', function () {
    $decision = (new ProductApprovalRouter)->route([], [
        'status' => 'APPROVE',
        'confidence_score' => 0.80,
        'flagged_signals' => [],
        'reasoning' => 'The product may be acceptable but confidence is limited.',
    ]);

    expect($decision)->toMatchArray([
        'final_status' => 'pending_human_review',
        'product_status' => 'pending_review',
        'needs_human_review' => true,
    ]);
});

it('sends every flagged result to human review', function (string $status) {
    $decision = (new ProductApprovalRouter)->route([], [
        'status' => $status,
        'confidence_score' => 0.99,
        'flagged_signals' => ['category_mismatch'],
        'reasoning' => 'The product category needs verification.',
    ], allowAutomaticDecisions: true);

    expect($decision)->toMatchArray([
        'final_status' => 'pending_human_review',
        'product_status' => 'pending_review',
        'needs_human_review' => true,
    ]);
})->with(['APPROVE', 'REJECT', 'NEEDS_REVIEW']);

it('does not automate a result at the exact confidence threshold', function () {
    $decision = (new ProductApprovalRouter)->route([], [
        'status' => 'APPROVE',
        'confidence_score' => 0.95,
        'flagged_signals' => [],
        'reasoning' => 'The result is exactly at the threshold.',
    ], allowAutomaticDecisions: true);

    expect($decision['final_status'])->toBe('pending_human_review')
        ->and($decision['needs_human_review'])->toBeTrue();
});

it('keeps high confidence results pending when automatic decisions are disabled', function (string $status) {
    $decision = (new ProductApprovalRouter)->route([], [
        'status' => $status,
        'confidence_score' => 0.99,
        'flagged_signals' => [],
        'reasoning' => 'The result must be reviewed during the safe rollout.',
    ]);

    expect($decision)->toMatchArray([
        'final_status' => 'pending_human_review',
        'product_status' => 'pending_review',
        'needs_human_review' => true,
    ]);
})->with(['APPROVE', 'REJECT']);

it('rejects malformed moderation results', function () {
    expect(fn () => (new ProductApprovalRouter)->route([], [
        'status' => 'UNKNOWN',
        'confidence_score' => 2,
        'flagged_signals' => 'invalid',
        'reasoning' => '',
    ]))->toThrow(InvalidArgumentException::class);
});
