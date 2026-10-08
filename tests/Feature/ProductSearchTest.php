<?php

/*
|--------------------------------------------------------------------------
| GET /api/products?search=... for the buyer's unified search results
|--------------------------------------------------------------------------
|
| The whole visible catalog is searched on the server (paginated), by
| product name, description, category, subcategory and store name, with a
| stable relevance order and sidebar facets.
|
*/

use App\Models\Profile;
use App\Models\Review;
use App\Models\SellerDetail;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    // products.subcategory is added on Postgres only (see its migration).
    if (! Schema::hasColumn('products', 'subcategory')) {
        Schema::table('products', fn (Blueprint $table) => $table->string('subcategory')->nullable());
    }
});

function namedStore(string $name): Profile
{
    $seller = makeSeller();
    SellerDetail::where('profile_id', $seller->id)->update(['business_name' => $name]);

    return $seller;
}

function searchNames(array $query): array
{
    return collect(test()->getJson('/api/products?'.http_build_query($query))->assertOk()->json('data'))
        ->pluck('name')
        ->all();
}

it('finds a store\'s products by the store name', function () {
    $store = namedStore('Whisker Haven');
    makeProduct($store, ['name' => 'Scratching post']);
    makeProduct(namedStore('Other Shop'), ['name' => 'Desk lamp']);

    expect(searchNames(['search' => 'whisker']))->toBe(['Scratching post']);
});

it('matches name, description, category and subcategory case-insensitively', function () {
    $seller = makeSeller();
    makeProduct($seller, ['name' => 'Rope TOY']);
    makeProduct($seller, ['name' => 'Ball', 'description' => 'A bouncy toy for dogs']);
    makeProduct($seller, ['name' => 'Chew', 'category' => 'Pet Supplies', 'subcategory' => 'Toys']);
    makeProduct($seller, ['name' => 'Charger']);

    expect(searchNames(['search' => 'toy', 'sort' => 'name-asc']))->toBe(['Ball', 'Chew', 'Rope TOY']);
});

it('treats punctuation as a word break, never as a wildcard', function () {
    $seller = makeSeller();
    makeProduct($seller, ['name' => '100% cotton shirt']);
    makeProduct($seller, ['name' => '100 pack of pens']);
    makeProduct($seller, ['name' => 'Plain mug']);

    expect(searchNames(['search' => 'cotton-shirt!']))->toBe(['100% cotton shirt']);
    expect(searchNames(['search' => '100%', 'sort' => 'name-asc']))->toBe(['100 pack of pens', '100% cotton shirt']);
    expect(searchNames(['search' => '%']))->toBe([]);
    expect(searchNames(['search' => '_']))->toBe([]);
});

it('requires every word of a multiword query, across fields', function () {
    $store = namedStore('Paw Pantry');
    makeProduct($store, ['name' => 'Salmon kibble', 'category' => 'Pet Supplies']);
    makeProduct($store, ['name' => 'Chicken kibble', 'category' => 'Pet Supplies']);
    makeProduct(makeSeller(), ['name' => 'Salmon fillet', 'category' => 'House and Garden']);

    expect(searchNames(['search' => 'salmon kibble']))->toBe(['Salmon kibble']);
    expect(searchNames(['search' => 'kibble pet', 'sort' => 'name-asc']))->toBe(['Chicken kibble', 'Salmon kibble']);
    expect(searchNames(['search' => 'paw salmon']))->toBe(['Salmon kibble']);
});

