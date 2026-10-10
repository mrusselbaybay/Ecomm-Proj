<?php

use App\Models\Complaint;

it('protects the admin complaints API', function () {
    $this->getJson('/api/admin/complaints')->assertUnauthorized();
});

it('defines safe complaint status transitions', function () {
    $complaint = new Complaint(['status' => 'pending']);

    expect($complaint->canTransitionTo('under_review'))->toBeTrue()
        ->and($complaint->canTransitionTo('resolved'))->toBeTrue();

    $complaint->status = 'under_review';

    expect($complaint->canTransitionTo('resolved'))->toBeTrue()
        ->and($complaint->canTransitionTo('pending'))->toBeFalse();
});
