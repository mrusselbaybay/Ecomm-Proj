<?php

/*
|--------------------------------------------------------------------------
| Store page content, ratings, shipping and payment
|--------------------------------------------------------------------------
|
| What GET /api/stores/{id} tells a buyer beyond the products: the
| seller's own banner / description / return policy (next to the platform
| return rules), the product-review rating, and the shipping / payment
| terms, which must be exactly what checkout charges and accepts.
|
*/

use App\Models\Order;
use App\Models\Profile;
use App\Models\Review;
use App\Models\SellerDetail;
use App\Support\CheckoutOptions;
use App\Support\PlatformReturnPolicy;
use App\Support\StoreProfile;
use Illuminate\Support\Str;

function storeReview(Profile $seller, int $rating, ?string $productId): void
{
    Review::forceCreate([
        'id' => (string) Str::uuid(),
        'seller_id' => $seller->id,
        'product_id' => $productId,
        'rating' => $rating,
    ]);
}

it('returns the seller supplied banner, description and return policy', function () {
    config(['services.supabase.url' => 'https://demo.supabase.co']);

    $seller = makeSeller();
    SellerDetail::where('profile_id', $seller->id)->update([
        'banner_path' => "{$seller->id}/banner.webp",
        'description' => "  Handmade <b>leather</b> goods.\r\n\r\n\r\n\r\nShips from Cebu.  ",
        'return_policy' => 'Unused items within 7 days of delivery.',
    ]);

    $this->getJson("/api/stores/{$seller->id}")
        ->assertOk()
        ->assertJsonPath('data.banner', "https://demo.supabase.co/storage/v1/object/public/store-banners/{$seller->id}/banner.webp")
        ->assertJsonPath('data.description', "Handmade leather goods.\n\nShips from Cebu.")
        ->assertJsonPath('data.returnPolicy', 'Unused items within 7 days of delivery.')
        ->assertJsonPath('data.returnRules', PlatformReturnPolicy::rules());
});

it('falls back to nulls, keeping the platform return rules, when a seller supplied nothing', function () {
    $seller = makeSeller();

    $this->getJson("/api/stores/{$seller->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Test Storefront')
        ->assertJsonPath('data.banner', null)
        ->assertJsonPath('data.description', null)
        ->assertJsonPath('data.returnPolicy', null)
        ->assertJsonPath('data.returnRules', PlatformReturnPolicy::rules());
});

it('never exposes private profile fields on the public store', function () {
    $seller = makeSeller(['contact_no' => '09170000000']);

    $data = $this->getJson("/api/stores/{$seller->id}")->assertOk()->json('data');

    expect($data)->not->toHaveKeys(['email', 'contact_no', 'status', 'account_status', 'birthday', 'banner_path']);
});

it('averages individual product reviews, not per-product averages', function () {
    $seller = makeSeller();
    $loved = makeProduct($seller);
    $panned = makeProduct($seller);

    storeReview($seller, 5, $loved->id);
    storeReview($seller, 1, $panned->id);
    storeReview($seller, 1, $panned->id);
    storeReview($seller, 1, $panned->id);

    // A review whose product was deleted is no longer a product review.
    storeReview($seller, 5, null);

    // Per-product averages would give (5 + 1) / 2 = 3.0.
    $this->getJson("/api/stores/{$seller->id}")
        ->assertOk()
        ->assertJsonPath('data.rating', 2)
        ->assertJsonPath('data.reviewCount', 4);

    $this->getJson('/api/stores')
        ->assertJsonPath('data.0.rating', 2)
        ->assertJsonPath('data.0.reviewCount', 4);
});

it('reports no rating rather than zero stars for a store without reviews', function () {
    $seller = makeSeller();
    makeProduct($seller);

    $this->getJson("/api/stores/{$seller->id}")
        ->assertOk()
        ->assertJsonPath('data.rating', null)
        ->assertJsonPath('data.reviewCount', 0);
});

it('serves the same shipping and payment terms checkout uses', function () {
    $seller = makeSeller();

    $expected = [
        'shipping' => [
            ['id' => 'standard', 'name' => 'Standard Delivery', 'shortName' => 'Standard', 'fee' => 60, 'eta' => '3-5 days'],
            ['id' => 'express', 'name' => 'Express Delivery', 'shortName' => 'Express', 'fee' => 120, 'eta' => '1-2 days'],
        ],
        'shippingChargedPer' => 'seller_order',
        'payment' => [
            ['id' => 'cod', 'name' => 'Cash on Delivery', 'description' => 'Pay the courier in cash when your parcel arrives.'],
        ],
    ];

    $this->getJson('/api/checkout/options')->assertOk()->assertExactJson(['data' => $expected]);
    $this->getJson("/api/stores/{$seller->id}")->assertOk()->assertJsonPath('data.fulfillment', $expected);
});

it('charges each advertised shipping fee once per store order at checkout', function (string $method) {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $first = makeProduct($seller, ['price' => 100]);
    $second = makeProduct($seller, ['price' => 50]);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', [
        'items' => [
            ['product_id' => $first->id, 'quantity' => 1],
            ['product_id' => $second->id, 'quantity' => 2],
        ],
        'delivery_address' => [
            'recipient_name' => 'Test Buyer',
            'contact_number' => '09171234567',
            'address' => '12 Mabini St, Quezon City, Metro Manila',
        ],
        'shipping_method' => $method,
        'payment_method' => 'cod',
    ])->assertCreated();

    $order = Order::where('buyer_profile_id', $buyer->id)->sole();
    $advertised = collect($this->getJson('/api/checkout/options')->json('data.shipping'))->firstWhere('id', $method);

    expect((float) $order->shipping_fee)->toBe((float) $advertised['fee']);
})->with(['standard', 'express']);

