<?php

use App\Models\ChatAutomationSuggestion;
use App\Models\Message;
use App\Models\OrderItem;
use App\Models\SellerChatRule;
use App\Models\SellerChatSetting;
use App\Models\SellerQuickReplyResponse;

it('returns order quick questions that match the current order status', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    [$order] = makeOrder($buyer, $seller, ['status' => 'New']);
    $conversation = makeBuyerSellerConversation($buyer, $seller, order: $order, overrides: ['type' => 'direct']);

    actingAsBuyer($buyer);

    $response = $this->getJson("/api/buyer/messages/quick-questions?conversation_id={$conversation->id}")
        ->assertOk()
        ->assertJsonFragment(['key' => 'cancel_order'])
        ->assertJsonFragment(['key' => 'change_shipping_address'])
        ->assertJsonMissing(['key' => 'track_order'])
        ->assertJsonMissing(['key' => 'return_refund']);

    expect(collect($response->json('data'))->pluck('orderId')->unique()->all())->toBe([$order->id]);

    $order->forceFill(['status' => 'In Transit', 'tracking_number' => 'SN-TRACK-1'])->save();

    $this->getJson("/api/buyer/messages/quick-questions?conversation_id={$conversation->id}")
        ->assertOk()
        ->assertJsonFragment(['key' => 'track_order'])
        ->assertJsonMissing(['key' => 'cancel_order']);
});

it('lets a buyer choose another ordered product for status questions', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $firstProduct = makeProduct($seller, ['name' => 'Blue jacket']);
    $secondProduct = makeProduct($seller, ['name' => 'Red shirt']);
    [$olderOrder, $olderItem] = makeOrder($buyer, $seller, [
        'status' => 'New',
        'placed_at' => now()->subDay(),
        'tracking_number' => 'SN-OLDER',
    ]);
    $olderItem->forceFill(['product_id' => $firstProduct->id, 'product_name' => 'Blue jacket'])->save();
    [$latestOrder] = makeOrder($buyer, $seller, [
        'status' => 'Delivered',
        'tracking_number' => 'SN-LATEST',
    ]);
    OrderItem::create([
        'order_id' => $latestOrder->id,
        'product_id' => $secondProduct->id,
        'product_name' => 'Red shirt',
        'category' => 'Clothing',
        'unit_price' => 100,
        'quantity' => 1,
        'subtotal' => 100,
    ]);
    $conversation = makeBuyerSellerConversation($buyer, $seller, order: $latestOrder, overrides: ['type' => 'direct']);

    SellerChatSetting::create([
        'seller_id' => $seller->id,
        'auto_reply_enabled' => true,
        'presence_mode' => 'away',
        'generic_away_response' => 'Away',
        'generic_reply_cooldown_minutes' => 240,
    ]);
    SellerQuickReplyResponse::create([
        'seller_id' => $seller->id,
        'question_key' => 'order_status',
        'response' => 'Order #{{order_id}} is {{status}}.',
        'enabled' => true,
    ]);

    actingAsBuyer($buyer);
    $this->getJson("/api/buyer/messages/quick-questions?conversation_id={$conversation->id}")
        ->assertOk()
        ->assertJsonFragment(['orderId' => $latestOrder->id])
        ->assertJsonFragment(['key' => 'return_refund']);

    $this->getJson("/api/buyer/messages/quick-questions?conversation_id={$conversation->id}&order_id={$olderOrder->id}")
        ->assertOk()
        ->assertJsonFragment(['orderId' => $olderOrder->id])
        ->assertJsonFragment(['key' => 'cancel_order'])
        ->assertJsonMissing(['key' => 'return_refund']);

    [$otherSellerOrder] = makeOrder($buyer, makeSeller());
    $this->getJson("/api/buyer/messages/quick-questions?conversation_id={$conversation->id}&order_id={$otherSellerOrder->id}")
        ->assertNotFound();

    $this->getJson("/api/buyer/messages/conversations/{$conversation->id}/products")
        ->assertOk()
        ->assertJsonFragment(['previewName' => 'Blue jacket', 'trackingNumber' => 'SN-OLDER'])
        ->assertJsonFragment(['previewName' => 'Red shirt', 'trackingNumber' => 'SN-LATEST'])
        ->assertJsonPath('meta.total', 3);

    $this->postJson("/api/buyer/messages/conversations/{$conversation->id}/messages", [
        'quick_question_key' => 'order_status',
        'order_id' => $olderOrder->id,
        'product_id' => $firstProduct->id,
    ])->assertCreated()
        ->assertJsonPath('data.productContext.name', 'Blue jacket')
        ->assertJsonPath('data.productContext.trackingNumber', 'SN-OLDER')
        ->assertJsonPath('data.autoReply.productContext.name', 'Blue jacket')
        ->assertJsonPath('data.autoReply.productContext.trackingNumber', 'SN-OLDER')
        ->assertJsonPath('data.autoReply.text', "Order #{$olderOrder->order_number} is Pending.");
});

