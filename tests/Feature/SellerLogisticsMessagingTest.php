<?php

use App\Models\Conversation;
use App\Models\CourierApplication;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ParcelAssignment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    if (! Schema::hasTable('logistics_companies')) {
        Schema::create('logistics_companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_profile_id');
            $table->string('company_name');
            $table->string('company_email')->nullable();
            $table->string('region')->nullable();
            $table->string('status')->default('approved');
            $table->string('account_status')->default('active');
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('courier_applications')) {
        Schema::create('courier_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('courier_profile_id');
            $table->uuid('logistics_company_id');
            $table->string('status');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }
});

function makeAssignedSellerParcel(string $region = 'Luzon'): array
{
    $buyer = makeBuyer();
    $seller = makeSeller();
    $logistics = makeLogistics();
    $rider = makeCourier();
    [$order] = makeOrder($buyer, $seller, ['status' => 'In Transit']);
    $company = LogisticsCompany::create([
        'id' => (string) Str::uuid(),
        'owner_profile_id' => $logistics->id,
        'company_name' => $region.' Freight',
        'company_email' => strtolower($region).'@example.test',
        'region' => $region,
        'status' => 'approved',
        'account_status' => 'active',
    ]);
    CourierApplication::create([
        'courier_profile_id' => $rider->id,
        'logistics_company_id' => $company->id,
        'status' => CourierApplication::STATUS_ACCEPTED,
        'applied_at' => now(),
    ]);
    $assignment = ParcelAssignment::create([
        'order_id' => $order->id,
        'logistics_company_id' => $company->id,
        'rider_profile_id' => $rider->id,
        'status' => ParcelAssignment::STATUS_ASSIGNED,
        'received_by' => $logistics->id,
        'assigned_by' => $logistics->id,
        'received_at' => now(),
        'assigned_at' => now(),
    ]);

    return compact('seller', 'logistics', 'rider', 'order', 'company', 'assignment');
}

it('lets a seller message only the logistics company of the assigned rider', function () {
    $context = makeAssignedSellerParcel('Visayas');
    actingAsSeller($context['seller']);

    $this->getJson('/api/seller/messages/logistics-contacts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.parcel_assignment_id', $context['assignment']->id)
        ->assertJsonPath('data.0.region', 'Visayas');

    $response = $this->postJson('/api/seller/messages/logistics-conversations', [
        'parcel_assignment_id' => $context['assignment']->id,
        'body' => 'Please confirm this parcel handover.',
    ])->assertCreated()
        ->assertJsonPath('data.type', 'shipment')
        ->assertJsonPath('data.shipment.region', 'Visayas')
        ->assertJsonPath('data.shipment.rider', $context['rider']->full_name);

    $conversation = Conversation::findOrFail($response->json('data.id'));

    expect($conversation->logistics_company_id)->toBe($context['company']->id)
        ->and($conversation->seller_id)->toBe($context['seller']->id)
        ->and($conversation->logistics_unread_count)->toBe(1)
        ->and($conversation->participants()->pluck('profiles.id')->all())
        ->toContain($context['seller']->id, $context['logistics']->id);
});

