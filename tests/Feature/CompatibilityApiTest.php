<?php

use App\Models\Message;
use App\Models\SellerChatRule;
use App\Models\SellerChatSetting;

it('exposes order quick questions on the requested compatibility endpoint', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    [$order] = makeOrder($buyer, $seller, ['status' => 'New']);
    makeBuyerSellerConversation($buyer, $seller, order: $order, overrides: ['type' => 'direct']);
    actingAsBuyer($buyer);

    $this->getJson("/api/chat/quick-questions?order_id={$order->id}")
        ->assertOk()
        ->assertJsonFragment(['key' => 'cancel_order'])
        ->assertJsonMissing(['key' => 'track_order']);
});

it('sends a buyer message through the compatibility endpoint and retains the matched rule id', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    [$order] = makeOrder($buyer, $seller, ['status' => 'In Transit']);
    $conversation = makeBuyerSellerConversation($buyer, $seller, order: $order, overrides: ['type' => 'direct']);
    SellerChatSetting::query()->create([
        'seller_id' => $seller->id,
        'auto_reply_enabled' => true,
        'bot_mode' => 'auto',
        'presence_mode' => 'away',
        'seller_status' => 'away',
        'generic_away_response' => 'Away',
        'generic_reply_cooldown_minutes' => 240,
    ]);
    $rule = SellerChatRule::query()->create([
        'seller_id' => $seller->id,
        'match_type' => 'keyword',
        'keyword' => 'tracking',
        'normalized_keyword' => 'tracking',
        'response_template' => 'Tracking: {{tracking_link}}',
        'is_active' => true,
        'priority' => 10,
    ]);
    actingAsBuyer($buyer);

    $this->postJson('/api/chat/message', [
        'session_id' => $conversation->id,
        'text' => 'Can I have the tracking details?',
        'order_id' => $order->id,
    ])->assertCreated();

    expect(Message::query()->where('source', 'auto_reply_specific')->value('automation_rule_id'))
        ->toBe($rule->id);
});

it('provides seller rule CRUD and settings at the requested paths', function () {
    $seller = makeSeller();
    actingAsSeller($seller);

    $ruleId = $this->postJson('/api/seller/rules', [
        'keyword' => 'warranty',
        'response_template' => 'Warranty details are available for {{order_id}}.',
        'is_active' => true,
        'priority' => 20,
    ])->assertCreated()->json('data.id');

    $this->putJson("/api/seller/rules/{$ruleId}", [
        'keyword' => 'warranty',
        'response_template' => 'Updated warranty response.',
        'is_active' => true,
        'priority' => 20,
    ])->assertOk()->assertJsonPath('data.response_template', 'Updated warranty response.');

    $this->putJson('/api/seller/settings', [
        'availability' => 'away',
        'bot_mode' => 'auto',
    ])->assertOk()
        ->assertJsonPath('data.availability', 'away')
        ->assertJsonPath('data.bot_mode', 'auto');

    $this->deleteJson("/api/seller/rules/{$ruleId}")->assertOk();
});
