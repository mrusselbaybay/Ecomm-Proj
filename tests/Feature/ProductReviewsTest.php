<?php

use App\Models\Review;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| GET /api/products/{id}/reviews
|--------------------------------------------------------------------------
|
| Public, paginated product review list added for the "read reviews before
| buying" drawer on the buyer cart. Covers the visibility rule, the
| summary/breakdown block, the rating filter, pagination, and the real
| verified-purchase signal (a non-null order_item_id).
|
*/

function makeReview(string $productId, string $sellerId, string $buyerId, array $overrides = []): Review
{
    return Review::create(array_merge([
        'id' => (string) Str::uuid(),
        'product_id' => $productId,
        'seller_id' => $sellerId,
        'buyer_id' => $buyerId,
        'rating' => 5,
        'comment' => 'Solid product.',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('returns a paginated review list with a rating summary', function () {
    $seller = makeSeller();
    $buyer = makeBuyer(['first_name' => 'Maria', 'last_name' => 'Santos']);
    $product = makeProduct($seller);

    makeReview($product->id, $seller->id, $buyer->id, ['rating' => 5, 'comment' => 'Great']);
    makeReview($product->id, $seller->id, $buyer->id, ['rating' => 3, 'comment' => 'Okay', 'order_item_id' => null]);

    $response = $this->getJson("/api/products/{$product->id}/reviews");

    $response->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('summary.total', 2)
        ->assertJsonPath('summary.breakdown.5', 1)
        ->assertJsonPath('summary.breakdown.3', 1)
        ->assertJsonPath('data.0.author', 'Maria S.');

    expect((float) $response->json('summary.average'))->toBe(4.0);
});

it('hides reviews for a product that is not active or not found', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    $archived = makeProduct($seller, ['status' => 'archived']);

    makeReview($archived->id, $seller->id, $buyer->id);

    $this->getJson("/api/products/{$archived->id}/reviews")->assertNotFound();
    $this->getJson('/api/products/'.Str::uuid().'/reviews')->assertNotFound();
});

it('filters by an exact star rating', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    $product = makeProduct($seller);

    makeReview($product->id, $seller->id, $buyer->id, ['rating' => 5]);
    makeReview($product->id, $seller->id, $buyer->id, ['rating' => 2]);

    $response = $this->getJson("/api/products/{$product->id}/reviews?rating=2");

    $response->assertOk()->assertJsonPath('meta.total', 1);
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.rating'))->toBe(2);
});

it('marks a review as a verified purchase only when it is tied to an order item', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    $product = makeProduct($seller);
    [, $orderItem] = makeOrder($buyer, $seller);

    makeReview($product->id, $seller->id, $buyer->id, ['order_item_id' => $orderItem->id, 'rating' => 5]);
    makeReview($product->id, $seller->id, $buyer->id, ['order_item_id' => null, 'rating' => 4]);

    $data = collect($this->getJson("/api/products/{$product->id}/reviews")->json('data'))
        ->keyBy('rating');

    expect($data[5]['verifiedPurchase'])->toBeTrue();
    expect($data[4]['verifiedPurchase'])->toBeFalse();
});

it('paginates with a small default page size', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    $product = makeProduct($seller);

    foreach (range(1, 7) as $i) {
        makeReview($product->id, $seller->id, $buyer->id, ['comment' => "Review {$i}"]);
    }

    $page1 = $this->getJson("/api/products/{$product->id}/reviews");
    $page1->assertOk()
        ->assertJsonPath('meta.total', 7)
        ->assertJsonPath('meta.last_page', 2);
    expect($page1->json('data'))->toHaveCount(5);

    $page2 = $this->getJson("/api/products/{$product->id}/reviews?page=2");
    expect($page2->json('data'))->toHaveCount(2);
});
