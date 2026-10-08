<?php

/*
|--------------------------------------------------------------------------
| Checkout quote, per-seller shipping, changed totals and duplicate submits
|--------------------------------------------------------------------------
*/

use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Profile;
use App\Models\SellerDetail;
use Illuminate\Support\Str;

function namedSeller(string $name): Profile
{
    $seller = makeSeller();
    SellerDetail::where('profile_id', $seller->id)->update(['business_name' => $name]);

    return $seller;
}

function quoteAddress(): array
{
    return [
        'recipient_name' => 'Maria Santos',
        'contact_number' => '09171234567',
        'address' => '12 Mabini St, Quezon City, Metro Manila',
        'city' => 'Quezon City',
        'province' => 'Metro Manila',
    ];
}

it('quotes every line at today\'s price, grouped by store with per-store shipping', function () {
    $buyer = makeBuyer();
    $mugs = namedSeller('Mug House');
    $pets = namedSeller('Pet Corner');
    $mug = makeProduct($mugs, ['name' => 'Mug', 'price' => 150]);
    $bowl = makeProduct($pets, ['name' => 'Bowl', 'price' => 80]);

    actingAsBuyer($buyer);

    $quote = $this->postJson('/api/buyer/checkout/quote', [
        'items' => [
            ['product_id' => $mug->id, 'quantity' => 2],
            ['product_id' => $bowl->id, 'quantity' => 1],
        ],
        'shipping_methods' => [$pets->id => 'express'],
    ])->assertOk()->json('data');

    $byStore = collect($quote['sellers'])->keyBy('store_name');

    expect($byStore['Mug House']['subtotal'])->toEqual(300);
    expect($byStore['Mug House']['shipping']['method'])->toBe('standard');
    expect($byStore['Mug House']['shipping']['fee'])->toEqual(60);
    expect($byStore['Pet Corner']['shipping']['method'])->toBe('express');
    expect($byStore['Pet Corner']['shipping']['fee'])->toEqual(120);
    expect($byStore['Pet Corner']['shipping']['options'])->toHaveCount(2);
    expect($quote['totals']['subtotal'])->toEqual(380);
    expect($quote['totals']['shipping'])->toEqual(180);
    expect($quote['totals']['total'])->toEqual(560);
    expect($quote['totals']['item_count'])->toBe(3);
    expect($quote['can_place'])->toBeTrue();
    expect($quote['vouchers_supported'])->toBeFalse();
    expect(Order::count())->toBe(0);
});

it('flags lines that can\'t be bought and leaves them out of the totals', function () {
    $buyer = makeBuyer();
    $seller = namedSeller('Pet Corner');
    $collar = makeProduct($seller, ['name' => 'Collar', 'price' => 90, 'has_variants' => true]);
    $red = ProductVariant::create(['id' => (string) Str::uuid(), 'product_id' => $collar->id, 'sku' => 'C-RED', 'price' => 95, 'stock' => 3, 'status' => 'active']);
    $blue = ProductVariant::create(['id' => (string) Str::uuid(), 'product_id' => $collar->id, 'sku' => 'C-BLUE', 'price' => 95, 'stock' => 3, 'status' => 'unavailable']);
    $bed = makeProduct($seller, ['name' => 'Bed', 'stock' => 1]);
    $hidden = makeProduct(makeSeller(['account_status' => 'suspended']), ['name' => 'Hidden toy']);

    actingAsBuyer($buyer);

    $quote = $this->postJson('/api/buyer/checkout/quote', ['items' => [
        ['product_id' => $collar->id, 'variant_id' => $red->id, 'quantity' => 2],
        ['product_id' => $collar->id, 'variant_id' => $blue->id, 'quantity' => 1],
        ['product_id' => $collar->id, 'quantity' => 1],
        ['product_id' => $bed->id, 'quantity' => 4],
        ['product_id' => $hidden->id, 'quantity' => 1],
        ['product_id' => (string) Str::uuid(), 'quantity' => 1],
    ]])->assertOk()->json('data');

    $problems = collect($quote['sellers'])->flatMap(fn ($s) => $s['items'])->mapWithKeys(fn ($i) => [$i['key'] => $i['problem']]);

    expect($problems["{$collar->id}-{$red->id}"])->toBeNull();
    expect($problems["{$collar->id}-{$blue->id}"])->toBe('This option is no longer available.');
    expect($problems["{$collar->id}-simple"])->toBe('Choose an option for this product in your cart.');
    expect($problems["{$bed->id}-simple"])->toBe('Only 1 left — lower the quantity in your cart.');
    expect($problems["{$hidden->id}-simple"])->toBe('This product is no longer available.');
    expect($quote['totals']['subtotal'])->toEqual(190);
    expect($quote['can_place'])->toBeFalse();
});

