<?php

/*
|--------------------------------------------------------------------------
| One review, the same numbers everywhere
|--------------------------------------------------------------------------
|
| A buyer's review write returns the product's new rating and count, and
| every public surface (cards, product page, review summary, store) counts
| the same eligible rows (Review::scopeEligible).
|
*/

use App\Models\Review;

function reviewableItem(): array
{
    $buyer = makeBuyer();
    $seller = makeSeller();
    $product = makeProduct($seller, ['name' => 'Enamel mug']);
    [, $item] = makeOrder($buyer, $seller, ['status' => 'Delivered']);
    $item->update(['product_id' => $product->id, 'product_name' => 'Enamel mug']);

    return [$buyer, $item, $product, $seller];
}

/**
 * The rating and count of one product as each public surface reports it.
 *
 * @return array<string, array{0: float|int|null, 1: int}>
 */
function ratingsEverywhere($test, string $productId, string $sellerId): array
{
    $card = $test->getJson("/api/products?ids={$productId}&fields=card")->json('data.0');
    $list = $test->getJson("/api/products?seller_id={$sellerId}")->json('data.0');
    $show = $test->getJson("/api/products/{$productId}")->json('data');
    $summary = $test->getJson("/api/products/{$productId}/reviews")->json('summary');
    $store = $test->getJson("/api/stores/{$sellerId}")->json('data');

    return [
        'card' => [$card['rating'], $card['reviewCount']],
        'store listing' => [$list['rating'], $list['reviewCount']],
        'product page' => [$show['rating'], $show['reviewCount']],
        'review summary' => [$summary['average'], $summary['total']],
        'store' => [$store['rating'], $store['reviewCount']],
    ];
}

it('returns the new rating with a submitted review, and every page agrees', function () {
    [$buyer, $item, $product, $seller] = reviewableItem();

    foreach (ratingsEverywhere($this, $product->id, $seller->id) as $surface => $values) {
        expect($values)->toBe([null, 0], "{$surface} before any review");
    }

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/reviews', ['order_item_id' => $item->id, 'rating' => 4, 'comment' => 'Keeps tea hot.'])
        ->assertCreated()
        ->assertJsonPath('data.productStats.productId', $product->id)
        ->assertJsonPath('data.productStats.rating', 4)
        ->assertJsonPath('data.productStats.reviewCount', 1)
        ->assertJsonPath('data.isPublic', true)
        ->assertJsonPath('data.visibilityNote', null);

    foreach (ratingsEverywhere($this, $product->id, $seller->id) as $surface => [$rating, $count]) {
        expect((float) $rating)->toBe(4.0, $surface);
        expect($count)->toBe(1, $surface);
    }

    $this->getJson("/api/products/{$product->id}/reviews")
        ->assertJsonPath('data.0.comment', 'Keeps tea hot.')
        ->assertJsonPath('data.0.verifiedPurchase', true);
    $this->getJson('/api/buyer/reviews')->assertJsonPath('data.0.productId', $product->id);
    $this->getJson('/api/buyer/orders')->assertJsonPath('data.0.items.0.review.rating', 4);
});

it('updates the numbers on edit and clears them on delete', function () {
    [$buyer, $item, $product, $seller] = reviewableItem();
    actingAsBuyer($buyer);

    $id = $this->postJson('/api/buyer/reviews', ['order_item_id' => $item->id, 'rating' => 2])->json('data.id');

    $this->putJson("/api/buyer/reviews/{$id}", ['rating' => 5, 'comment' => 'Grew on me.'])
        ->assertOk()
        ->assertJsonPath('data.productStats.rating', 5)
        ->assertJsonPath('data.productStats.reviewCount', 1);

    expect(ratingsEverywhere($this, $product->id, $seller->id)['card'])->toEqual([5, 1]);

    $this->deleteJson("/api/buyer/reviews/{$id}")
        ->assertOk()
        ->assertJsonPath('data.productStats.rating', null)
        ->assertJsonPath('data.productStats.reviewCount', 0);

    foreach (ratingsEverywhere($this, $product->id, $seller->id) as $surface => $values) {
        expect($values)->toBe([null, 0], "{$surface} after delete");
    }

    $this->getJson('/api/buyer/orders')->assertJsonPath('data.0.items.0.review', null);
});

