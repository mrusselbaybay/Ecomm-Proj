<?php

use App\Models\BuyerAddress;

function addressPayload(array $overrides = []): array
{
    return array_merge([
        'recipient_name' => 'Juan Dela Cruz',
        'contact_no' => '09171234567',
        'line1' => '123 Mabini St, Brgy Poblacion',
        'city' => 'Quezon City',
        'province' => 'Metro Manila',
        'postal_code' => '1100',
        'label' => 'Home',
    ], $overrides);
}

it('creates the first address as the default', function () {
    $buyer = makeBuyer();
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/addresses', addressPayload())
        ->assertCreated()
        ->assertJsonPath('data.isDefault', true)
        ->assertJsonPath('data.fullName', 'Juan Dela Cruz');
});

it('keeps exactly one default when a new default is added', function () {
    $buyer = makeBuyer();
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/addresses', addressPayload(['label' => 'Home']));
    $this->postJson('/api/buyer/addresses', addressPayload(['label' => 'Work', 'is_default' => true]));

    $defaults = BuyerAddress::where('buyer_profile_id', $buyer->id)->where('is_default', true)->count();
    expect($defaults)->toBe(1);
    expect(BuyerAddress::where('buyer_profile_id', $buyer->id)->where('is_default', true)->first()->label)->toBe('Work');
});

it('promotes another address to default when the default is deleted', function () {
    $buyer = makeBuyer();
    actingAsBuyer($buyer);

    $a = $this->postJson('/api/buyer/addresses', addressPayload())->json('data.id');
    $this->postJson('/api/buyer/addresses', addressPayload(['label' => 'Work']));

    $this->deleteJson("/api/buyer/addresses/{$a}")->assertOk();

    expect(BuyerAddress::where('buyer_profile_id', $buyer->id)->count())->toBe(1);
    expect(BuyerAddress::where('buyer_profile_id', $buyer->id)->first()->is_default)->toBeTrue();
});

it("won't update or delete another buyer's address", function () {
    $me = makeBuyer();
    $other = makeBuyer();
    $addr = BuyerAddress::create(addressPayload() + ['buyer_profile_id' => $other->id, 'is_default' => true]);

    actingAsBuyer($me);

    $this->putJson("/api/buyer/addresses/{$addr->id}", addressPayload(['city' => 'Hacked']))->assertStatus(404);
    $this->deleteJson("/api/buyer/addresses/{$addr->id}")->assertStatus(404);
    expect($addr->fresh()->city)->toBe('Quezon City');
});

it('validates a bad label', function () {
    actingAsBuyer(makeBuyer());

    $this->postJson('/api/buyer/addresses', addressPayload(['label' => 'Bogus']))
        ->assertStatus(422)->assertJsonValidationErrors('label');
});