it('places one order per store with each store\'s shipping and the structured address', function () {
    $buyer = makeBuyer();
    $mugs = namedSeller('Mug House');
    $pets = namedSeller('Pet Corner');
    $mug = makeProduct($mugs, ['price' => 150, 'stock' => 5]);
    $bowl = makeProduct($pets, ['price' => 80, 'stock' => 5]);

    actingAsBuyer($buyer);

    $orders = $this->postJson('/api/buyer/checkout', [
        'items' => [['product_id' => $mug->id, 'quantity' => 2], ['product_id' => $bowl->id, 'quantity' => 1]],
        'shipping_methods' => [$pets->id => 'express'],
        'delivery_address' => quoteAddress(),
        'payment_method' => 'cod',
        'expected_total' => 560,
    ])->assertCreated()->json('data');

    expect(collect($orders)->pluck('store_name')->sort()->values()->all())->toBe(['Mug House', 'Pet Corner']);
    expect(collect($orders)->sum('total'))->toEqual(560);
    expect(collect($orders)->firstWhere('store_name', 'Pet Corner')['shipping'])->toBe(['method' => 'express', 'name' => 'Express Delivery', 'eta' => '1-2 days']);

    $order = Order::where('seller_id', $mugs->id)->sole();
    expect($order->shipping_municipality_name)->toBe('Quezon City');
    expect($order->shipping_province_name)->toBe('Metro Manila');
    expect($mug->fresh()->stock)->toBe(3);
});

it('orders nothing when the total changed since the quote, and returns the new quote', function () {
    $buyer = makeBuyer();
    $mug = makeProduct(makeSeller(), ['price' => 150]);

    actingAsBuyer($buyer);

    $payload = [
        'items' => [['product_id' => $mug->id, 'quantity' => 1]],
        'delivery_address' => quoteAddress(),
        'payment_method' => 'cod',
        'expected_total' => 210,
    ];

    $mug->update(['price' => 175]);

    $this->postJson('/api/buyer/checkout', $payload)
        ->assertStatus(409)
        ->assertJsonPath('code', 'quote_changed')
        ->assertJsonPath('quote.totals.total', 235)
        ->assertJsonPath('quote.sellers.0.items.0.unit_price', 175);

    expect(Order::count())->toBe(0);
    expect($mug->fresh()->stock)->toBe(10);

    $this->postJson('/api/buyer/checkout', [...$payload, 'expected_total' => 235])->assertCreated();
});

it('refuses a line that can\'t be bought, naming it', function () {
    $buyer = makeBuyer();
    $mug = makeProduct(makeSeller(), ['name' => 'Mug', 'stock' => 1]);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', [
        'items' => [['product_id' => $mug->id, 'quantity' => 2]],
        'delivery_address' => quoteAddress(),
        'payment_method' => 'cod',
    ])->assertUnprocessable()->assertJsonPath('message', '"Mug": Only 1 left — lower the quantity in your cart.');

    expect(Order::count())->toBe(0);
});

it('creates the orders once for a repeated submit with the same key', function () {
    $buyer = makeBuyer();
    $mug = makeProduct(makeSeller(), ['stock' => 5]);

    actingAsBuyer($buyer);

    $payload = [
        'items' => [['product_id' => $mug->id, 'quantity' => 1]],
        'delivery_address' => quoteAddress(),
        'payment_method' => 'cod',
        'idempotency_key' => (string) Str::uuid(),
    ];

    $first = $this->postJson('/api/buyer/checkout', $payload)->assertCreated()->json('data.0.id');
    $second = $this->postJson('/api/buyer/checkout', $payload)->assertOk()->json('data.0.id');

    expect($second)->toBe($first);
    expect(Order::count())->toBe(1);
    expect($mug->fresh()->stock)->toBe(4);

    // A new attempt (new key) is a new order.
    $this->postJson('/api/buyer/checkout', [...$payload, 'idempotency_key' => (string) Str::uuid()])->assertCreated();
    expect(Order::count())->toBe(2);
});

it('validates the delivery address', function () {
    $buyer = makeBuyer();
    $mug = makeProduct(makeSeller());

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', [
        'items' => [['product_id' => $mug->id, 'quantity' => 1]],
        'delivery_address' => ['recipient_name' => '', 'contact_number' => '12345', 'address' => ''],
        'payment_method' => 'cod',
    ])->assertUnprocessable()->assertJsonValidationErrors(['delivery_address.recipient_name', 'delivery_address.contact_number', 'delivery_address.address']);
});
