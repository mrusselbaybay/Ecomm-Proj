<?php

use App\Models\BuyerAddress;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

// The hand-made test schema has no public.addresses (a Supabase table),
// which BuyerAddressSync writes to on every address save.
beforeEach(function () {
    if (! Schema::hasTable('addresses')) {
        Schema::create('addresses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('owner_kind')->nullable();
            $t->uuid('profile_id')->nullable();
            $t->string('logistics_company_id')->nullable();
            foreach (['region_code', 'region_name', 'province_code', 'province_name', 'municipality_code',
                'municipality_name', 'barangay', 'street', 'house_no'] as $col) {
                $t->string($col)->nullable();
            }
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->timestamps();
        });
    }
});

function pinnedAddressPayload(array $overrides = []): array
{
    return array_merge([
        'recipient_name' => 'Juan Dela Cruz',
        'contact_no' => '09171234567',
        'line1' => 'Mabini St',
        'region_name' => 'Luzon',
        'province_code' => '0434',
        'province' => 'Laguna',
        'municipality_code' => '043404',
        'city' => 'Calamba',
        'barangay' => 'Real',
        'label' => 'Home',
        'latitude' => 14.2117,
        'longitude' => 121.1653,
    ], $overrides);
}

it('saves and returns a buyer address pin', function () {
    actingAsBuyer(makeBuyer());

    $this->postJson('/api/buyer/addresses', pinnedAddressPayload())
        ->assertCreated()
        ->assertJsonPath('data.pin.lat', 14.2117)
        ->assertJsonPath('data.pin.lng', 121.1653);
});

it('rejects a pin outside the Philippines or with only one coordinate', function () {
    actingAsBuyer(makeBuyer());

    $this->postJson('/api/buyer/addresses', pinnedAddressPayload(['latitude' => 0, 'longitude' => 0]))
        ->assertJsonValidationErrors(['latitude', 'longitude']);

    $this->postJson('/api/buyer/addresses', pinnedAddressPayload(['longitude' => null]))
        ->assertJsonValidationErrors(['longitude']);
});

it('drops a stale pin when the area changes without a new pin', function () {
    $buyer = makeBuyer();
    actingAsBuyer($buyer);

    $id = $this->postJson('/api/buyer/addresses', pinnedAddressPayload())->json('data.id');

    // Same area, no pin keys (older client): pin kept.
    $this->putJson("/api/buyer/addresses/{$id}", ['line1' => 'Rizal St'])
        ->assertOk()
        ->assertJsonPath('data.pin.lat', 14.2117);

    // Moved barangay without re-pinning: pin cleared.
    $this->putJson("/api/buyer/addresses/{$id}", ['barangay' => 'Parian'])
        ->assertOk()
        ->assertJsonPath('data.pin', null);

    // Moved again WITH a new pin: new pin kept.
    $this->putJson("/api/buyer/addresses/{$id}", ['barangay' => 'Halang', 'latitude' => 14.2, 'longitude' => 121.15])
        ->assertOk()
        ->assertJsonPath('data.pin.lat', 14.2);

    expect(BuyerAddress::find($id)->latitude)->toBe(14.2);
});

it('opens the pin picker on the most specific place geocoding finds', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::sequence()
            ->push([], 200) // street not found
            ->push([['lat' => '14.2150', 'lon' => '121.1600']], 200), // barangay found
    ]);

    $this->getJson('/api/geo/locate?street=Mabini%20St&barangay=Real&municipality=Calamba&province=Laguna')
        ->assertOk()
        ->assertJsonPath('data.precision', 'barangay')
        ->assertJsonPath('data.lat', 14.215)
        ->assertJsonPath('data.zoom', 15);
});

it('falls back to the offline town centre, then the whole country, when geocoding is down', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 503)]);

    $this->getJson('/api/geo/locate?municipality=Calamba&province=Laguna')
        ->assertOk()
        ->assertJsonPath('data.precision', 'approximate');

    $this->getJson('/api/geo/locate?municipality=Nowhere&province=Atlantis')
        ->assertOk()
        ->assertJsonPath('data.precision', 'country');
});
