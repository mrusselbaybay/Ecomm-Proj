<?php

use App\Models\BuyerPaymentMethod;

it('stores only non-sensitive card fields', function () {
    $buyer = makeBuyer();
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/payment-methods', [
        'type' => 'card',
        'brand' => 'Visa',
        'last4' => '4242',
        'holder' => 'Juan Dela Cruz',
        'exp_month' => '08',
        'exp_year' => 2030,
        'label' => 'Personal',
    ])->assertCreated()
        ->assertJsonPath('data.last4', '4242')
        ->assertJsonPath('data.type', 'card')
        ->assertJsonPath('data.isPrimary', true);

    $row = BuyerPaymentMethod::first()->getAttributes();
    expect($row)->not->toHaveKey('number');
    expect($row)->not->toHaveKey('cvv');
});

it('rejects a full PAN sent in last4', function () {
    actingAsBuyer(makeBuyer());

    $this->postJson('/api/buyer/payment-methods', [
        'type' => 'card',
        'brand' => 'Visa',
        'last4' => '4242424242424242',
        'holder' => 'Juan Dela Cruz',
        'exp_month' => '08',
        'exp_year' => 2030,
    ])->assertStatus(422)->assertJsonValidationErrors('last4');
});

it('keeps exactly one primary method', function () {
    $buyer = makeBuyer();
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/payment-methods', [
        'type' => 'card', 'brand' => 'Visa', 'last4' => '4242',
        'holder' => 'A', 'exp_month' => '08', 'exp_year' => 2030,
    ]);
    $second = $this->postJson('/api/buyer/payment-methods', [
        'type' => 'wallet', 'provider' => 'GCash', 'phone_masked' => '0917 •••• 4567',
        'is_primary' => true,
    ])->json('data.id');

    expect(BuyerPaymentMethod::where('buyer_profile_id', $buyer->id)->where('is_primary', true)->count())->toBe(1);
    expect(BuyerPaymentMethod::find($second)->is_primary)->toBeTrue();
});

it("won't touch another buyer's payment method", function () {
    $me = makeBuyer();
    $other = makeBuyer();
    $pm = BuyerPaymentMethod::create([
        'buyer_profile_id' => $other->id, 'type' => 'card', 'brand' => 'Visa',
        'last4' => '1111', 'holder' => 'X', 'exp_month' => '01', 'exp_year' => 2031, 'is_primary' => true,
    ]);

    actingAsBuyer($me);

    $this->deleteJson("/api/buyer/payment-methods/{$pm->id}")->assertStatus(404);
    $this->putJson("/api/buyer/payment-methods/{$pm->id}/primary")->assertStatus(404);
    expect($pm->fresh())->not->toBeNull();
});