it('accepts exactly the advertised payment methods at checkout', function () {
    $advertised = collect($this->getJson('/api/checkout/options')->json('data.payment'))->pluck('id')->all();

    expect($advertised)->toBe(CheckoutOptions::paymentMethodIds());

    $buyer = makeBuyer();
    $product = makeProduct(makeSeller());

    actingAsBuyer($buyer);

    foreach (['gcash', 'maya', 'card'] as $notOffered) {
        expect($advertised)->not->toContain($notOffered);

        $this->postJson('/api/buyer/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'delivery_address' => ['recipient_name' => 'Test Buyer', 'address' => '12 Mabini St, Quezon City'],
            'shipping_method' => 'standard',
            'payment_method' => $notOffered,
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    }
});

it('rejects a shipping method checkout does not offer', function () {
    $buyer = makeBuyer();
    $product = makeProduct(makeSeller());

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'delivery_address' => ['recipient_name' => 'Test Buyer', 'address' => '12 Mabini St, Quezon City'],
        'shipping_method' => 'same-day',
        'payment_method' => 'cod',
    ])->assertUnprocessable()->assertJsonValidationErrors('shipping_method');
});

it('treats store text as plain text and keeps banner paths inside the owner folder', function () {
    $sellerId = (string) Str::uuid();

    expect(StoreProfile::plainText('<script>alert(1)</script>Fresh <i>daily</i>'))->toBe('alert(1)Fresh daily');
    expect(StoreProfile::plainText("   \n  "))->toBeNull();

    expect(StoreProfile::ownsBannerPath($sellerId, "{$sellerId}/a.webp"))->toBeTrue();
    expect(StoreProfile::ownsBannerPath($sellerId, Str::uuid().'/a.webp'))->toBeFalse();
    expect(StoreProfile::ownsBannerPath($sellerId, "{$sellerId}/../other/a.webp"))->toBeFalse();

    expect(StoreProfile::bannerRatioAllowed(1600, 400))->toBeTrue();
    expect(StoreProfile::bannerRatioAllowed(1200, 1200))->toBeFalse();
    expect(StoreProfile::bannerRatioAllowed(4000, 500))->toBeFalse();
});