it('averages several buyers the same way on cards, product page and store', function () {
    [$buyer, $item, $product, $seller] = reviewableItem();
    actingAsBuyer($buyer);
    $this->postJson('/api/buyer/reviews', ['order_item_id' => $item->id, 'rating' => 5])->assertCreated();

    $other = makeBuyer();
    [, $otherItem] = makeOrder($other, $seller, ['status' => 'Delivered']);
    $otherItem->update(['product_id' => $product->id]);
    actingAsBuyer($other);
    $this->postJson('/api/buyer/reviews', ['order_item_id' => $otherItem->id, 'rating' => 4])
        ->assertJsonPath('data.productStats.rating', 4.5)
        ->assertJsonPath('data.productStats.reviewCount', 2);

    foreach (ratingsEverywhere($this, $product->id, $seller->id) as $surface => [$rating, $count]) {
        expect((float) $rating)->toBe(4.5, $surface);
        expect($count)->toBe(2, $surface);
    }
});

it('lets only the author edit or delete a review', function () {
    [$buyer, $item] = reviewableItem();
    actingAsBuyer($buyer);
    $id = $this->postJson('/api/buyer/reviews', ['order_item_id' => $item->id, 'rating' => 3])->json('data.id');

    actingAsBuyer(makeBuyer());
    $this->putJson("/api/buyer/reviews/{$id}", ['rating' => 1])->assertNotFound();
    $this->deleteJson("/api/buyer/reviews/{$id}")->assertNotFound();

    expect(Review::sole()->rating)->toBe(3);
});

it('tells the author why a review is not public, and counts it nowhere', function () {
    [$buyer, $item, $product, $seller] = reviewableItem();
    actingAsBuyer($buyer);
    $this->postJson('/api/buyer/reviews', ['order_item_id' => $item->id, 'rating' => 5])->assertCreated();

    $product->update(['status' => 'inactive']);

    $this->getJson('/api/buyer/reviews')
        ->assertJsonPath('data.0.isPublic', false)
        ->assertJsonPath('data.0.visibilityNote', 'This product isn’t listed right now, so its reviews are hidden until it’s back.');
    $this->getJson("/api/products/{$product->id}/reviews")->assertNotFound();

    // A review whose product was deleted keeps its row but leaves every rating.
    Review::sole()->update(['product_id' => null]);
    $this->getJson('/api/buyer/reviews')->assertJsonPath('data.0.isPublic', false);
    $this->getJson("/api/stores/{$seller->id}")->assertJsonPath('data.reviewCount', 0)->assertJsonPath('data.rating', null);
});

it('counts only reviews that really have photos as photo reviews', function () {
    [$buyer, $item, $product, $seller] = reviewableItem();
    actingAsBuyer($buyer);
    // Saved without photos: the row holds an empty list, not NULL.
    $this->postJson('/api/buyer/reviews', ['order_item_id' => $item->id, 'rating' => 5])->assertCreated();
    expect(Review::sole()->getRawOriginal('images'))->toBe('[]');

    $other = makeBuyer();
    [, $otherItem] = makeOrder($other, $seller, ['status' => 'Delivered']);
    $otherItem->update(['product_id' => $product->id]);
    Review::create([
        'product_id' => $product->id, 'seller_id' => $seller->id, 'buyer_id' => $other->id,
        'order_item_id' => $otherItem->id, 'rating' => 4, 'images' => ['https://example.test/a.jpg'],
    ]);

    $this->getJson("/api/products/{$product->id}/reviews")
        ->assertJsonPath('summary.total', 2)
        ->assertJsonPath('summary.with_images', 1);
    $this->getJson("/api/products/{$product->id}/reviews?has_images=1")
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.rating', 4);
});
