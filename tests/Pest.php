<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\Profile;
use App\Models\SellerDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Buyer API test helpers
|--------------------------------------------------------------------------
|
| The buyer API sits behind 'supabase.auth' (verifies a Supabase bearer
| token against GoTrue) + 'buyer' (approved, active buyer). These helpers
| stand up a buyer/seller profile and fake the GoTrue lookup so a test
| request authenticates as that buyer.
|
*/

function makeBuyer(array $overrides = []): Profile
{
    return Profile::create(array_merge([
        'id' => (string) Str::uuid(),
        'role' => 'buyer',
        'status' => 'approved',
        'account_status' => 'active',
        'first_name' => 'Test',
        'last_name' => 'Buyer',
        'email' => 'buyer_'.Str::random(8).'@example.test',
    ], $overrides));
}

function makeSeller(array $overrides = []): Profile
{
    $seller = Profile::create(array_merge([
        'id' => (string) Str::uuid(),
        'role' => 'seller',
        'status' => 'approved',
        'account_status' => 'active',
        'first_name' => 'Test',
        'last_name' => 'Seller',
        'email' => 'seller_'.Str::random(8).'@example.test',
    ], $overrides));

    SellerDetail::create([
        'profile_id' => $seller->id,
        'business_name' => 'Test Storefront',
        'line_of_business' => 'Electronics and Gadgets',
    ]);

    return $seller;
}

function makeProduct(Profile $seller, array $overrides = []): Product
{
    return Product::create(array_merge([
        'seller_id' => $seller->id,
        'name' => 'Test Product',
        'category' => 'Electronics and Gadgets',
        'price' => 100,
        'stock' => 10,
        'status' => 'active',
        'images' => [],
    ], $overrides));
}

/**
 * @return array{0: Order, 1: OrderItem}
 */
function makeOrder(Profile $buyer, Profile $seller, array $overrides = []): array
{
    $order = Order::create(array_merge([
        'order_number' => 'SN-'.random_int(10000, 99999),
        'seller_id' => $seller->id,
        'buyer_profile_id' => $buyer->id,
        'recipient_name' => 'Test Buyer',
        'status' => 'New',
        'payment_status' => 'Unpaid',
        'subtotal' => 100,
        'shipping_fee' => 60,
        'total' => 160,
        'placed_at' => now(),
    ], $overrides));

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => null,
        'product_name' => 'Test Product',
        'category' => 'Electronics and Gadgets',
        'unit_price' => 100,
        'quantity' => 1,
        'subtotal' => 100,
    ]);

    OrderStatusHistory::create([
        'order_id' => $order->id,
        'status' => $order->status,
        'note' => 'Order placed by buyer.',
        'changed_by' => $buyer->id,
    ]);

    return [$order, $item];
}

function actingAsBuyer(Profile $buyer): void
{
    $token = 'test-token-'.$buyer->id;

    config([
        'services.supabase.url' => 'https://unit-test.supabase.co',
        'services.supabase.anon_key' => 'test-anon-key',
    ]);

    Http::fake([
        'https://unit-test.supabase.co/auth/v1/user' => Http::response(['id' => $buyer->id], 200),
    ]);

    test()->withHeader('Authorization', 'Bearer '.$token);
}