it('blocks a seller from using another sellers parcel assignment', function () {
    $context = makeAssignedSellerParcel();
    $unrelatedSeller = makeSeller();
    actingAsSeller($unrelatedSeller);

    $this->postJson('/api/seller/messages/logistics-conversations', [
        'parcel_assignment_id' => $context['assignment']->id,
        'body' => 'Unauthorized contact attempt.',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('parcel_assignment_id');

    expect(Conversation::count())->toBe(0);
});

it('requires the assigned rider to belong to the parcel logistics company', function () {
    $context = makeAssignedSellerParcel('Mindanao');
    $unrelatedRider = makeCourier();
    $context['assignment']->update(['rider_profile_id' => $unrelatedRider->id]);
    actingAsSeller($context['seller']);

    $this->postJson('/api/seller/messages/logistics-conversations', [
        'parcel_assignment_id' => $context['assignment']->id,
        'body' => 'This must be rejected.',
    ])->assertUnprocessable();
});

it('lets a seller delete a shipment conversation without affecting the logistics side', function () {
    $context = makeAssignedSellerParcel();
    actingAsSeller($context['seller']);

    $conversationId = $this->postJson('/api/seller/messages/logistics-conversations', [
        'parcel_assignment_id' => $context['assignment']->id,
        'body' => 'Please confirm this parcel handover.',
    ])->json('data.id');

    $this->deleteJson("/api/seller/messages/conversations/{$conversationId}")->assertOk();
    $this->getJson("/api/seller/messages/conversations/{$conversationId}")->assertStatus(404);

    // The logistics side's own copy is untouched.
    actingAsProfile($context['logistics']);
    $this->getJson("/api/logistics/messages/conversations/{$conversationId}")->assertOk();

    // A reply from logistics revives it for the seller instead of it
    // staying permanently hidden.
    $this->postJson("/api/logistics/messages/conversations/{$conversationId}/messages", ['body' => 'confirmed'])
        ->assertCreated();

    actingAsSeller($context['seller']);
    $this->getJson("/api/seller/messages/conversations/{$conversationId}")->assertOk();
});

it('allows only the assigned logistics account to read and reply', function () {
    $context = makeAssignedSellerParcel();
    actingAsSeller($context['seller']);
    $conversationId = $this->postJson('/api/seller/messages/logistics-conversations', [
        'parcel_assignment_id' => $context['assignment']->id,
        'body' => 'Is the parcel ready for dispatch?',
    ])->json('data.id');

    actingAsProfile($context['logistics']);
    $this->getJson("/api/logistics/messages/conversations/{$conversationId}")
        ->assertOk()
        ->assertJsonPath('data.shipment.region', 'Luzon');
    $this->postJson("/api/logistics/messages/conversations/{$conversationId}/messages", [
        'body' => 'Yes, the assigned rider has received it.',
    ])->assertCreated()
        ->assertJsonPath('data.from', 'logistics');

    $otherLogistics = makeLogistics();
    LogisticsCompany::create([
        'id' => (string) Str::uuid(), 'owner_profile_id' => $otherLogistics->id,
        'company_name' => 'Other Region Freight', 'region' => 'Mindanao',
        'status' => 'approved', 'account_status' => 'active',
    ]);
    actingAsProfile($otherLogistics);
    $this->getJson("/api/logistics/messages/conversations/{$conversationId}")->assertNotFound();
});

it('lets logistics archive and unarchive a shipment conversation without affecting the seller', function () {
    $context = makeAssignedSellerParcel();
    actingAsSeller($context['seller']);
    $conversationId = $this->postJson('/api/seller/messages/logistics-conversations', [
        'parcel_assignment_id' => $context['assignment']->id,
        'body' => 'Please confirm this parcel handover.',
    ])->json('data.id');

    actingAsProfile($context['logistics']);

    $this->putJson("/api/logistics/messages/conversations/{$conversationId}/status", ['status' => 'archived'])
        ->assertOk()
        ->assertJsonPath('data.archived', true)
        // Archiving is per-participant, not the shared status column — it
        // must never block replying for either side (see the buyer/seller
        // bug: one side archiving used to hide/block it for BOTH).
        ->assertJsonPath('data.status', 'open');

    expect(Conversation::find($conversationId)->status)->toBe('open');

    // Logistics mirrors seller's tabbed inbox (not buyer's binary default/
    // archived split) — the default "all" view still includes archived
    // items mixed in; only the dedicated tab filters to just those.
    $this->getJson('/api/logistics/messages/conversations')->assertJsonCount(1, 'data');
    $this->getJson('/api/logistics/messages/conversations?status=archived')->assertJsonCount(1, 'data');

    // The seller's own copy is completely unaffected — still visible,
    // still not archived for them.
    actingAsSeller($context['seller']);
    $this->getJson("/api/seller/messages/conversations/{$conversationId}")
        ->assertOk()
        ->assertJsonPath('data.archived', false);

    // Explicit unarchive (no intervening message — sending, from either
    // side, auto-revives an archived thread, which is covered by its own
    // test below, so it's kept out of this one to avoid the two conflicting).
    actingAsProfile($context['logistics']);
    $this->putJson("/api/logistics/messages/conversations/{$conversationId}/status", ['status' => 'open'])
        ->assertOk()
        ->assertJsonPath('data.archived', false);
});

it('auto-revives an archived shipment conversation for logistics when the seller sends a new message', function () {
    $context = makeAssignedSellerParcel();
    actingAsSeller($context['seller']);
    $conversationId = $this->postJson('/api/seller/messages/logistics-conversations', [
        'parcel_assignment_id' => $context['assignment']->id,
        'body' => 'Please confirm this parcel handover.',
    ])->json('data.id');

    actingAsProfile($context['logistics']);
    $this->putJson("/api/logistics/messages/conversations/{$conversationId}/status", ['status' => 'archived'])->assertOk();

    actingAsSeller($context['seller']);
    $this->postJson("/api/seller/messages/conversations/{$conversationId}/messages", ['body' => 'reply from seller'])
        ->assertCreated();

    actingAsProfile($context['logistics']);
    $this->getJson("/api/logistics/messages/conversations/{$conversationId}")
        ->assertOk()
        ->assertJsonPath('data.archived', false);
    $this->getJson('/api/logistics/messages/conversations')->assertJsonCount(1, 'data');
});

it('lets logistics attach a file to a shipment message', function () {
    $context = makeAssignedSellerParcel();
    actingAsSeller($context['seller']);
    $conversationId = $this->postJson('/api/seller/messages/logistics-conversations', [
        'parcel_assignment_id' => $context['assignment']->id,
        'body' => 'Please confirm this parcel handover.',
    ])->json('data.id');

    actingAsProfile($context['logistics']);

    $attachmentId = $this->postJson('/api/logistics/messages/attachments', [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('proof.jpg', 50, 'image/jpeg'),
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/logistics/messages/conversations/{$conversationId}/messages", [
        'attachment_ids' => [$attachmentId],
    ])->assertCreated()
        ->assertJsonPath('data.attachments.0.id', $attachmentId);
});

it('filters and counts logistics conversations by status', function () {
    $openContext = makeAssignedSellerParcel('Luzon');
    $archivedContext = makeAssignedSellerParcel('Visayas');

    actingAsSeller($openContext['seller']);
    $this->postJson('/api/seller/messages/logistics-conversations', [
        'parcel_assignment_id' => $openContext['assignment']->id,
        'body' => 'Open thread',
    ])->assertCreated();

    actingAsSeller($archivedContext['seller']);
    $archivedId = $this->postJson('/api/seller/messages/logistics-conversations', [
        'parcel_assignment_id' => $archivedContext['assignment']->id,
        'body' => 'To be archived',
    ])->json('data.id');

    // Both threads are with different logistics companies by default (each
    // makeAssignedSellerParcel() call creates its own company/owner) — use
    // the same logistics owner for both so one account sees both threads.
    Conversation::whereKey($archivedId)->update(['logistics_company_id' => $openContext['company']->id]);
    \App\Models\ConversationParticipant::updateOrCreate(
        ['conversation_id' => $archivedId, 'user_id' => $openContext['logistics']->id],
        ['joined_at' => now()],
    );

    actingAsProfile($openContext['logistics']);
    $this->putJson("/api/logistics/messages/conversations/{$archivedId}/status", ['status' => 'archived'])->assertOk();

    $this->getJson('/api/logistics/messages/conversations')
        ->assertOk()
        ->assertJsonPath('meta.statusCounts.all', 2)
        ->assertJsonPath('meta.statusCounts.archived', 1);

    $this->getJson('/api/logistics/messages/conversations?status=archived')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $archivedId);
});

function makeParcelOrder(App\Models\Profile $buyer, App\Models\Profile $seller, LogisticsCompany $company, App\Models\Profile $rider, array $overrides = []): Order
{
    $product = makeProduct($seller, ['name' => 'Wireless Mouse', 'images' => [['url' => 'https://example.test/mouse.jpg']]]);

    $order = Order::create(array_merge([
        'order_number' => 'SN-'.random_int(10000, 99999),
        'seller_id' => $seller->id,
        'buyer_profile_id' => $buyer->id,
        'recipient_name' => 'Test Buyer',
        'status' => 'In Transit',
        'payment_status' => 'Unpaid',
        'subtotal' => 100,
        'shipping_fee' => 60,
        'total' => 160,
        'placed_at' => now()->addSecond(),
    ], $overrides));

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'category' => $product->category,
        'unit_price' => 100,
        'quantity' => 1,
        'subtotal' => 100,
    ]);

    ParcelAssignment::create([
        'order_id' => $order->id,
        'logistics_company_id' => $company->id,
        'rider_profile_id' => $rider->id,
        'status' => ParcelAssignment::STATUS_ASSIGNED,
        'received_by' => $rider->id,
        'assigned_by' => $rider->id,
        'received_at' => now(),
        'assigned_at' => now(),
    ]);

    return $order;
}

