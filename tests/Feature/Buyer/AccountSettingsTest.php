<?php

/*
|--------------------------------------------------------------------------
| Buyer account settings
|--------------------------------------------------------------------------
|
| Profile photo, optional email preferences and the personal-data export
| (Buyer\AccountSettingsController). The public file disk is faked.
|
*/

use App\Models\BuyerAddress;
use App\Models\BuyerNotificationPreference;
use App\Models\Review;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function fakeAvatarStorage(): void
{
    Storage::fake('public');
}

it('uploads a profile photo into the buyer own folder and replaces the old one', function () {
    $buyer = makeBuyer();
    $buyer->forceFill(['avatar_path' => "{$buyer->id}/old.jpg"])->save();

    actingAsBuyer($buyer);
    fakeAvatarStorage();

    $response = $this->post('/api/buyer/account/avatar', [
        'avatar' => UploadedFile::fake()->image('me.png', 400, 400),
    ], ['Accept' => 'application/json'])->assertCreated();

    $path = $buyer->fresh()->avatar_path;

    expect($path)->toStartWith($buyer->id.'/')->toEndWith('.png');
    expect($response->json('data.avatar_url'))->toEndWith("/storage/avatars/{$path}");
    Storage::disk('public')->assertExists("avatars/{$path}");
    Storage::disk('public')->assertMissing("avatars/{$buyer->id}/old.jpg");

    $this->getJson('/api/buyer/account')->assertJsonPath('data.avatar_url', $response->json('data.avatar_url'));
})->skip(! extension_loaded('gd'), 'GD is required by Laravel image test fixtures.');

it('rejects unsuitable profile photos', function (UploadedFile $file) {
    $buyer = makeBuyer();

    actingAsBuyer($buyer);
    fakeAvatarStorage();

    $this->post('/api/buyer/account/avatar', ['avatar' => $file], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('avatar');

    expect($buyer->fresh()->avatar_path)->toBeNull();
})->with(array_merge([
    'not an image' => fn () => UploadedFile::fake()->create('me.pdf', 50, 'application/pdf'),
], extension_loaded('gd') ? [
    'gif' => fn () => UploadedFile::fake()->image('me.gif', 300, 300),
    'too small' => fn () => UploadedFile::fake()->image('me.png', 64, 64),
    'too large' => fn () => UploadedFile::fake()->image('me.jpg', 400, 400)->size(3000),
] : []));

it('removes the profile photo without touching other folders', function () {
    $buyer = makeBuyer();
    $stranger = (string) Str::uuid();
    $buyer->forceFill(['avatar_path' => "{$stranger}/theirs.jpg"])->save();

    actingAsBuyer($buyer);
    fakeAvatarStorage();

    $this->deleteJson('/api/buyer/account/avatar')
        ->assertOk()
        ->assertJsonPath('data.avatar_url', null);

    expect($buyer->fresh()->avatar_path)->toBeNull();
    // The row pointed outside the buyer's folder: cleared, never deleted.
    Storage::disk('public')->assertMissing("avatars/{$stranger}/theirs.jpg");
});

it('saves email preferences with marketing off by default', function () {
    $buyer = makeBuyer();

    actingAsBuyer($buyer);

    $this->getJson('/api/buyer/account/preferences')
        ->assertOk()
        ->assertJsonPath('data', ['order_updates_email' => true, 'promotions_email' => false, 'saved' => false]);

    $this->putJson('/api/buyer/account/preferences', ['promotions_email' => true])
        ->assertOk()
        ->assertJsonPath('data.promotions_email', true)
        ->assertJsonPath('data.order_updates_email', true)
        ->assertJsonPath('data.saved', true);

    $this->putJson('/api/buyer/account/preferences', ['order_updates_email' => false])
        ->assertOk()
        ->assertJsonPath('data.promotions_email', true)
        ->assertJsonPath('data.order_updates_email', false);

    expect(BuyerNotificationPreference::findOrFail($buyer->id)->order_updates_email)->toBeFalse();

    $this->putJson('/api/buyer/account/preferences', ['promotions_email' => 'sometimes'])
        ->assertUnprocessable();
});

it('exports only the signed-in buyer data as a download', function () {
    $buyer = makeBuyer(['first_name' => 'Maria']);
    $other = makeBuyer(['first_name' => 'Other']);
    $seller = makeSeller();

    BuyerAddress::create(['buyer_profile_id' => $buyer->id, 'recipient_name' => 'Maria', 'contact_no' => '09171234567', 'line1' => '12 Mabini St', 'city' => 'Quezon City', 'province' => 'Metro Manila', 'label' => 'Home', 'is_default' => true]);
    BuyerAddress::create(['buyer_profile_id' => $other->id, 'recipient_name' => 'Other', 'contact_no' => '09170000000', 'line1' => 'Elsewhere', 'city' => 'Cebu City', 'province' => 'Cebu', 'label' => 'Home', 'is_default' => true]);
    makeOrder($buyer, $seller, ['order_number' => 'SN-11111']);
    makeOrder($other, $seller, ['order_number' => 'SN-22222']);
    Review::forceCreate(['id' => (string) Str::uuid(), 'seller_id' => $seller->id, 'buyer_id' => $buyer->id, 'product_name' => 'Mug', 'rating' => 5, 'comment' => 'Great']);

    actingAsBuyer($buyer);

    $response = $this->get('/api/buyer/account/export', ['Accept' => 'application/json'])->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('attachment; filename="buytheway-my-data-');

    $data = $response->json();

    expect($data['profile']['first_name'])->toBe('Maria');
    expect(collect($data['addresses'])->pluck('recipient_name')->all())->toBe(['Maria']);
    expect(collect($data['orders'])->pluck('order_number')->all())->toBe(['SN-11111']);
    expect($data['reviews'][0]['comment'])->toBe('Great');
    expect($data['email_preferences']['promotions_email'])->toBeFalse();
});

it('keeps account settings behind buyer sign-in', function () {
    $this->getJson('/api/buyer/account/preferences')->assertUnauthorized();
    $this->getJson('/api/buyer/account/export')->assertUnauthorized();
    $this->deleteJson('/api/buyer/account/avatar')->assertUnauthorized();
});
