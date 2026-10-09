<?php

/*
|--------------------------------------------------------------------------
| Following stores
|--------------------------------------------------------------------------
|
| /api/buyer/follows/* (Buyer\StoreFollowController) and the follower
| fields on GET /api/stores/{id}.
|
*/

use App\Models\Profile;
use App\Models\SellerDetail;
use App\Models\StoreFollow;

function followableStore(string $name = 'Volt Electronics', array $overrides = []): Profile
{
    $seller = makeSeller($overrides);
    SellerDetail::where('profile_id', $seller->id)->update(['business_name' => $name]);

    return $seller;
}

it('follows and unfollows a store', function () {
    $buyer = makeBuyer();
    $store = followableStore();

    actingAsBuyer($buyer);

    $this->postJson("/api/buyer/follows/{$store->id}")
        ->assertCreated()
        ->assertJsonPath('data.isFollowing', true)
        ->assertJsonPath('data.followerCount', 1);

    $this->getJson("/api/buyer/follows/{$store->id}")
        ->assertOk()
        ->assertJsonPath('data.isFollowing', true);

    $this->deleteJson("/api/buyer/follows/{$store->id}")
        ->assertOk()
        ->assertJsonPath('data.isFollowing', false)
        ->assertJsonPath('data.followerCount', 0);

    expect(StoreFollow::count())->toBe(0);
});

it('keeps a single follow when the same store is followed twice', function () {
    $buyer = makeBuyer();
    $store = followableStore();

    actingAsBuyer($buyer);

    $this->postJson("/api/buyer/follows/{$store->id}")->assertCreated();
    $this->postJson("/api/buyer/follows/{$store->id}")
        ->assertOk()
        ->assertJsonPath('data.followerCount', 1);

    expect(StoreFollow::where('buyer_profile_id', $buyer->id)->count())->toBe(1);
});

it('treats unfollowing a store that is not followed as a no-op', function () {
    $buyer = makeBuyer();
    $store = followableStore();

    actingAsBuyer($buyer);

    $this->deleteJson("/api/buyer/follows/{$store->id}")
        ->assertOk()
        ->assertJsonPath('data.isFollowing', false);
});

it('requires a signed-in buyer', function () {
    $store = followableStore();

    $this->postJson("/api/buyer/follows/{$store->id}")->assertUnauthorized();
    $this->getJson('/api/buyer/follows')->assertUnauthorized();

    expect(StoreFollow::count())->toBe(0);
});

it('does not let a seller follow stores, including their own', function () {
    $seller = followableStore();

    actingAsBuyer($seller);

    $this->postJson("/api/buyer/follows/{$seller->id}")->assertForbidden();

    expect(StoreFollow::count())->toBe(0);
});

it('rejects following a buyer profile id as if it were a store', function () {
    $buyer = makeBuyer();

    actingAsBuyer($buyer);

    // A buyer's own id is not a store, so "following yourself" is a 404.
    $this->postJson("/api/buyer/follows/{$buyer->id}")->assertNotFound();
});

it('only lets buyers follow visible stores', function () {
    $buyer = makeBuyer();
    $suspended = followableStore('Closed Shop', ['account_status' => 'suspended']);

    actingAsBuyer($buyer);

    $this->postJson("/api/buyer/follows/{$suspended->id}")->assertNotFound();
    $this->postJson('/api/buyer/follows/not-a-uuid')->assertNotFound();

    expect(StoreFollow::count())->toBe(0);
});

it('lists followed stores newest first with pagination, hiding closed stores', function () {
    $buyer = makeBuyer();
    $first = followableStore('First Store');
    $second = followableStore('Second Store');
    $closed = followableStore('Closed Store');

    StoreFollow::forceCreate(['buyer_profile_id' => $buyer->id, 'seller_id' => $first->id, 'created_at' => now()->subDays(2)]);
    StoreFollow::forceCreate(['buyer_profile_id' => $buyer->id, 'seller_id' => $second->id, 'created_at' => now()->subDay()]);
    StoreFollow::forceCreate(['buyer_profile_id' => $buyer->id, 'seller_id' => $closed->id, 'created_at' => now()]);
    $closed->update(['account_status' => 'suspended']);

    // Someone else's follows never show up.
    StoreFollow::create(['buyer_profile_id' => makeBuyer()->id, 'seller_id' => $first->id]);

    actingAsBuyer($buyer);

    $this->getJson('/api/buyer/follows?per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Second Store')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.last_page', 2);

    $this->getJson('/api/buyer/follows?per_page=1&page=2')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'First Store');
});

it('returns follower count and the viewer follow status with store details', function () {
    $buyer = makeBuyer();
    $store = followableStore();

    StoreFollow::create(['buyer_profile_id' => $buyer->id, 'seller_id' => $store->id]);
    StoreFollow::create(['buyer_profile_id' => makeBuyer()->id, 'seller_id' => $store->id]);

    // Guests get the count but no follow status.
    $this->getJson("/api/stores/{$store->id}")
        ->assertOk()
        ->assertJsonPath('data.followerCount', 2)
        ->assertJsonPath('data.isFollowing', null);

    actingAsBuyer($buyer);

    $this->getJson("/api/stores/{$store->id}")
        ->assertOk()
        ->assertJsonPath('data.followerCount', 2)
        ->assertJsonPath('data.isFollowing', true);
});

it('treats an invalid token on the public store endpoint as a guest', function () {
    $store = followableStore();

    $this->withHeader('Authorization', 'Bearer not-a-real-token')
        ->getJson("/api/stores/{$store->id}")
        ->assertOk()
        ->assertJsonPath('data.isFollowing', null);
});
