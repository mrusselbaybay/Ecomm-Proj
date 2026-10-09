<?php

/*
|--------------------------------------------------------------------------
| Buyer messaging: inbox, paging, photos, references, receipts
|--------------------------------------------------------------------------
*/

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Profile;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function threadWith(Profile $buyer, Profile $seller, array $overrides = []): Conversation
{
    return Conversation::create(array_merge([
        'buyer_id' => $buyer->id,
        'seller_id' => $seller->id,
        'status' => 'open',
    ], $overrides));
}

function addMessage(Conversation $c, string $role, string $body, $at, array $extra = []): Message
{
    return Message::forceCreate(array_merge([
        'id' => (string) Str::uuid(),
        'conversation_id' => $c->id,
        'sender_id' => $role === 'buyer' ? $c->buyer_id : $c->seller_id,
        'sender_role' => $role,
        'body' => $body,
        'attachments' => [],
        'created_at' => $at,
        'updated_at' => $at,
    ], $extra));
}

function fakeMessageStorage(): void
{
    config(['services.supabase.service_role_key' => 'test-service-key']);

    Http::fake([
        'https://unit-test.supabase.co/storage/v1/bucket/*' => Http::response(['id' => 'message-attachments', 'public' => false], 200),
        'https://unit-test.supabase.co/storage/v1/object/sign/*' => fn (HttpRequest $r) => Http::response(
            collect($r->data()['paths'] ?? [])->map(fn ($p) => ['path' => $p, 'signedURL' => "/object/sign/message-attachments/{$p}?token=t"])->all(),
            200,
        ),
        'https://unit-test.supabase.co/storage/v1/object/*' => Http::response(['Key' => 'ok'], 200),
    ]);
}

it('lists conversations by recent activity without loading message bodies or marking them read', function () {
    $buyer = makeBuyer();
    $older = threadWith($buyer, makeSeller(), ['last_message_at' => now()->subDay(), 'last_message_preview' => 'Old', 'last_message_sender_role' => 'seller', 'buyer_unread_count' => 2]);
    $newer = threadWith($buyer, makeSeller(), ['last_message_at' => now(), 'last_message_preview' => 'Thanks!', 'last_message_sender_role' => 'buyer']);
    $withoutMessage = threadWith($buyer, makeSeller());
    addMessage($older, 'seller', 'Old', now()->subDay());

    actingAsBuyer($buyer);

    $response = $this->getJson('/api/buyer/messages/conversations')
        ->assertOk()
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.0.lastMessageFromMe', true)
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonPath('data.1.unread', 2)
        ->assertJsonPath('data.2.id', $withoutMessage->id)
        ->assertJsonPath('meta.unread_total', 2);

    expect($response->json('data.0'))->not->toHaveKey('messages');
    expect($older->fresh()->buyer_unread_count)->toBe(2);
    expect(Message::first()->read_at)->toBeNull();
});

it('pages older messages and polls newer ones by cursor', function () {
    $buyer = makeBuyer();
    $thread = threadWith($buyer, makeSeller());

    $messages = collect(range(1, 35))->map(fn ($i) => addMessage($thread, $i % 2 ? 'seller' : 'buyer', "m{$i}", now()->subMinutes(100 - $i)));

    actingAsBuyer($buyer);

    $latest = $this->getJson("/api/buyer/messages/conversations/{$thread->id}")
        ->assertOk()
        ->assertJsonCount(30, 'data.messages')
        ->assertJsonPath('data.hasMore', true)
        ->assertJsonPath('data.messages.0.text', 'm6')
        ->assertJsonPath('data.messages.29.text', 'm35');

    $this->getJson("/api/buyer/messages/conversations/{$thread->id}/messages?before=".$latest->json('data.messages.0.id'))
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('data.0.text', 'm1')
        ->assertJsonPath('meta.hasMore', false);

    $reply = addMessage($thread, 'seller', 'new reply', now()->addMinute());

    $this->getJson("/api/buyer/messages/conversations/{$thread->id}/messages?after=".$messages->last()->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $reply->id);
});

