<?php

use App\Models\Complaint;
use App\Models\Product;
use App\Models\Profile;
use Illuminate\Support\Facades\Mail;

it('snapshots anonymous product reports and temporarily holds urgent listings', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $product = makeProduct($seller, ['name' => 'Original listing name']);

    actingAsBuyer($buyer);
    $response = $this->postJson('/api/buyer/reports', [
        'target_type' => 'product',
        'target_id' => $product->id,
        'reason' => 'prohibited_items',
        'details' => 'This listing appears to offer a prohibited item.',
        'anonymous' => true,
    ])->assertCreated();

    $report = Complaint::findOrFail($response->json('data.id'));
    expect($report->reporter_is_anonymous)->toBeTrue()
        ->and($report->target_name_snapshot)->toBe('Original listing name')
        ->and($report->seller_name_snapshot)->toBe('Test Storefront')
        ->and($report->urgent_review_due_at)->not->toBeNull()
        ->and($product->fresh()->report_hold)->toBeTrue();

    actingAsProfile(makeAdmin());
    $this->getJson("/api/admin/complaints/{$report->id}")
        ->assertOk()
        ->assertJsonPath('complaint.complainant.full_name', 'Anonymous buyer')
        ->assertJsonPath('complaint.target.name', 'Original listing name')
        ->assertJsonMissingPath('complaint.complainant.email');
});

it('shows submitted shop reports in the admin complaints list', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();

    actingAsBuyer($buyer);
    $response = $this->postJson('/api/buyer/reports', [
        'target_type' => 'store',
        'target_id' => $seller->id,
        'reason' => 'customer_service',
        'details' => 'The shop has not responded to multiple messages.',
    ])->assertCreated();

    actingAsProfile(makeAdmin());
    $this->getJson('/api/admin/complaints')
        ->assertOk()
        ->assertJsonPath('complaints.data.0.id', $response->json('data.id'))
        ->assertJsonPath('complaints.data.0.target.type', 'store')
        ->assertJsonPath('complaints.data.0.target.name', 'Test Storefront');
});

it('requires a dismissal reason and locks terminal cases while notifying the reporter once', function () {
    Mail::fake();
    $buyer = makeBuyer();
    $seller = makeSeller();
    $admin = makeAdmin();
    $report = Complaint::create([
        'complainant_id' => $buyer->id,
        'respondent_id' => $seller->id,
        'type' => 'store_report',
        'subject' => 'Shop report',
        'description' => 'Shop report details',
        'status' => 'under_review',
        'priority' => 'normal',
        'report_reason' => 'customer_service',
        'reporter_name_snapshot' => $buyer->full_name,
        'target_name_snapshot' => 'Test Storefront',
        'seller_name_snapshot' => 'Test Storefront',
    ]);

    actingAsProfile($admin);
    $this->putJson("/api/admin/complaints/{$report->id}", [
        'status' => 'dismissed',
        'priority' => 'normal',
        'notes' => 'No violation was found after review.',
        'is_internal' => false,
    ])->assertUnprocessable()->assertJsonValidationErrors('dismissal_reason');

    $this->putJson("/api/admin/complaints/{$report->id}", [
        'status' => 'dismissed',
        'priority' => 'normal',
        'dismissal_reason' => 'no_violation',
        'notes' => 'No violation was found after review.',
        'is_internal' => false,
    ])->assertOk();

    expect($report->fresh()->dismissal_reason)->toBe('no_violation');
    $this->putJson("/api/admin/complaints/{$report->id}", [
        'status' => 'under_review',
        'priority' => 'normal',
        'notes' => 'Trying to reopen a closed report.',
        'is_internal' => false,
    ])->assertUnprocessable();

    $this->assertDatabaseCount('notifications', 1);
    actingAsBuyer($buyer);
    $this->getJson('/api/buyer/report-notifications')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Report review complete')
        ->assertJsonPath('data.0.message', 'We completed our review. We did not confirm a policy violation, and no further action is needed from you.');
});