it('matches a seller keyword rule and renders allowlisted order placeholders while away', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    [$order] = makeOrder($buyer, $seller, ['status' => 'In Transit', 'tracking_number' => 'SN-TRACK-2']);
    $conversation = makeBuyerSellerConversation($buyer, $seller, order: $order, overrides: ['type' => 'direct']);

    SellerChatSetting::query()->create([
        'seller_id' => $seller->id,
        'auto_reply_enabled' => true,
        'bot_mode' => 'auto',
        'presence_mode' => 'away',
        'seller_status' => 'away',
        'generic_away_response' => 'I am away.',
        'generic_reply_cooldown_minutes' => 240,
    ]);
    SellerChatRule::query()->create([
        'seller_id' => $seller->id,
        'match_type' => 'keyword',
        'keyword' => 'tracking',
        'normalized_keyword' => 'tracking',
        'response_template' => 'Order #{{order_id}} is {{status}}. {{tracking_link}}',
        'is_active' => true,
        'priority' => 10,
    ]);

    actingAsBuyer($buyer);
    $this->postJson("/api/buyer/messages/conversations/{$conversation->id}/messages", [
        'body' => 'Can I get the tracking details?',
        'order_id' => $order->id,
    ])->assertCreated();

    expect(Message::query()->where('source', 'auto_reply_specific')->value('body'))
        ->toBe("Order #{$order->order_number} is Shipped. Tracking number SN-TRACK-2");
});

it('creates a reviewable suggestion for an online seller in suggest mode', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller, overrides: ['type' => 'direct']);

    SellerChatSetting::query()->create([
        'seller_id' => $seller->id,
        'auto_reply_enabled' => true,
        'bot_mode' => 'suggest',
        'presence_mode' => 'automatic',
        'seller_status' => 'online',
        'generic_away_response' => 'I am away.',
        'generic_reply_cooldown_minutes' => 240,
    ]);
    SellerChatRule::query()->create([
        'seller_id' => $seller->id,
        'match_type' => 'keyword',
        'keyword' => 'warranty',
        'normalized_keyword' => 'warranty',
        'response_template' => 'Our warranty lasts one year.',
        'is_active' => true,
        'priority' => 10,
    ]);

    actingAsBuyer($buyer);
    $this->postJson("/api/buyer/messages/conversations/{$conversation->id}/messages", [
        'body' => 'Does it have a warranty?',
    ])->assertCreated();

    expect(Message::query()->count())->toBe(1)
        ->and(ChatAutomationSuggestion::query()->value('suggested_response'))->toBe('Our warranty lasts one year.')
        ->and($conversation->fresh()->seller_attention_status)->toBe('needs_reply');

    actingAsSeller($seller);
    $this->getJson("/api/seller/messages/conversations/{$conversation->id}/messages")
        ->assertOk()
        ->assertJsonPath('data.0.suggestion.response', 'Our warranty lasts one year.');
});

it('automatically sends matched suggestions when the seller is away', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller, overrides: ['type' => 'direct']);

    SellerChatSetting::query()->create([
        'seller_id' => $seller->id,
        'auto_reply_enabled' => true,
        'bot_mode' => 'suggest',
        'presence_mode' => 'away',
        'seller_status' => 'away',
        'generic_away_response' => 'I am away.',
        'generic_reply_cooldown_minutes' => 240,
    ]);
    SellerChatRule::query()->create([
        'seller_id' => $seller->id,
        'match_type' => 'keyword',
        'keyword' => 'discount',
        'normalized_keyword' => 'discount',
        'response_template' => 'Use the store voucher shown on the product page.',
        'is_active' => true,
        'priority' => 10,
    ]);

    actingAsBuyer($buyer);
    $this->postJson("/api/buyer/messages/conversations/{$conversation->id}/messages", [
        'body' => 'Do you offer a discount?',
    ])->assertCreated();

    expect(Message::query()->where('source', 'auto_reply_specific')->value('body'))
        ->toBe('Use the store voucher shown on the product page.')
        ->and(ChatAutomationSuggestion::query()->doesntExist())->toBeTrue();
});

it('rejects unsupported placeholders in seller-defined rules', function () {
    $seller = makeSeller();
    actingAsSeller($seller);

    $this->postJson('/api/seller/messages/automation/keyword-rules', [
        'keyword' => 'refund',
        'response_template' => 'Your answer is {{invented_answer}}.',
        'is_active' => true,
        'priority' => 100,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('response_template');
});

it('normalizes seller keyword rules and scopes updates to their owner', function () {
    $seller = makeSeller();
    actingAsSeller($seller);

    $ruleId = $this->postJson('/api/seller/messages/automation/keyword-rules', [
        'keyword' => '  WARRANTY  ',
        'response_template' => 'Warranty details for order #{{order_id}}.',
        'is_active' => true,
        'priority' => 20,
    ])->assertCreated()
        ->assertJsonPath('data.keyword', 'warranty')
        ->json('data.id');

    $otherSeller = makeSeller();
    actingAsSeller($otherSeller);
    $this->putJson("/api/seller/messages/automation/keyword-rules/{$ruleId}", [
        'keyword' => 'warranty',
        'response_template' => 'Changed.',
        'is_active' => true,
        'priority' => 20,
    ])->assertForbidden();
});