it('sends photos to the private bucket and returns signed links', function () {
    $buyer = makeBuyer();
    $thread = threadWith($buyer, makeSeller());

    actingAsBuyer($buyer);
    fakeMessageStorage();

    $response = $this->post("/api/buyer/messages/conversations/{$thread->id}/messages", [
        'images' => [UploadedFile::fake()->image('item.jpg', 800, 600)],
        'client_id' => 'local-1',
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('data.clientId', 'local-1')
        ->assertJsonPath('data.text', '')
        ->assertJsonPath('data.status', 'sent');

    $stored = Message::firstOrFail()->attachments[0];

    expect($stored['path'])->toStartWith($buyer->id.'/');
    expect($response->json('data.attachments.0.url'))->toContain('/object/sign/message-attachments/');
    expect($thread->fresh()->last_message_preview)->toBe('Photo');

    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
        && str_contains($r->url(), '/storage/v1/object/message-attachments/'.$buyer->id.'/'));
});

it('rejects empty messages and unsuitable photos', function (array $payload, string $field) {
    $buyer = makeBuyer();
    $thread = threadWith($buyer, makeSeller());

    actingAsBuyer($buyer);
    fakeMessageStorage();

    $this->post("/api/buyer/messages/conversations/{$thread->id}/messages", $payload, ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(Message::count())->toBe(0);
})->with([
    'empty' => fn () => [['body' => ''], 'body'],
    'pdf' => fn () => [['images' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]], 'images.0'],
    'too big' => fn () => [['images' => [UploadedFile::fake()->image('x.jpg')->size(6000)]], 'images.0'],
    'too many' => fn () => [['images' => array_map(fn () => UploadedFile::fake()->image('x.jpg'), range(1, 5))], 'images'],
]);

it('reports read only after the seller has read the message', function () {
    $buyer = makeBuyer();
    $thread = threadWith($buyer, makeSeller());
    addMessage($thread, 'buyer', 'seen', now()->subMinute(), ['read_at' => now()]);
    addMessage($thread, 'buyer', 'not yet', now());

    actingAsBuyer($buyer);

    $this->getJson("/api/buyer/messages/conversations/{$thread->id}/messages")
        ->assertOk()
        ->assertJsonPath('data.0.status', 'read')
        ->assertJsonPath('data.1.status', 'sent');
});

it('shows the order reference only for the buyer own order with that seller, at paid prices', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    [$order, $item] = makeOrder($buyer, $seller, ['order_number' => 'SN-55555', 'status' => 'In Transit']);
    $item->update(['product_name' => 'Clay Mug', 'unit_price' => 250, 'quantity' => 2]);
    $product = makeProduct($seller, ['name' => 'Clay Mug', 'price' => 300]);

    $thread = threadWith($buyer, $seller, ['order_id' => $order->id, 'product_id' => $product->id]);

    actingAsBuyer($buyer);

    $this->getJson("/api/buyer/messages/conversations/{$thread->id}")
        ->assertOk()
        ->assertJsonPath('data.order.number', 'SN-55555')
        ->assertJsonPath('data.order.status', 'In Transit')
        ->assertJsonPath('data.order.items.0.unitPrice', 250)
        ->assertJsonPath('data.product.price', 300);

    // An order that isn't between this buyer and this seller is never shown.
    [$foreign] = makeOrder(makeBuyer(), $seller, ['order_number' => 'SN-66666']);
    $thread->update(['order_id' => $foreign->id]);

    $this->getJson("/api/buyer/messages/conversations/{$thread->id}")
        ->assertOk()
        ->assertJsonPath('data.order', null);
});

it('does not let another buyer read messages or their photo links', function () {
    $owner = makeBuyer();
    $thread = threadWith($owner, makeSeller());
    addMessage($thread, 'buyer', 'private', now(), ['attachments' => [['id' => 'x', 'name' => 'p.jpg', 'path' => $owner->id.'/p.jpg', 'mime' => 'image/jpeg', 'size' => 10]]]);

    actingAsBuyer(makeBuyer());

    $this->getJson("/api/buyer/messages/conversations/{$thread->id}/messages")->assertNotFound();
    $this->getJson('/api/buyer/messages/conversations/not-a-uuid')->assertNotFound();
});

it('starts a conversation with only a photo', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();

    actingAsBuyer($buyer);
    fakeMessageStorage();

    $this->post('/api/buyer/messages/conversations', [
        'seller_id' => $seller->id,
        'images' => [UploadedFile::fake()->image('item.jpg', 400, 300)],
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('data.lastMessagePreview', 'Photo')
        ->assertJsonPath('data.messages.0.text', '')
        ->assertJsonCount(1, 'data.messages.0.attachments');

    expect(Conversation::where('buyer_id', $buyer->id)->where('seller_id', $seller->id)->count())->toBe(1);
});

it('still refuses to start a conversation with nothing in it', function () {
    actingAsBuyer(makeBuyer());

    $this->postJson('/api/buyer/messages/conversations', ['seller_id' => makeSeller()->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body']);

    expect(Conversation::count())->toBe(0);
});

it('moves the product reference when a message asks about another of the seller products', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $first = makeProduct($seller, ['name' => 'Kibble 5kg']);
    $second = makeProduct($seller, ['name' => 'Kibble 10kg']);
    $thread = threadWith($buyer, $seller, ['product_id' => $first->id]);

    actingAsBuyer($buyer);

    $this->postJson("/api/buyer/messages/conversations/{$thread->id}/messages", ['body' => 'And the 10kg?', 'product_id' => $second->id])
        ->assertCreated();

    expect($thread->fresh()->product_id)->toBe($second->id);

    $this->getJson("/api/buyer/messages/conversations/{$thread->id}")
        ->assertJsonPath('data.product.name', 'Kibble 10kg');
});

it('rejects a product reference from another seller', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $thread = threadWith($buyer, $seller);
    $foreign = makeProduct(makeSeller());

    actingAsBuyer($buyer);

    $this->postJson("/api/buyer/messages/conversations/{$thread->id}/messages", ['body' => 'Hi', 'product_id' => $foreign->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['product_id']);

    expect($thread->fresh()->product_id)->toBeNull();
    expect(Message::count())->toBe(0);
});
