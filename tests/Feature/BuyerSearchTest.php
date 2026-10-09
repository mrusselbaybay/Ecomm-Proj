<?php

it('searches products and suggestions using portable like escaping', function () {
    $seller = makeSeller(['first_name' => 'Tech']);
    $matching = makeProduct($seller, ['name' => 'Tech Headphones']);
    makeProduct($seller, ['name' => 'Kitchen Mixer']);

    $this->getJson('/api/products?search=tec&sort=relevance')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $matching->id);

    $this->getJson('/api/search/suggestions?q=tec')
        ->assertOk()
        ->assertJsonPath('products.0.id', $matching->id);
});
