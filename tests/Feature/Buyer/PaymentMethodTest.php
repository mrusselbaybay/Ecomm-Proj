<?php

/*
|--------------------------------------------------------------------------
| Buyer payment methods: provider-backed only, owner-scoped, one default
|--------------------------------------------------------------------------
*/

use App\Models\BuyerPaymentMethod;
use App\Models\Order;
use App\Support\SavedPaymentSupport;

function savedCard($buyer, array $overrides = []): BuyerPaymentMethod
{
    return BuyerPaymentMethod::forceCreate(array_merge([
        'buyer_profile_id' => $buyer->id,
        'type' => 'card',
        'brand' => 'Visa',
        'last4' => '4242',
        'holder' => 'Juan Dela Cruz',
        'exp_month' => '08',
        'exp_year' => (int) now()->addYears(3)->format('Y'),
        'provider_token' => 'pm_sandbox_'.uniqid(),
        'is_primary' => false,
    ], $overrides));
}

/** A sandbox provider integration, registered the way a real one would be. */
function enableSandboxVault(): void
{
    SavedPaymentSupport::$drivers = ['sandbox'];
    config(['services.payment_vault.provider' => 'sandbox', 'services.payment_vault.secret_key' => 'sk_test_x']);
}

afterEach(function () {
    SavedPaymentSupport::$drivers = [];
});

it('reports that saving needs a provider and lists what checkout accepts', function () {
    actingAsBuyer(makeBuyer());

    $this->getJson('/api/buyer/payment-methods')
        ->assertOk()
        ->assertJsonPath('meta.saving.enabled', false)
        ->assertJsonPath('meta.checkoutMethods.0.id', 'cod')
        ->assertJsonCount(1, 'meta.checkoutMethods')
        ->assertJson(fn ($json) => $json->has('meta.saving.missing.0')->etc());
});

it('never accepts card or wallet details from the browser', function () {
    actingAsBuyer(makeBuyer());

    $this->postJson('/api/buyer/payment-methods', [
        'type' => 'card', 'brand' => 'Visa', 'last4' => '4242424242424242',
        'holder' => 'Juan Dela Cruz', 'exp_month' => '08', 'exp_year' => 2030,
    ])->assertStatus(405);

    $this->postJson('/api/buyer/payment-methods', [
        'type' => 'wallet', 'provider' => 'GCash', 'phone_masked' => '0917 •••• 4567',
    ])->assertStatus(405);

    expect(BuyerPaymentMethod::count())->toBe(0);
});

it('shows display details only, never the provider reference', function () {
    $buyer = makeBuyer();
    savedCard($buyer, ['is_primary' => true]);
    enableSandboxVault();
    actingAsBuyer($buyer);

    $response = $this->getJson('/api/buyer/payment-methods')
        ->assertOk()
        ->assertJsonPath('data.0.last4', '4242')
        ->assertJsonPath('data.0.usable', true)
        ->assertJsonPath('data.0.isDefault', true);

    expect($response->getContent())->not->toContain('pm_sandbox_')
        ->and($response->json('data.0'))->not->toHaveKeys(['provider_token', 'holder', 'number', 'cvv']);
});

it('marks details typed in before payments were connected as unusable', function () {
    $buyer = makeBuyer();
    $legacy = savedCard($buyer, ['provider_token' => null, 'is_primary' => true]);
    enableSandboxVault();
    actingAsBuyer($buyer);

    $this->getJson('/api/buyer/payment-methods')
        ->assertJsonPath('data.0.usable', false)
        ->assertJsonPath('data.0.isDefault', false)
        ->assertJsonPath('data.0.unusableReason', 'not_linked');

    $this->putJson("/api/buyer/payment-methods/{$legacy->id}/primary")->assertStatus(422);
});

it('flags expired cards and will not make them the default', function () {
    $buyer = makeBuyer();
    $expired = savedCard($buyer, ['exp_month' => '01', 'exp_year' => 2020]);
    enableSandboxVault();
    actingAsBuyer($buyer);

    $this->getJson('/api/buyer/payment-methods')
        ->assertJsonPath('data.0.expired', true)
        ->assertJsonPath('data.0.unusableReason', 'expired');

    $this->putJson("/api/buyer/payment-methods/{$expired->id}/primary")->assertStatus(422);
});

it('keeps exactly one default method', function () {
    $buyer = makeBuyer();
    $first = savedCard($buyer, ['is_primary' => true]);
    $second = savedCard($buyer, ['last4' => '1881']);
    enableSandboxVault();
    actingAsBuyer($buyer);

    $this->putJson("/api/buyer/payment-methods/{$second->id}/primary")
        ->assertOk()
        ->assertJsonPath('data.isDefault', true);

    expect(BuyerPaymentMethod::where('buyer_profile_id', $buyer->id)->where('is_primary', true)->pluck('id')->all())
        ->toBe([$second->id])
        ->and($first->fresh()->is_primary)->toBeFalse();
});

it('passes the default on to the newest usable method when it is removed, leaving orders alone', function () {
    $buyer = makeBuyer();
    $default = savedCard($buyer, ['is_primary' => true, 'created_at' => now()->subDays(3)]);
    $usable = savedCard($buyer, ['last4' => '1881', 'created_at' => now()->subDays(2)]);
    savedCard($buyer, ['last4' => '0005', 'provider_token' => null, 'created_at' => now()->subDay()]);
    [$order] = makeOrder($buyer, makeSeller(), ['payment_method' => 'cod']);
    enableSandboxVault();
    actingAsBuyer($buyer);

    $this->deleteJson("/api/buyer/payment-methods/{$default->id}")->assertOk();

    expect(BuyerPaymentMethod::find($default->id))->toBeNull()
        ->and($usable->fresh()->is_primary)->toBeTrue()
        ->and(Order::find($order->id)->payment_method)->toBe('cod');
});

it("won't touch another buyer's payment method", function () {
    $other = makeBuyer();
    $pm = savedCard($other, ['is_primary' => true]);
    enableSandboxVault();
    actingAsBuyer(makeBuyer());

    $this->getJson('/api/buyer/payment-methods')->assertJsonCount(0, 'data');
    $this->deleteJson("/api/buyer/payment-methods/{$pm->id}")->assertStatus(404);
    $this->putJson("/api/buyer/payment-methods/{$pm->id}/primary")->assertStatus(404);
    $this->deleteJson('/api/buyer/payment-methods/not-a-uuid')->assertStatus(404);

    expect($pm->fresh())->not->toBeNull()->and($pm->fresh()->is_primary)->toBeTrue();
});

it('requires a signed-in buyer', function () {
    $this->getJson('/api/buyer/payment-methods')->assertUnauthorized();
});
