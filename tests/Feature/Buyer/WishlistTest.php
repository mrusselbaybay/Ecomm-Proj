<?php

use App\Models\WishlistItem;

it('adds, lists and removes wishlist items scoped to the buyer', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $product = makeProduct($seller);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/wishlist', ['product_id' => $product->id])->assertCreated();

    $this->getJson('/api/buyer/wishlist')
        ->assertOk()
        ->assertJsonPath('data.0', $product->id);

    $this->deleteJson("/api/buyer/wishlist/{$product->id}")->assertOk();
    $this->getJson('/api/buyer/wishlist')->assertOk()->assertJsonCount(0, 'data');
});

it('is idempotent on repeated adds (unique per buyer + product)', function () {
    $buyer = makeBuyer();
    $product = makeProduct(makeSeller());

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/wishlist', ['product_id' => $product->id])->assertCreated();
    $this->postJson('/api/buyer/wishlist', ['product_id' => $product->id])->assertCreated();

    expect(WishlistItem::where('buyer_profile_id', $buyer->id)->count())->toBe(1);
});

it('refuses to wishlist a non-active product', function () {
    $buyer = makeBuyer();
    $product = makeProduct(makeSeller(), ['status' => 'archived']);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/wishlist', ['product_id' => $product->id])->assertStatus(422);
    expect(WishlistItem::count())->toBe(0);
});

it("never returns another buyer's wishlist", function () {
    $me = makeBuyer();
    $other = makeBuyer();
    $product = makeProduct(makeSeller());

    WishlistItem::create(['buyer_profile_id' => $other->id, 'product_id' => $product->id]);

    actingAsBuyer($me);

    $this->getJson('/api/buyer/wishlist')->assertOk()->assertJsonCount(0, 'data');
});