it('ranks relevance by how the product matched, with stable ties', function () {
    $store = namedStore('Lamp Masters');
    $other = makeSeller();

    makeProduct($other, ['name' => 'Notebook', 'description' => 'Reads well under a lamp'])->forceFill(['created_at' => now()->subDays(1)])->save();
    makeProduct($store, ['name' => 'Ceiling fan'])->forceFill(['created_at' => now()->subDays(2)])->save();
    makeProduct($other, ['name' => 'Desk lamp'])->forceFill(['created_at' => now()->subDays(3)])->save();
    makeProduct($other, ['name' => 'Lamp shade'])->forceFill(['created_at' => now()->subDays(4)])->save();
    makeProduct($other, ['name' => 'Lamp'])->forceFill(['created_at' => now()->subDays(5)])->save();
    makeProduct($other, ['name' => 'Floor lamp'])->forceFill(['created_at' => now()->subDays(6)])->save();

    expect(searchNames(['search' => 'lamp', 'sort' => 'relevance']))->toBe([
        'Lamp',
        'Lamp shade',
        'Desk lamp',
        'Floor lamp',
        'Ceiling fan',
        'Notebook',
    ]);
});

it('pages through more than 100 matching products', function () {
    $seller = makeSeller();

    foreach (range(1, 120) as $n) {
        makeProduct($seller, ['name' => sprintf('Widget %03d', $n)]);
    }

    $response = $this->getJson('/api/products?search=widget&sort=name-asc&per_page=24&page=5')->assertOk();

    expect($response->json('meta.total'))->toBe(120);
    expect($response->json('meta.last_page'))->toBe(5);
    expect(collect($response->json('data'))->pluck('name')->first())->toBe('Widget 097');
    expect($response->json('data'))->toHaveCount(24);
});

it('filters by category and subcategories', function () {
    $seller = makeSeller();
    makeProduct($seller, ['name' => 'Kibble', 'category' => 'Pet Supplies', 'subcategory' => 'Food & Treats']);
    makeProduct($seller, ['name' => 'Squeaky bone', 'category' => 'Pet Supplies', 'subcategory' => 'Toys']);
    makeProduct($seller, ['name' => 'Leash', 'category' => 'Pet Supplies', 'subcategory' => 'Accessories']);
    makeProduct($seller, ['name' => 'Phone case', 'category' => 'Electronics and Gadgets']);

    expect(searchNames(['category' => 'pet supplies', 'sort' => 'name-asc']))->toBe(['Kibble', 'Leash', 'Squeaky bone']);
    expect(searchNames(['category' => 'Pet Supplies', 'subcategory' => ['Toys', 'Food & Treats'], 'sort' => 'name-asc']))
        ->toBe(['Kibble', 'Squeaky bone']);
});

it('returns facets computed without their own filter', function () {
    $seller = makeSeller();
    makeProduct($seller, ['name' => 'Cat food', 'category' => 'Pet Supplies', 'subcategory' => 'Food & Treats']);
    makeProduct($seller, ['name' => 'Cat toy', 'category' => 'Pet Supplies', 'subcategory' => 'Toys']);
    makeProduct($seller, ['name' => 'Cat shirt', 'category' => "Men's Apparel"]);
    makeProduct($seller, ['name' => 'Dog toy', 'category' => 'Pet Supplies', 'subcategory' => 'Toys']);

    $facets = $this->getJson('/api/products?'.http_build_query([
        'search' => 'cat',
        'category' => 'Pet Supplies',
        'subcategory' => ['Toys'],
        'facets' => 1,
    ]))->assertOk()->assertJsonPath('meta.total', 1)->json('facets');

    expect($facets['categories'])->toBe([
        ['name' => "Men's Apparel", 'count' => 1],
        ['name' => 'Pet Supplies', 'count' => 2],
    ]);
    expect(collect($facets['subcategories'])->sortBy('name')->values()->all())->toBe([
        ['name' => 'Food & Treats', 'count' => 1],
        ['name' => 'Toys', 'count' => 1],
    ]);
    expect($facets['has_ratings'])->toBeFalse();
    expect($facets['has_sales'])->toBeFalse();
});

