<?php

use App\Models\ProductModerationLog;

it('allows platform admins to review and act on seller compliance', function () {
    $admin = makeAdmin();
    $seller = makeSeller();
    $product = makeProduct($seller, ['status' => 'pending_review']);

    ProductModerationLog::query()->create([
        'product_id' => $product->id,
        'ai_status' => 'NEEDS_REVIEW',
        'ai_confidence_score' => 0.62,
        'ai_flagged_signals' => ['violence'],
        'ai_reasoning' => 'The listing requires a human compliance review.',
        'final_status' => 'pending_human_review',
        'needs_human_review' => true,
    ]);

    actingAsProfile($admin);

    $this->getJson('/api/admin/compliance/products')
        ->assertOk()
        ->assertJsonPath('products.data.0.id', $product->id)
        ->assertJsonPath('products.data.0.moderation.ai_status', 'NEEDS_REVIEW')
        ->assertJsonPath('products.data.0.moderation.flagged_signals.0', 'violence');

    $this->postJson("/api/admin/compliance/products/{$product->id}/actions", [
        'action' => 'verify',
    ])->assertOk();

    expect($product->refresh()->status)->toBe('active');

    $this->assertDatabaseHas('seller_compliance_actions', [
        'product_id' => $product->id,
        'seller_id' => $seller->id,
        'admin_id' => $admin->id,
        'action' => 'verify',
    ]);
});

it('does not expose platform seller compliance to logistics admins', function () {
    actingAsProfile(makeAdmin(['role' => 'logistics_admin']));

    $this->getJson('/api/admin/compliance/products')->assertForbidden();
});

it('allows only platform admins to view commission data', function () {
    actingAsProfile(makeAdmin());

    $this->getJson('/api/admin/commissions')->assertOk();

    actingAsProfile(makeAdmin(['role' => 'logistics_admin']));

    $this->getJson('/api/admin/commissions')->assertForbidden();
});
