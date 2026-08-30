<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('profiles', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('role');
        $table->string('status')->default('approved');
        $table->string('account_status')->default('active');
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('email')->nullable();
        $table->string('contact_no')->nullable();
        $table->timestamps();
    });
    Schema::create('logistics_companies', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('owner_profile_id');
        $table->string('company_name');
        $table->string('status')->default('approved');
        $table->string('account_status')->default('active');
        $table->timestamps();
    });
    Schema::create('courier_details', function (Blueprint $table) {
        $table->string('profile_id')->primary();
        $table->string('vehicle')->nullable();
        $table->string('plate_number')->nullable();
        $table->string('logistics_company_id')->nullable();
    });
    Schema::create('courier_applications', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('courier_profile_id');
        $table->string('logistics_company_id');
        $table->string('status');
        $table->timestamp('applied_at')->nullable();
        $table->timestamps();
    });

    DB::table('profiles')->insert([
        ['id' => '10000000-0000-0000-0000-000000000001', 'role' => 'logistics', 'first_name' => 'Logistics', 'last_name' => 'Owner'],
        ['id' => '20000000-0000-0000-0000-000000000002', 'role' => 'courier', 'first_name' => 'Rider', 'last_name' => 'One'],
    ]);
    DB::table('logistics_companies')->insert([
        'id' => '30000000-0000-0000-0000-000000000003',
        'owner_profile_id' => '10000000-0000-0000-0000-000000000001',
        'company_name' => 'Luzon Logistics',
    ]);
    DB::table('courier_applications')->insert([
        'id' => '40000000-0000-0000-0000-000000000004',
        'courier_profile_id' => '20000000-0000-0000-0000-000000000002',
        'logistics_company_id' => '30000000-0000-0000-0000-000000000003',
        'status' => 'accepted',
        'applied_at' => now(),
    ]);
    DB::table('logistics_delivery_areas')->insert([
        'id' => '50000000-0000-0000-0000-000000000005',
        'logistics_company_id' => '30000000-0000-0000-0000-000000000003',
        'name' => 'Area A',
        'province_name' => 'Laguna',
        'municipality_name' => 'Santa Cruz',
        'rider_profile_id' => '20000000-0000-0000-0000-000000000002',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('orders')->insert([
        'id' => '60000000-0000-0000-0000-000000000006',
        'order_number' => 'SN-10001',
        'seller_id' => '70000000-0000-0000-0000-000000000007',
        'buyer_profile_id' => '80000000-0000-0000-0000-000000000008',
        'recipient_name' => 'Buyer One',
        'shipping_province_name' => 'Laguna',
        'shipping_municipality_name' => 'Santa Cruz',
        'shipping_barangay' => 'Poblacion',
        'status' => 'In Transit',
        'payment_status' => 'Paid',
        'subtotal' => 100,
        'shipping_fee' => 60,
        'tax' => 0,
        'discount' => 0,
        'total' => 160,
        'shipping_carrier' => 'Luzon Logistics',
        'tracking_number' => 'NXM-10001',
        'placed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Http::fake(['*' => Http::response(['id' => '10000000-0000-0000-0000-000000000001'])]);
});

it('receives an in-transit parcel and automatically matches its area and rider', function () {
    $this->withToken('valid-token')
        ->postJson('/api/logistics/parcel-assignments/receive', [
            'tracking_number' => 'NXM-10001',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'sorted')
        ->assertJsonPath('data.delivery_area.name', 'Area A')
        ->assertJsonPath('data.rider.first_name', 'Rider');
});

it('assigns a sorted parcel and confirms rider handoff', function () {
    $receive = $this->withToken('valid-token')
        ->postJson('/api/logistics/parcel-assignments/receive', [
            'tracking_number' => 'SN-10001',
        ])
        ->assertCreated();

    $assignmentId = $receive->json('data.id');

    $this->withToken('valid-token')
        ->putJson("/api/logistics/parcel-assignments/{$assignmentId}/assign", [
            'delivery_area_id' => '50000000-0000-0000-0000-000000000005',
            'rider_profile_id' => '20000000-0000-0000-0000-000000000002',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'assigned');

    $this->withToken('valid-token')
        ->putJson("/api/logistics/parcel-assignments/{$assignmentId}/handoff")
        ->assertOk()
        ->assertJsonPath('data.status', 'handed_off');
});

it('rejects a parcel assigned to another carrier', function () {
    DB::table('orders')
        ->where('id', '60000000-0000-0000-0000-000000000006')
        ->update(['shipping_carrier' => 'Another Carrier']);

    $this->withToken('valid-token')
        ->postJson('/api/logistics/parcel-assignments/receive', [
            'tracking_number' => 'NXM-10001',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This parcel is assigned to a different logistics company.');
});