it('reports the price range of the matches, ignoring the price filter', function () {
    $seller = makeSeller();
    makeProduct($seller, ['name' => 'Cheap pen', 'price' => 15]);
    makeProduct($seller, ['name' => 'Fancy pen', 'price' => 950]);
    makeProduct($seller, ['name' => 'Notebook', 'price' => 5]);

    $this->getJson('/api/products?search=pen&price_min=100&facets=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('facets.price', ['min' => 15, 'max' => 950]);

    $this->getJson('/api/products?search=zzz&facets=1')
        ->assertOk()
        ->assertJsonPath('facets.price', null);
});

it('reports ratings and completed sales only when they exist', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    $product = makeProduct($seller, ['name' => 'Rated mug']);

    [, $item] = makeOrder($buyer, $seller, ['status' => 'Delivered', 'payment_status' => 'Paid']);
    $item->update(['product_id' => $product->id]);

    Review::create([
        'id' => (string) Str::uuid(),
        'product_id' => $product->id,
        'seller_id' => $seller->id,
        'buyer_id' => $buyer->id,
        'rating' => 4,
        'comment' => 'Nice.',
    ]);

    $this->getJson('/api/products?search=mug&facets=1')
        ->assertOk()
        ->assertJsonPath('facets.has_ratings', true)
        ->assertJsonPath('facets.has_sales', true);
});

it('never returns products of inactive sellers or inactive listings', function () {
    $active = namedStore('Sunny Shop');
    $suspended = namedStore('Sunny Suspended');
    $suspended->update(['account_status' => 'suspended']);

    makeProduct($active, ['name' => 'Sunny hat']);
    makeProduct($active, ['name' => 'Sunny draft', 'status' => 'inactive']);
    makeProduct($suspended, ['name' => 'Sunny umbrella']);

    expect(searchNames(['search' => 'sunny']))->toBe(['Sunny hat']);
});

it('computes every sidebar facet in one query, independent of the page and sort', function () {
    $seller = makeSeller();

    foreach (range(1, 30) as $n) {
        makeProduct($seller, [
            'name' => "Cat item {$n}",
            'category' => 'Pet Supplies',
            'subcategory' => $n % 2 ? 'Toys' : 'Food & Treats',
            'price' => $n * 10,
        ]);
    }

    makeProduct($seller, ['name' => 'Cat phone stand', 'category' => 'Electronics and Gadgets', 'price' => 999]);

    $query = ['search' => 'cat', 'category' => 'Pet Supplies', 'subcategory' => ['Toys'], 'price_min' => 50, 'per_page' => 5];

    $count = function (array $params): int {
        $queries = 0;
        // Data queries only: sqlite's schema introspection (hasColumn)
        // doesn't happen on PostgreSQL.
        DB::listen(function ($query) use (&$queries) {
            if (! str_contains(strtolower($query->sql), 'pragma') && ! str_contains($query->sql, 'sqlite_master')) {
                $queries++;
            }
        });
        $this->getJson('/api/products?'.http_build_query($params))->assertOk();

        return $queries;
    };

    expect($count([...$query, 'facets' => 1]) - $count($query))->toBe(1);

    $page1 = $this->getJson('/api/products?'.http_build_query([...$query, 'facets' => 1]))->json();
    $page3 = $this->getJson('/api/products?'.http_build_query([...$query, 'facets' => 1, 'page' => 3, 'sort' => 'price-desc']))->json();

    // Toys priced 50 and up: 5, 7, ... 29 -> 13 products.
    expect($page1['meta']['total'])->toBe(13);
    expect($page3['facets'])->toBe($page1['facets']);
    expect($page1['facets']['categories'])->toBe([
        ['name' => 'Electronics and Gadgets', 'count' => 1],
        ['name' => 'Pet Supplies', 'count' => 26],
    ]);
    expect(collect($page1['facets']['subcategories'])->sortBy('name')->values()->all())->toBe([
        ['name' => 'Food & Treats', 'count' => 13],
        ['name' => 'Toys', 'count' => 13],
    ]);
    expect($page1['facets']['price'])->toEqual(['min' => 10, 'max' => 290]);
});

