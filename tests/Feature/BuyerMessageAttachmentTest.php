<?php

use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('shows seller attachments in the buyer conversation', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller);

    Message::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $seller->id,
        'sender_role' => 'seller',
        'message_type' => 'attachment',
        'body' => '',
        'attachments' => [[
            'id' => fake()->uuid(),
            'name' => 'product-photo.jpg',
            'url' => 'data:image/jpeg;base64,dGVzdA==',
            'mime' => 'image/jpeg',
            'size' => 4,
        ]],
    ]);

    actingAsBuyer($buyer);

    $this->getJson("/api/buyer/messages/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJsonPath('data.messages.0.attachments.0.name', 'product-photo.jpg')
        ->assertJsonPath('data.messages.0.attachments.0.mime', 'image/jpeg')
        ->assertJsonPath('data.messages.0.attachments.0.url', 'data:image/jpeg;base64,dGVzdA==');
});

it('lets a buyer upload and send an attachment without message text', function () {
    Storage::fake('message_attachments');
    $buyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller);

    actingAsBuyer($buyer);

    $upload = $this->post('/api/buyer/messages/attachments', [
        'file' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'receipt.pdf')
        ->assertJsonPath('data.mime', 'application/pdf');

    $attachmentId = $upload->json('data.id');

    $this->postJson("/api/buyer/messages/conversations/{$conversation->id}/messages", [
        'attachment_ids' => [$attachmentId],
    ])->assertCreated()
        ->assertJsonPath('data.text', '')
        ->assertJsonPath('data.attachments.0.id', $attachmentId)
        ->assertJsonPath('data.attachments.0.name', 'receipt.pdf');

    $message = Message::sole();
    $attachment = MessageAttachment::sole();

    expect($message->message_type)->toBe('attachment')
        ->and($attachment->uploader_id)->toBe($buyer->id)
        ->and($attachment->seller_id)->toBeNull()
        ->and($attachment->message_id)->toBe($message->id)
        ->and($conversation->fresh()->seller_unread_count)->toBe(1)
        ->and($conversation->fresh()->last_message_preview)->toBe('Sent an attachment');

    Storage::disk('message_attachments')->assertExists($attachment->url);

    $this->get($upload->json('data.url'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('rejects unsupported buyer attachment types', function () {
    $buyer = makeBuyer();

    actingAsBuyer($buyer);

    $this->post('/api/buyer/messages/attachments', [
        'file' => UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});

it('does not let a buyer claim another users staged attachment', function () {
    $buyer = makeBuyer();
    $otherBuyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller);
    $attachment = MessageAttachment::create([
        'seller_id' => null,
        'uploader_id' => $otherBuyer->id,
        'message_id' => null,
        'name' => 'private.pdf',
        'mime' => 'application/pdf',
        'size' => 10,
        'url' => 'data:application/pdf;base64,dGVzdA==',
    ]);

    actingAsBuyer($buyer);

    $this->postJson("/api/buyer/messages/conversations/{$conversation->id}/messages", [
        'attachment_ids' => [$attachment->id],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('attachment_ids');

    expect(Message::count())->toBe(0)
        ->and($attachment->fresh()->message_id)->toBeNull();
});

it('keeps seller attachment uploads working with generalized ownership', function () {
    Storage::fake('message_attachments');
    $buyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller);

    actingAsSeller($seller);

    $upload = $this->post('/api/seller/messages/attachments', [
        'file' => UploadedFile::fake()->create('manual.pdf', 50, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $attachmentId = $upload->json('data.id');

    $this->postJson("/api/seller/messages/conversations/{$conversation->id}/messages", [
        'attachment_ids' => [$attachmentId],
    ])->assertCreated()
        ->assertJsonPath('data.attachments.0.id', $attachmentId);

    $attachment = MessageAttachment::sole();

    expect($attachment->uploader_id)->toBe($seller->id)
        ->and($attachment->seller_id)->toBe($seller->id)
        ->and($attachment->message_id)->not->toBeNull();
});
