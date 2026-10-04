<?php

/*
|--------------------------------------------------------------------------
| GET /api/products?ids=...
|--------------------------------------------------------------------------
|
| Lets the buyer cart re-check every line in one request instead of one
| request per product. Only visible products come back; anything missing
| is treated as unavailable by the cart.
|
*/

it('returns only the requested products', function () {
    $seller = makeSeller();
    $wanted = makeProduct($seller, ['name' => 'Wanted']);
    $alsoWanted = makeProduct($seller, ['name' => 'Also wanted']);
    makeProduct($seller, ['name' => 'Not requested']);

    $response = $this->getJson("/api/products?ids={$wanted->id},{$alsoWanted->id}");

    $response->assertOk()->assertJsonCount(2, 'data');

    expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$wanted->id, $alsoWanted->id])->sort()->values()->all());
});

it('leaves out products that are not visible', function () {
    $seller = makeSeller();
    $active = makeProduct($seller);
    $inactive = makeProduct($seller, ['status' => 'inactive']);

    $this->getJson("/api/products?ids={$active->id},{$inactive->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $active->id);
});

it('ignores values that are not product ids', function () {
    $product = makeProduct(makeSeller());

    $this->getJson("/api/products?ids={$product->id},not-a-uuid,,")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson('/api/products?ids=not-a-uuid')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
