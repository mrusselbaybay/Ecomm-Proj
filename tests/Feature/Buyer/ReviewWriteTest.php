<?php

/*
|--------------------------------------------------------------------------
| Writing and editing a review from a completed order
|--------------------------------------------------------------------------
*/

use App\Models\Review;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

function fakeReviewStorage(): void
{
    Http::fake([
        'https://unit-test.supabase.co/storage/v1/bucket/*' => Http::response(['name' => 'review-photos', 'public' => true], 200),
        'https://unit-test.supabase.co/storage/v1/object/review-photos' => Http::response([], 200),
        'https://unit-test.supabase.co/storage/v1/object/review-photos/*' => Http::response(['Key' => 'ok'], 200),
    ]);
}

function deliveredItem(array $order = []): array
{
    $buyer = makeBuyer();
    $seller = makeSeller();
    $product = makeProduct($seller, ['name' => 'Ceramic mug']);
    [$order, $item] = makeOrder($buyer, $seller, ['status' => 'Delivered', ...$order]);
    $item->update(['product_id' => $product->id, 'product_name' => 'Ceramic mug']);

    return [$buyer, $item, $product];
}

it('reviews a delivered item once, tied to the item, product and buyer', function () {
    [$buyer, $item, $product] = deliveredItem();
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/reviews', ['order_item_id' => $item->id, 'rating' => 4, 'comment' => 'Sturdy.'])
        ->assertCreated()
        ->assertJsonPath('data.rating', 4)
        ->assertJsonPath('data.orderItemId', $item->id)
        ->assertJsonPath('data.images', []);

    $review = Review::sole();
    expect($review->product_id)->toBe($product->id);
    expect($review->buyer_id)->toBe($buyer->id);
    expect($review->order_item_id)->toBe($item->id);

    $this->postJson('/api/buyer/reviews', ['order_item_id' => $item->id, 'rating' => 5])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['order_item_id']);

    $this->getJson('/api/buyer/orders')->assertOk()
        ->assertJsonPath('data.0.items.0.review.rating', 4)
        ->assertJsonPath('data.0.items.0.review.isEdited', false);
});

it('refuses reviews for undelivered orders and other buyers\' items', function () {
    [$buyer, $item] = deliveredItem(['status' => 'In Transit']);
    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/reviews', ['order_item_id' => $item->id, 'rating' => 5])->assertStatus(422);

    [, $otherItem] = deliveredItem();
    $this->postJson('/api/buyer/reviews', ['order_item_id' => $otherItem->id, 'rating' => 5])->assertStatus(422);

    expect(Review::count())->toBe(0);
});

it('uploads review photos to storage and saves only their URLs', function () {
    fakeReviewStorage();
    [$buyer, $item] = deliveredItem();
    actingAsBuyer($buyer);
    fakeReviewStorage();

    $response = $this->post('/api/buyer/reviews', [
        'order_item_id' => $item->id,
        'rating' => 5,
        'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png')],
    ], ['Accept' => 'application/json'])->assertCreated();

    $images = $response->json('data.images');
    expect($images)->toHaveCount(2);
    expect($images[0])->toStartWith('https://unit-test.supabase.co/storage/v1/object/public/review-photos/'.$buyer->id.'/');
    expect(json_encode(Review::sole()->images))->not->toContain('base64');
});

it('limits photos and file types', function () {
    fakeReviewStorage();
    [$buyer, $item] = deliveredItem();
    actingAsBuyer($buyer);

    $this->post('/api/buyer/reviews', [
        'order_item_id' => $item->id,
        'rating' => 5,
        'images' => array_map(fn ($n) => UploadedFile::fake()->image("p{$n}.jpg"), range(1, 4)),
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['images']);

    $this->post('/api/buyer/reviews', [
        'order_item_id' => $item->id,
        'rating' => 5,
        'images' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['images.0']);
});

it('edits a review: rating, text, keeping and adding photos', function () {
    [$buyer, $item] = deliveredItem();
    actingAsBuyer($buyer);
    fakeReviewStorage();

    $keep = 'https://unit-test.supabase.co/storage/v1/object/public/review-photos/'.$buyer->id.'/keep.jpg';
    $drop = 'https://unit-test.supabase.co/storage/v1/object/public/review-photos/'.$buyer->id.'/drop.jpg';
    $review = Review::create([
        'product_id' => $item->product_id, 'seller_id' => $item->order->seller_id, 'buyer_id' => $buyer->id,
        'order_item_id' => $item->id, 'product_name' => 'Ceramic mug', 'rating' => 3, 'comment' => 'Ok',
        'images' => [$keep, $drop],
    ]);
    $review->forceFill(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()])->save();

    $images = $this->post("/api/buyer/reviews/{$review->id}", [
        '_method' => 'PUT',
        'rating' => 5,
        'comment' => 'Better than I thought.',
        'keep_images' => [$keep],
        'images' => [UploadedFile::fake()->image('new.webp')],
    ], ['Accept' => 'application/json'])->assertOk()->json('data.images');

    expect($images)->toHaveCount(2);
    expect($images[0])->toBe($keep);
    expect($review->fresh()->rating)->toBe(5);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->body(), 'drop.jpg'));

    $this->getJson('/api/buyer/orders')->assertJsonPath('data.0.items.0.review.isEdited', true);
});

it('only lets a buyer edit their own review', function () {
    [$buyer, $item] = deliveredItem();
    $review = Review::create([
        'product_id' => $item->product_id, 'seller_id' => $item->order->seller_id, 'buyer_id' => $buyer->id,
        'order_item_id' => $item->id, 'product_name' => 'Ceramic mug', 'rating' => 3,
    ]);

    actingAsBuyer(makeBuyer());

    $this->putJson("/api/buyer/reviews/{$review->id}", ['rating' => 1])->assertNotFound();
    expect($review->fresh()->rating)->toBe(3);
});

it('removes every photo when keep_images is sent empty', function () {
    [$buyer, $item] = deliveredItem();
    actingAsBuyer($buyer);
    fakeReviewStorage();

    $photo = 'https://unit-test.supabase.co/storage/v1/object/public/review-photos/'.$buyer->id.'/old.jpg';
    $review = Review::create([
        'product_id' => $item->product_id, 'seller_id' => $item->order->seller_id, 'buyer_id' => $buyer->id,
        'order_item_id' => $item->id, 'product_name' => 'Ceramic mug', 'rating' => 3, 'images' => [$photo],
    ]);

    $this->post("/api/buyer/reviews/{$review->id}", ['_method' => 'PUT', 'rating' => 4, 'keep_images' => ''], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.images', []);

    // A plain JSON edit (no keep_images) leaves photos alone.
    $review->fresh()->update(['images' => [$photo]]);
    $this->putJson("/api/buyer/reviews/{$review->id}", ['rating' => 5])->assertOk()->assertJsonPath('data.images', [$photo]);
});