it('lists a store product reviews newest first with an unfiltered summary', function () {
    $seller = makeSeller();
    $other = makeSeller();
    $mug = makeProduct($seller, ['name' => 'Clay Mug']);
    $bowl = makeProduct($seller, ['name' => 'Clay Bowl']);
    $buyer = makeBuyer(['first_name' => 'Maria', 'last_name' => 'Santos']);

    Review::forceCreate(['id' => (string) Str::uuid(), 'seller_id' => $seller->id, 'product_id' => $mug->id, 'buyer_id' => $buyer->id, 'rating' => 5, 'comment' => 'Lovely glaze', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
    Review::forceCreate(['id' => (string) Str::uuid(), 'seller_id' => $seller->id, 'product_id' => $bowl->id, 'rating' => 2, 'comment' => 'Chipped', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
    // Not this store's, and not a product review: neither is listed.
    Review::forceCreate(['id' => (string) Str::uuid(), 'seller_id' => $other->id, 'product_id' => makeProduct($other)->id, 'rating' => 1]);
    Review::forceCreate(['id' => (string) Str::uuid(), 'seller_id' => $seller->id, 'product_id' => null, 'rating' => 1]);

    $this->getJson("/api/stores/{$seller->id}/reviews")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.comment', 'Chipped')
        ->assertJsonPath('data.0.product.name', 'Clay Bowl')
        ->assertJsonPath('data.1.author', 'Maria S.')
        ->assertJsonPath('summary.total', 2)
        ->assertJsonPath('summary.average', 3.5)
        ->assertJsonPath('summary.breakdown.5', 1)
        ->assertJsonPath('summary.breakdown.2', 1);

    $this->getJson("/api/stores/{$seller->id}/reviews?rating=5")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product.name', 'Clay Mug')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('summary.total', 2);

    $data = $this->getJson("/api/stores/{$seller->id}/reviews")->json('data.1');
    expect($data)->not->toHaveKeys(['buyer_id', 'email']);
});

it('paginates store reviews and hides closed stores', function () {
    $seller = makeSeller();
    $product = makeProduct($seller);

    foreach (range(1, 3) as $i) {
        Review::forceCreate(['id' => (string) Str::uuid(), 'seller_id' => $seller->id, 'product_id' => $product->id, 'rating' => 4]);
    }

    $this->getJson("/api/stores/{$seller->id}/reviews?per_page=2&page=2")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.last_page', 2);

    $closed = makeSeller(['account_status' => 'suspended']);
    $this->getJson("/api/stores/{$closed->id}/reviews")->assertNotFound();
    $this->getJson('/api/stores/not-a-uuid/reviews')->assertNotFound();
});

it('reports completed orders, items sold and distinct buyers from completed sales only', function () {
    $seller = makeSeller();
    $product = makeProduct($seller);
    $ana = makeBuyer();
    $ben = makeBuyer();
    $cara = makeBuyer();

    $sale = function (Profile $buyer, int $quantity, string $status, string $payment = 'Unpaid') use ($seller, $product): void {
        [, $item] = makeOrder($buyer, $seller, ['status' => $status, 'payment_status' => $payment]);
        $item->update(['product_id' => $product->id, 'quantity' => $quantity]);
    };

    $sale($ana, 2, 'Delivered');            // counts
    $sale($ana, 1, 'Delivered', 'Paid');    // counts, same buyer
    $sale($ben, 3, 'Delivered');            // counts
    $sale($cara, 5, 'Delivered', 'Refunded'); // refunded: not a sale
    $sale($cara, 4, 'Cancelled');
    $sale($cara, 6, 'In Transit');           // not complete yet

    $this->getJson("/api/stores/{$seller->id}")
        ->assertOk()
        ->assertJsonPath('data.sales.completedOrders', 3)
        ->assertJsonPath('data.sales.itemsSold', 6)
        ->assertJsonPath('data.sales.buyerCount', 2);

    // The product's own "sold" count follows the same rule.
    $this->getJson("/api/products?seller_id={$seller->id}")
        ->assertOk()
        ->assertJsonPath('data.0.soldCount', 6);
});

it('reports zero sales for a store without completed orders', function () {
    $seller = makeSeller();

    $this->getJson("/api/stores/{$seller->id}")
        ->assertOk()
        ->assertJsonPath('data.sales', ['completedOrders' => 0, 'itemsSold' => 0, 'buyerCount' => 0]);
});

it('can leave the current product out of the shop review strip', function () {
    $seller = makeSeller();
    $current = makeProduct($seller, ['name' => 'Current']);
    $other = makeProduct($seller, ['name' => 'Other']);

    Review::forceCreate(['id' => (string) Str::uuid(), 'seller_id' => $seller->id, 'product_id' => $current->id, 'rating' => 5]);
    Review::forceCreate(['id' => (string) Str::uuid(), 'seller_id' => $seller->id, 'product_id' => $other->id, 'rating' => 4]);

    $this->getJson("/api/stores/{$seller->id}/reviews?exclude_product={$current->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product.name', 'Other')
        // The summary still describes the whole shop.
        ->assertJsonPath('summary.total', 2);
});
