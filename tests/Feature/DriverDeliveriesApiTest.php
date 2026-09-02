<?php

use App\Models\LogisticsDeliveryArea;
use App\Models\ParcelAssignment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// logistics_companies is a Supabase-managed table with no local Laravel
// migration (see the other Logistics*ApiTest / SellerHandoverParcelIntake
// Test files for the same ad hoc fixture) — makeLogisticsCompany() itself
// is declared globally by SellerHandoverParcelIntakeTest.php and reused
// here rather than redeclared, since Pest loads every test file's
// top-level functions into one shared global namespace.
beforeEach(function () {
    if (! Schema::hasTable('logistics_companies')) {
        Schema::create('logistics_companies', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('owner_profile_id');
            $table->string('company_name');
            $table->string('status')->default('approved');
            $table->string('account_status')->default('active');
            $table->timestamps();
        });
    }
});

it('rejects an unauthenticated request', function () {
    $this->getJson('/api/driver/deliveries')->assertStatus(401);
});

it('lists a parcel the logistics team has assigned to the signed-in rider', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $courier = makeCourier(['first_name' => 'Marco', 'last_name' => 'Rivera']);
    $company = makeLogisticsCompany(['company_name' => 'NexMart Logistics']);
    $area = LogisticsDeliveryArea::factory()->create([
        'logistics_company_id' => $company->id,
        'name' => 'Area B',
    ]);

    [$order] = makeOrder($buyer, $seller, [
        'recipient_name' => 'Liza Cruz',
        'status' => 'In Transit',
        'shipping_house_no' => '14',
        'shipping_street' => 'Maple St',
        'shipping_barangay' => 'Sikatuna Village',
        'shipping_municipality_name' => 'Quezon City',
        'shipping_province_name' => 'Metro Manila',
    ]);

    $assignment = ParcelAssignment::create([
        'order_id' => $order->id,
        'logistics_company_id' => $company->id,
        'delivery_area_id' => $area->id,
        'rider_profile_id' => $courier->id,
        'status' => ParcelAssignment::STATUS_ASSIGNED,
        'received_by' => $seller->id,
        'assigned_by' => $seller->id,
        'received_at' => now()->subHour(),
        'sorted_at' => now()->subMinutes(40),
        'assigned_at' => now()->subMinutes(25),
    ]);

    actingAsDriver($courier);

    $this->getJson('/api/driver/deliveries')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $assignment->id)
        ->assertJsonPath('data.0.order_number', $order->order_number)
        ->assertJsonPath('data.0.customer_name', 'Liza Cruz')
        ->assertJsonPath('data.0.delivery_area', 'Area B')
        ->assertJsonPath('data.0.dropoff_label', '14, Maple St, Sikatuna Village, Quezon City, Metro Manila')
        ->assertJsonPath('data.0.pickup_label', 'NexMart Logistics — Sorting Hub')
        ->assertJsonPath('data.0.parcels', 1)
        ->assertJsonPath('data.0.status', 'assigned');
});

it('reports handed-off assignments as picked up, and delivered orders as delivered', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $courier = makeCourier();
    $company = makeLogisticsCompany();

    [$inTransitOrder] = makeOrder($buyer, $seller, ['status' => 'In Transit']);
    [$deliveredOrder] = makeOrder($buyer, $seller, ['status' => 'Delivered']);

    $pickedUp = ParcelAssignment::create([
        'order_id' => $inTransitOrder->id,
        'logistics_company_id' => $company->id,
        'rider_profile_id' => $courier->id,
        'status' => ParcelAssignment::STATUS_HANDED_OFF,
        'received_by' => $seller->id,
        'received_at' => now()->subHours(2),
        'assigned_at' => now()->subHours(2),
        'handed_off_at' => now()->subHour(),
    ]);

    $delivered = ParcelAssignment::create([
        'order_id' => $deliveredOrder->id,
        'logistics_company_id' => $company->id,
        'rider_profile_id' => $courier->id,
        'status' => ParcelAssignment::STATUS_HANDED_OFF,
        'received_by' => $seller->id,
        'received_at' => now()->subHours(5),
        'assigned_at' => now()->subHours(5),
        'handed_off_at' => now()->subHours(3),
    ]);

    actingAsDriver($courier);

    $response = $this->getJson('/api/driver/deliveries')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $statuses = collect($response->json('data'))->pluck('status', 'id');

    expect($statuses[$pickedUp->id])->toBe('picked_up');
    expect($statuses[$delivered->id])->toBe('delivered');
});

it('excludes parcels not yet dispatched to a rider and parcels assigned to a different rider', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $courier = makeCourier();
    $otherCourier = makeCourier();
    $company = makeLogisticsCompany();

    [$unassignedOrder] = makeOrder($buyer, $seller);
    [$otherRiderOrder] = makeOrder($buyer, $seller);

    ParcelAssignment::create([
        'order_id' => $unassignedOrder->id,
        'logistics_company_id' => $company->id,
        'rider_profile_id' => null,
        'status' => ParcelAssignment::STATUS_SORTED,
        'received_by' => $seller->id,
        'received_at' => now(),
    ]);

    ParcelAssignment::create([
        'order_id' => $otherRiderOrder->id,
        'logistics_company_id' => $company->id,
        'rider_profile_id' => $otherCourier->id,
        'status' => ParcelAssignment::STATUS_ASSIGNED,
        'received_by' => $seller->id,
        'received_at' => now(),
        'assigned_at' => now(),
    ]);

    actingAsDriver($courier);

    $this->getJson('/api/driver/deliveries')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