it('ranks stores by how well their name matches, then by product count', function () {
    $contains = namedStore('The Pawsome Den');
    $word = namedStore('Happy Paws');
    $prefix = namedStore('Paws and Claws');
    $exact = namedStore('Paws');
    $bigPrefix = namedStore('Pawsitive Pets');

    foreach (range(1, 3) as $n) {
        makeProduct($bigPrefix, ['name' => "Treat {$n}"]);
    }

    makeProduct($contains, ['name' => 'Leash']);
    makeProduct($contains, ['name' => 'Collar']);

    $names = collect($this->getJson('/api/stores?search=paws')->assertOk()->json('data'))->pluck('name')->all();

    expect($names)->toBe(['Paws', 'Pawsitive Pets', 'Paws and Claws', 'Happy Paws', 'The Pawsome Den']);
});

it('recommends related products from the categories of the matches, never the matches themselves', function () {
    $seller = makeSeller();
    makeProduct($seller, ['name' => 'Cat bed', 'category' => 'Pet Supplies', 'subcategory' => 'Bedding & Housing']);
    makeProduct($seller, ['name' => 'Dog crate', 'category' => 'Pet Supplies', 'subcategory' => 'Bedding & Housing']);
    makeProduct($seller, ['name' => 'Dog leash', 'category' => 'Pet Supplies', 'subcategory' => 'Accessories']);
    makeProduct($seller, ['name' => 'Hidden kennel', 'category' => 'Pet Supplies', 'subcategory' => 'Bedding & Housing', 'status' => 'inactive']);
    makeProduct($seller, ['name' => 'Desk lamp', 'category' => 'Electronics and Gadgets']);

    $suspended = makeSeller(['account_status' => 'suspended']);
    makeProduct($suspended, ['name' => 'Suspended hutch', 'category' => 'Pet Supplies', 'subcategory' => 'Bedding & Housing']);

    $response = $this->getJson('/api/products/related?search=cat')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Dog crate', 'Dog leash']);
    expect($response->json('basis'))->toBe([
        'type' => 'category',
        'categories' => [['category' => 'Pet Supplies', 'subcategory' => 'Bedding & Housing']],
    ]);
});

it('returns no related products when nothing anchors them', function () {
    makeProduct(makeSeller(), ['name' => 'Desk lamp', 'category' => 'Electronics and Gadgets']);

    $this->getJson('/api/products/related?search=zzzz')
        ->assertOk()
        ->assertExactJson(['data' => [], 'basis' => null]);

    $this->getJson('/api/products/related?search=%25')
        ->assertOk()
        ->assertExactJson(['data' => [], 'basis' => null]);
});

it('suggests products and stores from the whole catalogue', function () {
    $seller = namedStore('Gizmo Garage');

    foreach (range(1, 120) as $n) {
        makeProduct($seller, ['name' => sprintf('Widget %03d', $n)]);
    }

    makeProduct(makeSeller(), ['name' => 'Gizmo charger']);

    $response = $this->getJson('/api/search/suggestions?q=widget 118')->assertOk();

    expect($response->json('products'))->toHaveCount(1);
    expect($response->json('products.0'))->toHaveKeys(['id', 'name', 'price', 'image', 'category']);
    expect($response->json('products.0.name'))->toBe('Widget 118');

    $gizmo = $this->getJson('/api/search/suggestions?q=gizmo')->assertOk();

    expect(collect($gizmo->json('stores'))->pluck('name')->all())->toBe(['Gizmo Garage']);
    expect($gizmo->json('products'))->toHaveCount(5);
    expect($gizmo->json('products.0.name'))->toBe('Gizmo charger');

    $this->getJson('/api/search/suggestions?q=g')
        ->assertOk()
        ->assertJsonPath('products', [])
        ->assertJsonPath('stores', []);
});
