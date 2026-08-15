<?php

use Illuminate\Support\Facades\Http;

it('returns PSGC data in the frontend shape', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://psgc.gitlab.io/api/regions*' => Http::response([
            ['code' => '010000000', 'name' => 'Ilocos Region'],
        ]),
    ]);

    $response = $this->getJson('/api/psgc/regions?limit=100');

    $response->assertOk()
        ->assertJsonPath('data.0.name', 'Ilocos Region')
        ->assertJsonStructure([
            'data' => [
                ['code', 'name'],
            ],
        ]);
});

it('deduplicates repeated province entries by code', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://psgc.gitlab.io/api/provinces*' => Http::response([
            ['code' => '012800000', 'name' => 'Laguna'],
            ['code' => '012800000', 'name' => 'Laguna'],
            ['code' => '013300000', 'name' => 'Batangas'],
        ]),
    ]);

    $response = $this->getJson('/api/psgc/provinces?region_code=010000000');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Batangas')
        ->assertJsonPath('data.1.name', 'Laguna');
});
