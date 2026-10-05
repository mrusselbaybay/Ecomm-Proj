<?php

/*
|--------------------------------------------------------------------------
| Store directory + store pages
|--------------------------------------------------------------------------
|
| GET /api/stores, GET /api/stores/{id}, and the store page's product
| browsing through GET /api/products?seller_id=... Only active sellers are
| stores, and a store only ever lists its own buyer-visible products.
|
*/

use App\Models\Address;
use App\Models\Product;
use App\Models\Profile;
use App\Models\Review;
use App\Models\SellerDetail;
use Illuminate\Support\Str;

function makeStore(string $name, string $category = 'Electronics and Gadgets', array $overrides = []): Profile
{
    $seller = makeSeller($overrides);

    SellerDetail::where('profile_id', $seller->id)->update([
        'business_name' => $name,
        'line_of_business' => $category,
    ]);

    return $seller;
}

function reviewFor(Profile $seller, int $rating, ?string $productId = null): Review
{
    return Review::forceCreate([
        'id' => (string) Str::uuid(),
        'seller_id' => $seller->id,
        'product_id' => $productId,
        'rating' => $rating,
    ]);
}

it('lists only active sellers as stores', function () {
    makeStore('Open Shop');
    makeStore('Suspended Shop', overrides: ['account_status' => 'suspended']);
    makeBuyer();

    $this->getJson('/api/stores')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Open Shop')
        ->assertJsonPath('meta.total', 1);
});

it('counts only active products and previews at most three photos', function () {
    $store = makeStore('Gadget Hub');

    foreach (range(1, 4) as $i) {
        makeProduct($store, ['images' => [['url' => "https://cdn.test/{$i}.jpg"]]]);
    }

    makeProduct($store, ['status' => 'inactive']);

    $this->getJson('/api/stores')
        ->assertOk()
        ->assertJsonPath('data.0.productCount', 4)
        ->assertJsonCount(3, 'data.0.previewImages');
});

it('returns null logo, banner, rating and location when a store has none', function () {
    makeStore('Bare Store');

    $this->getJson('/api/stores')
        ->assertOk()
        ->assertJsonPath('data.0.logo', null)
        ->assertJsonPath('data.0.banner', null)
        ->assertJsonPath('data.0.description', null)
        ->assertJsonPath('data.0.location', null)
        ->assertJsonPath('data.0.rating', null)
        ->assertJsonPath('data.0.reviewCount', 0)
        ->assertJsonPath('data.0.previewImages', []);
});

it('exposes only city and province as the location', function () {
    $store = makeStore('Located Store');

    Address::forceCreate([
        'id' => (string) Str::uuid(),
        'owner_kind' => 'profile',
        'profile_id' => $store->id,
        'province_name' => 'Cebu',
        'municipality_name' => 'Cebu City',
        'barangay' => 'Lahug',
        'street' => 'Secret Street',
        'house_no' => '12',
    ]);

    $response = $this->getJson('/api/stores')->assertOk();

    expect($response->json('data.0.location'))->toBe('Cebu City, Cebu');
    expect($response->getContent())->not->toContain('Secret Street');
});

it('searches store names case-insensitively', function () {
    makeStore('Paws and Claws', 'Pet Supplies');
    makeStore('Volt Electronics');

    $this->getJson('/api/stores?search=paws')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Paws and Claws');
});

