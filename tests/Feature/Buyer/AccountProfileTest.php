<?php

it('updates only the whitelisted profile fields', function () {
    $buyer = makeBuyer(['first_name' => 'Old', 'last_name' => 'Name']);
    actingAsBuyer($buyer);

    $this->putJson('/api/buyer/account/profile', [
        'first_name' => 'New',
        'last_name' => 'Person',
        'middle_initial' => 'q',
        'sex' => 'Female',
        'contact_no' => '09170001111',
        'birthday' => '1995-05-20',
    ])->assertOk()
        ->assertJsonPath('data.first_name', 'New')
        ->assertJsonPath('data.middle_initial', 'Q'); // upper-cased, single char

    $buyer->refresh();
    expect($buyer->first_name)->toBe('New');
    expect($buyer->last_name)->toBe('Person');
    expect($buyer->sex)->toBe('Female');
});

it('ignores attempts to change role or account_status', function () {
    $buyer = makeBuyer();
    actingAsBuyer($buyer);

    $this->putJson('/api/buyer/account/profile', [
        'first_name' => 'Still',
        'last_name' => 'Buyer',
        'role' => 'admin',
        'account_status' => 'suspended',
    ])->assertOk();

    $buyer->refresh();
    expect($buyer->role)->toBe('buyer');
    expect($buyer->account_status)->toBe('active');
});

it('rejects an unknown sex value and a future birthday', function () {
    actingAsBuyer(makeBuyer());

    $this->putJson('/api/buyer/account/profile', [
        'first_name' => 'A', 'last_name' => 'B', 'sex' => 'Other',
    ])->assertStatus(422)->assertJsonValidationErrors('sex');

    $this->putJson('/api/buyer/account/profile', [
        'first_name' => 'A', 'last_name' => 'B', 'birthday' => now()->addYear()->toDateString(),
    ])->assertStatus(422)->assertJsonValidationErrors('birthday');
});

it('requires an active approved buyer', function () {
    $pending = makeBuyer(['status' => 'pending', 'account_status' => 'pending']);
    actingAsBuyer($pending);

    $this->putJson('/api/buyer/account/profile', ['first_name' => 'X', 'last_name' => 'Y'])
        ->assertStatus(403);
});
