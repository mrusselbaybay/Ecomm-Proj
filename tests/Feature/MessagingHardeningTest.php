<?php

use App\Models\Complaint;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Storage;

it('only allows valid seller conversation status transitions', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $conversation = makeBuyerSellerConversation($buyer, $seller);

    actingAsSeller($seller);

    $this->putJson("/api/seller/messages/conversations/{$conversation->id}/status", [
        'status' => 'active',
    ])->assertUnprocessable();

    $this->putJson("/api/seller/messages/conversations/{$conversation->id}/status", [
        'status' => 'resolved',
    ])->assertOk();

    $this->putJson("/api/seller/messages/conversations/{$conversation->id}/status", [
        'status' => 'resolved',
    ])->assertUnprocessable();
});

it('persists seller reports in the admin complaint queue', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    [$order] = makeOrder($buyer, $seller);
    $conversation = makeBuyerSellerConversation($buyer, $seller, order: $order);

    actingAsSeller($seller);

    $response = $this->postJson("/api/seller/messages/conversations/{$conversation->id}/report", [
        'reason' => 'The buyer repeatedly sent abusive messages.',
    ])->assertCreated()
        ->assertJsonPath('data.reported', true);

    $complaint = Complaint::sole();

    expect($response->json('data.complaint_id'))->toBe($complaint->id)
        ->and($complaint->complainant_id)->toBe($seller->id)
        ->and($complaint->respondent_id)->toBe($buyer->id)
        ->and($complaint->order_id)->toBe($order->id)
        ->and($complaint->evidence)->toBe([['conversation_id' => $conversation->id]]);
});

it('prunes abandoned staged attachment files', function () {
    Storage::fake('message_attachments');

    $seller = makeSeller();
    Storage::disk('message_attachments')->put('staged/old.pdf', 'old');
    Storage::disk('message_attachments')->put('staged/recent.pdf', 'recent');

    $old = MessageAttachment::create([
        'seller_id' => $seller->id,
        'uploader_id' => $seller->id,
        'name' => 'old.pdf',
        'mime' => 'application/pdf',
        'size' => 3,
        'url' => 'staged/old.pdf',
    ]);
    $old->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

    $recent = MessageAttachment::create([
        'seller_id' => $seller->id,
        'uploader_id' => $seller->id,
        'name' => 'recent.pdf',
        'mime' => 'application/pdf',
        'size' => 6,
        'url' => 'staged/recent.pdf',
    ]);

    $this->artisan('messages:prune-staged-attachments')->assertSuccessful();

    expect($old->fresh())->toBeNull()
        ->and($recent->fresh())->not->toBeNull();
    Storage::disk('message_attachments')->assertMissing('staged/old.pdf');
    Storage::disk('message_attachments')->assertExists('staged/recent.pdf');
});
