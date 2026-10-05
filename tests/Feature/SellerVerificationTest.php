<?php

/*
|--------------------------------------------------------------------------
| Seller verification
|--------------------------------------------------------------------------
|
| Admin-only grant / revoke (Admin\SellerVerificationController) and the
| buyer-facing badge on the store API.
|
*/

use App\Models\Profile;
use App\Models\SellerVerification;
use Illuminate\Support\Str;

function makeAdmin(): Profile
{
    return Profile::create([
        'id' => (string) Str::uuid(),
        'role' => 'admin',
        'status' => 'approved',
        'account_status' => 'active',
        'first_name' => 'Ada',
        'last_name' => 'Admin',
        'email' => 'admin_'.Str::random(8).'@example.test',
    ]);
}

it('starts every seller unverified, even when approved', function () {
    $seller = makeSeller();

    $this->getJson("/api/stores/{$seller->id}")
        ->assertOk()
        ->assertJsonPath('data.isVerified', false)
        ->assertJsonPath('data.verifiedAt', null);
});

it('lets an admin verify and later revoke a seller', function () {
    $admin = makeAdmin();
    $seller = makeSeller();

    actingAsBuyer($admin);

    $this->putJson("/api/admin/sellers/{$seller->id}/verification", ['verified' => true, 'note' => 'Checked business permit'])
        ->assertOk()
        ->assertJsonPath('data.isVerified', true)
        ->assertJsonPath('data.verifiedBy', 'Ada Admin');

    $row = SellerVerification::findOrFail($seller->id);
    expect($row->status)->toBe('verified');
    expect($row->verified_by)->toBe($admin->id);
    expect($row->verified_at)->not->toBeNull();

    $this->getJson("/api/stores/{$seller->id}")->assertJsonPath('data.isVerified', true);

    $this->putJson("/api/admin/sellers/{$seller->id}/verification", ['verified' => false])
        ->assertOk()
        ->assertJsonPath('data.isVerified', false)
        ->assertJsonPath('data.status', 'revoked');

    expect(SellerVerification::findOrFail($seller->id)->revoked_by)->toBe($admin->id);

    $this->getJson("/api/stores/{$seller->id}")->assertJsonPath('data.isVerified', false);
});

it('keeps the original grant date when an admin re-verifies', function () {
    $admin = makeAdmin();
    $seller = makeSeller();

    SellerVerification::create([
        'seller_id' => $seller->id,
        'status' => 'verified',
        'verified_by' => $admin->id,
        'verified_at' => now()->subMonth(),
    ]);

    actingAsBuyer($admin);

    $this->putJson("/api/admin/sellers/{$seller->id}/verification", ['verified' => true])->assertOk();

    expect(SellerVerification::findOrFail($seller->id)->verified_at->lt(now()->subWeeks(3)))->toBeTrue();
});

it('does not let a seller verify themselves', function () {
    $seller = makeSeller();

    actingAsBuyer($seller);

    $this->putJson("/api/admin/sellers/{$seller->id}/verification", ['verified' => true])->assertForbidden();
    $this->getJson('/api/admin/seller-verifications')->assertForbidden();

    expect(SellerVerification::count())->toBe(0);
});

it('does not let a buyer verify a seller', function () {
    $seller = makeSeller();

    actingAsBuyer(makeBuyer());

    $this->putJson("/api/admin/sellers/{$seller->id}/verification", ['verified' => true])->assertForbidden();

    expect(SellerVerification::count())->toBe(0);
});

it('requires authentication', function () {
    $seller = makeSeller();

    $this->putJson("/api/admin/sellers/{$seller->id}/verification", ['verified' => true])->assertUnauthorized();

    expect(SellerVerification::count())->toBe(0);
});

it('only verifies seller accounts', function () {
    actingAsBuyer(makeAdmin());

    $this->putJson('/api/admin/sellers/'.makeBuyer()->id.'/verification', ['verified' => true])->assertUnprocessable();
    $this->putJson('/api/admin/sellers/'.Str::uuid().'/verification', ['verified' => true])->assertNotFound();
    $this->putJson('/api/admin/sellers/'.makeSeller()->id.'/verification', [])->assertUnprocessable();
});

it('lists verification rows for the admin users table', function () {
    $admin = makeAdmin();
    $seller = makeSeller();

    SellerVerification::create(['seller_id' => $seller->id, 'status' => 'verified', 'verified_by' => $admin->id, 'verified_at' => now()]);

    actingAsBuyer($admin);

    $this->getJson('/api/admin/seller-verifications')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sellerId', $seller->id)
        ->assertJsonPath('data.0.isVerified', true);
});
