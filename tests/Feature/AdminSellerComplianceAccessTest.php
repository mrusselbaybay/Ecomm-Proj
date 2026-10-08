<?php

it('allows platform admins to review and act on seller compliance', function () {
    $admin = makeAdmin();
    $seller = makeSeller();
    $product = makeProduct($seller, ['status' => 'pending_review']);

    actingAsProfile($admin);

    $this->getJson('/api/admin/compliance/products')
        ->assertOk()
        ->assertJsonPath('products.data.0.id', $product->id);

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