it('lists the sellers non-delivered parcels shipped through this logistics company, newest first, paginated 4', function () {
    $context = makeAssignedSellerParcel();
    actingAsSeller($context['seller']);
    $conversationId = $this->postJson('/api/seller/messages/logistics-conversations', [
        'parcel_assignment_id' => $context['assignment']->id,
        'body' => 'Please confirm this parcel handover.',
    ])->json('data.id');

    // 5 more non-delivered parcels through the same company (6 total
    // including the one from makeAssignedSellerParcel), plus one delivered
    // one that must never appear.
    for ($i = 0; $i < 5; $i++) {
        makeParcelOrder(makeBuyer(), $context['seller'], $context['company'], $context['rider']);
    }
    makeParcelOrder(makeBuyer(), $context['seller'], $context['company'], $context['rider'], ['status' => 'Delivered']);

    // A parcel through a DIFFERENT logistics company must not appear either.
    $otherCompany = LogisticsCompany::create([
        'id' => (string) Str::uuid(), 'owner_profile_id' => makeLogistics()->id,
        'company_name' => 'Other Freight', 'region' => 'Mindanao',
        'status' => 'approved', 'account_status' => 'active',
    ]);
    makeParcelOrder(makeBuyer(), $context['seller'], $otherCompany, $context['rider']);

    actingAsSeller($context['seller']);

    $page1 = $this->getJson("/api/seller/messages/conversations/{$conversationId}/parcels")
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('meta.total', 6)
        ->assertJsonPath('meta.lastPage', 2)
        ->json('data');

    $page2 = $this->getJson("/api/seller/messages/conversations/{$conversationId}/parcels?page=2")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->json('data');

    // Every one of the 6 eligible parcels resolves a real product preview
    // image — asserted across both pages combined rather than by position,
    // since ordering among same-second placed_at rows isn't meaningful here.
    $orderIds = collect([...$page1, ...$page2])->pluck('orderId');
    expect($orderIds->unique())->toHaveCount(6);
    expect(collect([...$page1, ...$page2])->every(fn ($p) => $p['previewImage'] === 'https://example.test/mouse.jpg' || $p['orderId'] === $context['order']->id))->toBeTrue();
});

