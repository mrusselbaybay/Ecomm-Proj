<?php

/*
|--------------------------------------------------------------------------
| Category subcategories for the buyer's category filters
|--------------------------------------------------------------------------
*/

use App\Support\CategoryFieldConfig;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // products.subcategory is added on Postgres only (see its migration).
    if (! Schema::hasColumn('products', 'subcategory')) {
        Schema::table('products', fn (Blueprint $table) => $table->string('subcategory')->nullable());
    }
});

it('lists every category with the subcategories sellers pick from', function () {
    $response = $this->getJson('/api/catalog/subcategories')->assertOk();

    foreach (CategoryFieldConfig::categories() as $category) {
        expect($response->json("data.{$category}"))->toBe(CategoryFieldConfig::subcategoriesFor($category));
    }

    expect($response->json('data.Pet Supplies'))->toContain('Food & Treats', 'Toys');
});

it('returns each product with its stored subcategory', function () {
    $seller = makeSeller();
    makeProduct($seller, ['name' => 'Chew rope', 'category' => 'Pet Supplies', 'subcategory' => 'Toys']);
    makeProduct($seller, ['name' => 'Old listing', 'category' => 'Pet Supplies']);

    $products = collect($this->getJson('/api/products')->assertOk()->json('data'))->keyBy('name');

    expect($products['Chew rope']['subcategory'])->toBe('Toys');
    expect($products['Old listing']['subcategory'])->toBeNull();
});

it('labels specifications from the product subcategory template', function () {
    $product = makeProduct(makeSeller(), [
        'category' => 'Pet Supplies',
        'subcategory' => 'Toys',
        'specifications' => ['animal_type' => 'Dog', 'toy_type' => 'Chew Toy'],
    ]);

    $this->getJson("/api/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.specifications', ['Animal Type' => 'Dog', 'Toy Type' => 'Chew Toy']);
});

it('still labels specifications of listings made before subcategories', function () {
    $product = makeProduct(makeSeller(), [
        'category' => 'Pet Supplies',
        'specifications' => ['animal_type' => 'Cat', 'food_type' => 'Treats'],
    ]);

    $this->getJson("/api/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.specifications', ['Animal Type' => 'Cat', 'Food Type' => 'Treats']);
});
