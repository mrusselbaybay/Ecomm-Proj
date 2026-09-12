<?php

use App\Models\Conversation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
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

it('revives a conversation the buyer previously deleted when they order from that seller again', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $product = makeProduct($seller, ['stock' => 10]);

    actingAsBuyer($buyer);

    // Start a conversation, then the buyer "deletes" it (leaveFor).
    $this->postJson('/api/buyer/messages/conversations', [
        'seller_id' => $seller->id,
        'product_id' => $product->id,
        'body' => 'hi',
    ])->assertCreated();

    $conversation = Conversation::first();
    $this->deleteJson("/api/buyer/messages/conversations/{$conversation->id}")->assertOk();

    expect($conversation->hasActiveParticipant($buyer->id))->toBeFalse();
    // Hidden from the buyer's own inbox now.
    $this->getJson('/api/buyer/messages/conversations')->assertJsonCount(0, 'data');

    // Buyer orders from the same seller again.
    $this->postJson('/api/buyer/checkout', [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'delivery_address' => [
            'recipient_name' => 'Test Buyer',
            'contact_number' => '09171234567',
            'address' => '123 Test Street',
        ],
        'shipping_method' => 'standard',
        'payment_method' => 'cod',
    ])->assertCreated();

    expect(Conversation::count())->toBe(1);
    $conversation->refresh();
    expect($conversation->hasActiveParticipant($buyer->id))->toBeTrue();

    // Back in the buyer's inbox.
    $this->getJson('/api/buyer/messages/conversations')->assertJsonCount(1, 'data');
});
