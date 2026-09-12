<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\SellerNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // CheckoutService reads the buyer's shipping address off public.addresses
    // (a Supabase-managed table outside this repo's Laravel migrations —
    // see other Feature tests for the same shim), which the sqlite test
    // schema doesn't otherwise have.
    if (! Schema::hasTable('addresses')) {
        Schema::create('addresses', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('owner_kind');
            $table->string('profile_id')->nullable();
            $table->string('region_name')->nullable();
            $table->string('province_name')->nullable();
            $table->string('municipality_name')->nullable();
            $table->string('barangay')->nullable();
        });
    }
});

function checkoutPayload(array $items): array
{
    return [
        'items' => $items,
        'delivery_address' => [
            'recipient_name' => 'Test Buyer',
            'contact_number' => '09171234567',
            'address' => '123 Test Street',
        ],
        'shipping_method' => 'standard',
        'payment_method' => 'cod',
    ];
}

it('automatically starts a buyer-seller conversation when checkout creates an order', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $productA = makeProduct($seller);
    $productB = makeProduct($seller);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', checkoutPayload([
        ['product_id' => $productA->id, 'quantity' => 1],
        ['product_id' => $productB->id, 'quantity' => 2],
    ]))->assertCreated();

    // One order for the seller (multiple items collapse into one order),
    // so exactly one conversation must exist — not one per item.
    expect(Conversation::count())->toBe(1);

    $conversation = Conversation::first();
    expect($conversation->buyer_id)->toBe($buyer->id);
    expect($conversation->seller_id)->toBe($seller->id);
    expect($conversation->type)->toBe('direct');
    expect($conversation->seller_unread_count)->toBe(1);
    expect($conversation->participantRecords)->toHaveCount(2);

    expect(Message::count())->toBe(1);
    $message = Message::first();
    expect($message->sender_role)->toBe('system');
    expect($message->body)->toContain('placed');

    expect(SellerNotification::where('type', 'order_placed')->count())->toBe(1);
});

it('does not create a second conversation when the buyer orders again from the same seller', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $product = makeProduct($seller, ['stock' => 20]);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', checkoutPayload([
        ['product_id' => $product->id, 'quantity' => 1],
    ]))->assertCreated();

    $this->postJson('/api/buyer/checkout', checkoutPayload([
        ['product_id' => $product->id, 'quantity' => 1],
    ]))->assertCreated();

    expect(Conversation::count())->toBe(1);
    expect(Message::count())->toBe(2);
});

it('exposes an order preview (image, name, item count, total) on the auto-generated message', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $product = makeProduct($seller, [
        'name' => 'Wireless Mouse',
        'images' => [['url' => 'https://example.test/mouse.jpg']],
    ]);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', checkoutPayload([
        ['product_id' => $product->id, 'quantity' => 1],
    ]))->assertCreated();

    $conversation = Conversation::first();

    $this->getJson("/api/buyer/messages/conversations/{$conversation->id}/messages")
        ->assertOk()
        ->assertJsonPath('data.0.orderContext.itemCount', 1)
        ->assertJsonPath('data.0.orderContext.previewName', 'Wireless Mouse')
        ->assertJsonPath('data.0.orderContext.previewImage', 'https://example.test/mouse.jpg')
        ->assertJsonPath('data.0.orderContext.total', 160);
});

it('starts one conversation per seller when checking out a multi-seller cart', function () {
    $buyer = makeBuyer();
    $sellerA = makeSeller();
    $sellerB = makeSeller();
    $productA = makeProduct($sellerA);
    $productB = makeProduct($sellerB);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/checkout', checkoutPayload([
        ['product_id' => $productA->id, 'quantity' => 1],
        ['product_id' => $productB->id, 'quantity' => 1],
    ]))->assertCreated();

    expect(Conversation::count())->toBe(2);
    expect(Conversation::pluck('seller_id')->sort()->values()->all())
        ->toBe(collect([$sellerA->id, $sellerB->id])->sort()->values()->all());
});
