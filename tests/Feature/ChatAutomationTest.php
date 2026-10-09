<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\SellerChatSetting;
use App\Models\SellerQuickReplyResponse;

it('sends a seller-specific reply for a quick question while the seller is away', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller, overrides: ['type' => 'direct']);

    SellerChatSetting::create([
        'seller_id' => $seller->id,
        'auto_reply_enabled' => true,
        'presence_mode' => 'away',
        'generic_away_response' => 'I am away right now.',
        'generic_reply_cooldown_minutes' => 240,
    ]);
    SellerQuickReplyResponse::create([
        'seller_id' => $seller->id,
        'question_key' => 'shipping_time',
        'response' => 'We ship within 24 hours!',
        'enabled' => true,
    ]);

    actingAsBuyer($buyer);

    $this->postJson("/api/buyer/messages/conversations/{$conversation->id}/messages", [
        'body' => 'Changed text is ignored for preset questions',
        'quick_question_key' => 'shipping_time',
    ])->assertCreated()
        ->assertJsonPath('data.text', 'When will you ship?')
        ->assertJsonPath('data.source', 'quick_question')
        ->assertJsonPath('data.autoReply.text', 'We ship within 24 hours!')
        ->assertJsonPath('data.autoReply.source', 'auto_reply_specific');

    expect(Message::query()->count())->toBe(2)
        ->and(Message::query()->where('source', 'auto_reply_specific')->value('body'))->toBe('We ship within 24 hours!')
        ->and($conversation->fresh()->seller_attention_status)->toBe('auto_replied');
});

it('sends the generic away response for free text and keeps seller attention required', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();

    SellerChatSetting::create([
        'seller_id' => $seller->id,
        'auto_reply_enabled' => true,
        'presence_mode' => 'away',
        'generic_away_response' => 'I am away right now.',
        'generic_reply_cooldown_minutes' => 240,
    ]);

    actingAsBuyer($buyer);

    $this->postJson('/api/buyer/messages/conversations', [
        'seller_id' => $seller->id,
        'body' => 'Hello?',
    ])->assertCreated()
        ->assertJsonCount(2, 'data.messages')
        ->assertJsonPath('data.messages.1.isAutomatic', true)
        ->assertJsonPath('data.messages.1.source', 'auto_reply_welcome');

    $conversation = Conversation::query()->where('buyer_id', $buyer->id)->where('seller_id', $seller->id)->firstOrFail();

    $this->postJson("/api/buyer/messages/conversations/{$conversation->id}/messages", [
        'body' => 'I have another question.',
    ])->assertCreated()
        ->assertJsonPath('data.autoReply.text', 'I am away right now.')
        ->assertJsonPath('data.autoReply.source', 'auto_reply_generic');

    expect(Message::query()->where('source', 'auto_reply_welcome')->count())->toBe(1)
        ->and(Message::query()->where('source', 'auto_reply_generic')->count())->toBe(1)
        ->and($conversation->fresh()->seller_attention_status)->toBe('needs_reply');
});

it('does not auto reply while a seller using automatic presence is online', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $seller->forceFill(['last_active_at' => now()])->save();
    $conversation = makeBuyerSellerConversation($buyer, $seller, overrides: ['type' => 'direct']);

    SellerChatSetting::create([
        'seller_id' => $seller->id,
        'auto_reply_enabled' => true,
        'presence_mode' => 'automatic',
        'generic_away_response' => 'I am away right now.',
        'generic_reply_cooldown_minutes' => 240,
    ]);

    actingAsBuyer($buyer);

    $this->postJson("/api/buyer/messages/conversations/{$conversation->id}/messages", [
        'body' => 'Hello?',
    ])->assertCreated();

    expect(Message::query()->count())->toBe(1)
        ->and($conversation->fresh()->seller_attention_status)->toBe('needs_reply');
});

it('pauses automation for a direct conversation after the seller replies manually', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller, overrides: ['type' => 'direct']);

    actingAsSeller($seller);

    $this->postJson("/api/seller/messages/conversations/{$conversation->id}/messages", [
        'body' => 'I will handle this conversation.',
    ])->assertCreated();

    $conversation->refresh();

    expect($conversation->automation_paused_until)->not->toBeNull()
        ->and($conversation->automation_paused_until->isFuture())->toBeTrue()
        ->and($conversation->seller_attention_status)->toBe('handled');
});

it('exposes seeded questions and lets a seller save their automation settings', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();

    actingAsBuyer($buyer);
    $this->getJson('/api/buyer/messages/quick-questions')
        ->assertOk()
        ->assertJsonCount(3, 'data');

    actingAsSeller($seller);
    $this->putJson('/api/seller/messages/automation', [
        'auto_reply_enabled' => true,
        'presence_mode' => 'away',
        'generic_away_response' => 'Back tomorrow.',
        'generic_reply_cooldown_minutes' => 120,
    ])->assertOk()
        ->assertJsonPath('data.autoReplyEnabled', true)
        ->assertJsonPath('data.presenceMode', 'away');

    $this->putJson('/api/seller/messages/automation/questions/discounts', [
        'response' => 'Use our store voucher.',
        'enabled' => true,
    ])->assertOk()
        ->assertJsonPath('data.response', 'Use our store voucher.');
});
