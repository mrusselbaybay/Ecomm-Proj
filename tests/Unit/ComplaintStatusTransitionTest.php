<?php

use App\Models\Complaint;

it('allows investigation to begin from a pending complaint', function () {
    $complaint = new Complaint(['status' => 'pending']);

    expect($complaint->canTransitionTo('under_review'))->toBeTrue()
        ->and($complaint->canTransitionTo('resolved'))->toBeTrue();
});

it('allows reviewed complaints to be resolved but not reset to pending', function () {
    $complaint = new Complaint(['status' => 'under_review']);

    expect($complaint->canTransitionTo('resolved'))->toBeTrue()
        ->and($complaint->canTransitionTo('pending'))->toBeFalse();
});

it('keeps resolved and dismissed complaints terminal', function (string $status) {
    $complaint = new Complaint(['status' => $status]);

    expect($complaint->canTransitionTo('under_review'))->toBeFalse();
})->with(['resolved', 'dismissed']);