it('treats like wildcards in a store search literally', function () {
    makeStore('Volt Electronics');

    $this->getJson('/api/stores?search=%25')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('filters by category while facets keep counts for every category', function () {
    makeStore('Paws and Claws', 'Pet Supplies');
    makeStore('Pet Palace', 'Pet Supplies');
    makeStore('Volt Electronics');

    $response = $this->getJson('/api/stores?category=Pet%20Supplies')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())
        ->toEqualCanonicalizing(['Paws and Claws', 'Pet Palace']);

    expect($response->json('facets.categories'))->toBe([
        ['name' => 'Electronics and Gadgets', 'count' => 1],
        ['name' => 'Pet Supplies', 'count' => 2],
    ]);
});

it('sorts stores by product count, name and rating', function () {
    $small = makeStore('Alpha Small');
    $big = makeStore('Zulu Big');
    $smallProduct = makeProduct($small);
    $bigProduct = makeProduct($big);
    makeProduct($big);

    reviewFor($small, 5, $smallProduct->id);
    reviewFor($big, 3, $bigProduct->id);

    $names = fn (string $sort) => collect($this->getJson("/api/stores?sort={$sort}")->json('data'))->pluck('name')->all();

    expect($names('products'))->toBe(['Zulu Big', 'Alpha Small']);
    expect($names('name'))->toBe(['Alpha Small', 'Zulu Big']);
    expect($names('rating'))->toBe(['Alpha Small', 'Zulu Big']);

    $this->getJson('/api/stores?sort=name')
        ->assertJsonPath('data.0.rating', 5)
        ->assertJsonPath('data.0.reviewCount', 1)
        ->assertJsonPath('facets.has_ratings', true);
});

it('paginates stores', function () {
    foreach (range(1, 5) as $i) {
        makeStore("Store {$i}");
    }

    $this->getJson('/api/stores?per_page=2&page=3&sort=name')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Store 5')
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonPath('meta.total', 5);
});

it('shows a store with facets of its visible products', function () {
    $store = makeStore('Volt Electronics');
    makeProduct($store, ['price' => 50, 'stock' => 0, 'condition' => 'used']);
    makeProduct($store, ['price' => 250, 'compare_price' => 300, 'condition' => 'new']);
    makeProduct($store, ['price' => 9000, 'status' => 'inactive', 'condition' => 'refurbished']);

    $this->getJson("/api/stores/{$store->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Volt Electronics')
        ->assertJsonPath('data.productFacets.total', 2)
        ->assertJsonPath('data.productFacets.priceMin', 50)
        ->assertJsonPath('data.productFacets.priceMax', 250)
        ->assertJsonPath('data.productFacets.conditions', ['new', 'used'])
        ->assertJsonPath('data.productFacets.hasSale', true)
        ->assertJsonPath('data.productFacets.hasOutOfStock', true)
        ->assertJsonPath('data.productFacets.hasRatings', false)
        ->assertJsonPath('data.productFacets.hasSold', false);
});

it('hides stores that are not active or do not exist', function () {
    $suspended = makeStore('Suspended Shop', overrides: ['account_status' => 'suspended']);

    $this->getJson("/api/stores/{$suspended->id}")->assertNotFound();
    $this->getJson('/api/stores/'.Str::uuid())->assertNotFound();
    $this->getJson('/api/stores/not-a-uuid')->assertNotFound();
});

it('scopes a product search to the selected store', function () {
    $mine = makeStore('Volt Electronics');
    $other = makeStore('Other Store');
    makeProduct($mine, ['name' => 'Wireless Charger']);
    makeProduct($mine, ['name' => 'USB Cable']);
    makeProduct($other, ['name' => 'Wireless Mouse']);
    makeProduct($mine, ['name' => 'Wireless Hidden', 'status' => 'inactive']);

    $response = $this->getJson("/api/products?seller_id={$mine->id}&search=WIRELESS")->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Wireless Charger']);
});

it('sorts and filters store products', function () {
    $store = makeStore('Volt Electronics');
    makeProduct($store, ['name' => 'Mid', 'price' => 200, 'condition' => 'new']);
    makeProduct($store, ['name' => 'Cheap', 'price' => 50, 'stock' => 0, 'condition' => 'used']);
    makeProduct($store, ['name' => 'Pricey', 'price' => 900, 'compare_price' => 1200, 'condition' => 'new']);

    $names = fn (string $query) => collect($this->getJson("/api/products?seller_id={$store->id}&{$query}")->json('data'))->pluck('name')->all();

    expect($names('sort=price-asc'))->toBe(['Cheap', 'Mid', 'Pricey']);
    expect($names('sort=price-desc'))->toBe(['Pricey', 'Mid', 'Cheap']);
    expect($names('sort=name-asc'))->toBe(['Cheap', 'Mid', 'Pricey']);
    expect($names('in_stock=1&sort=price-asc'))->toBe(['Mid', 'Pricey']);
    expect($names('on_sale=1'))->toBe(['Pricey']);
    expect($names('condition=used'))->toBe(['Cheap']);
    expect($names('price_min=100&price_max=500'))->toBe(['Mid']);
});

it('filters and sorts store products by rating', function () {
    $store = makeStore('Volt Electronics');
    $good = makeProduct($store, ['name' => 'Good']);
    $okay = makeProduct($store, ['name' => 'Okay']);
    makeProduct($store, ['name' => 'Unrated']);

    reviewFor($store, 5, $good->id);
    reviewFor($store, 3, $okay->id);

    $names = fn (string $query) => collect($this->getJson("/api/products?seller_id={$store->id}&{$query}")->json('data'))->pluck('name')->all();

    expect($names('min_rating=4'))->toBe(['Good']);
    expect($names('sort=rating'))->toBe(['Good', 'Okay', 'Unrated']);
});

it('counts sold units from delivered orders only and sorts by popularity', function () {
    $store = makeStore('Volt Electronics');
    $buyer = makeBuyer();
    $hit = makeProduct($store, ['name' => 'Hit']);
    $steady = makeProduct($store, ['name' => 'Steady']);
    makeProduct($store, ['name' => 'New']);

    $sell = function (Product $product, int $quantity, string $status) use ($buyer, $store): void {
        [, $item] = makeOrder($buyer, $store, ['status' => $status]);
        $item->update(['product_id' => $product->id, 'quantity' => $quantity]);
    };

    $sell($hit, 5, 'Delivered');
    $sell($hit, 2, 'Delivered');
    $sell($steady, 3, 'Delivered');
    $sell($steady, 9, 'Cancelled');
    $sell($steady, 4, 'In Transit');

    $response = $this->getJson("/api/products?seller_id={$store->id}&sort=popular")->assertOk();

    expect(collect($response->json('data'))->pluck('soldCount', 'name')->all())
        ->toBe(['Hit' => 7, 'Steady' => 3, 'New' => 0]);

    $this->getJson("/api/stores/{$store->id}")
        ->assertOk()
        ->assertJsonPath('data.productFacets.hasSold', true);
});
