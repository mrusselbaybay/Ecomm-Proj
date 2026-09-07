<?php

use App\Models\Conversation;
use App\Models\CourierApplication;
use App\Models\LogisticsCompany;
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