it('lets a seller attach a parcel inquiry (order/product context) to a logistics message', function () {
    $context = makeAssignedSellerParcel();
    actingAsSeller($context['seller']);
    $conversationId = $this->postJson('/api/seller/messages/logistics-conversations', [
        'parcel_assignment_id' => $context['assignment']->id,
        'body' => 'Please confirm this parcel handover.',
    ])->json('data.id');

    $product = makeProduct($context['seller'], ['name' => 'Bluetooth Speaker']);
    $order = makeParcelOrder(makeBuyer(), $context['seller'], $context['company'], $context['rider']);

    $this->postJson("/api/seller/messages/conversations/{$conversationId}/messages", [
        'body' => 'Inquiring about this parcel:',
        'order_id' => $order->id,
        'product_id' => $order->items->first()->product_id,
    ])->assertCreated()
        ->assertJsonPath('data.orderContext.orderNumber', $order->order_number)
        ->assertJsonPath('data.productContext.name', 'Wireless Mouse');

    // Someone else's order/product is rejected, not silently trusted.
    $unrelatedSeller = makeSeller();
    $unrelatedOrder = makeParcelOrder(makeBuyer(), $unrelatedSeller, $context['company'], $context['rider']);

    $this->postJson("/api/seller/messages/conversations/{$conversationId}/messages", [
        'body' => 'Inquiring about this parcel:',
        'order_id' => $unrelatedOrder->id,
    ])->assertStatus(422);
});
