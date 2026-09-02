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
        [
            'id' => '10000000-0000-0000-0000-000000000001',
            'role' => 'logistics',
            'first_name' => 'Logistics',
            'last_name' => 'Owner',
        ],
        [
            'id' => '20000000-0000-0000-0000-000000000002',
            'role' => 'courier',
            'first_name' => 'Rider',
            'last_name' => 'One',
        ],
        [
            'id' => '30000000-0000-0000-0000-000000000003',
            'role' => 'courier',
            'first_name' => 'Other',
            'last_name' => 'Rider',
        ],
    ]);

    DB::table('logistics_companies')->insert([
        'id' => '40000000-0000-0000-0000-000000000004',
        'owner_profile_id' => '10000000-0000-0000-0000-000000000001',
        'company_name' => 'Luzon Logistics',
    ]);

    DB::table('courier_applications')->insert([
        [
            'id' => '50000000-0000-0000-0000-000000000005',
            'courier_profile_id' => '20000000-0000-0000-0000-000000000002',
            'logistics_company_id' => '40000000-0000-0000-0000-000000000004',
            'status' => 'accepted',
            'applied_at' => now(),
        ],
        [
            'id' => '60000000-0000-0000-0000-000000000006',
            'courier_profile_id' => '30000000-0000-0000-0000-000000000003',
            'logistics_company_id' => 'another-company',
            'status' => 'accepted',
            'applied_at' => now(),
        ],
    ]);

    Http::fake([
        '*' => Http::response(['id' => '10000000-0000-0000-0000-000000000001']),
    ]);
});

it('creates a delivery area assigned to an accepted rider', function () {
    $response = $this->withToken('valid-token')
        ->postJson('/api/logistics/delivery-areas', [
            'name' => 'Area A',
            'province_name' => 'Laguna',
            'municipality_name' => 'Santa Cruz',
            'rider_profile_id' => '20000000-0000-0000-0000-000000000002',
            'is_active' => true,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Area A')
        ->assertJsonPath('data.rider.first_name', 'Rider');

    $this->assertDatabaseHas('logistics_delivery_areas', [
        'logistics_company_id' => '40000000-0000-0000-0000-000000000004',
        'name' => 'Area A',
        'rider_profile_id' => '20000000-0000-0000-0000-000000000002',
    ]);
});

it('rejects a rider accepted by another logistics company', function () {
    $this->withToken('valid-token')
        ->postJson('/api/logistics/delivery-areas', [
            'name' => 'Area B',
            'province_name' => 'Laguna',
            'municipality_name' => 'Pagsanjan',
            'rider_profile_id' => '30000000-0000-0000-0000-000000000003',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rider_profile_id');
});

it('returns only the owners areas and accepted riders', function () {
    DB::table('logistics_delivery_areas')->insert([
        'id' => '70000000-0000-0000-0000-000000000007',
        'logistics_company_id' => '40000000-0000-0000-0000-000000000004',
        'name' => 'Area C',
        'province_name' => 'Laguna',
        'municipality_name' => 'Los Baños',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withToken('valid-token')
        ->getJson('/api/logistics/delivery-areas')
        ->assertOk()
        ->assertJsonCount(1, 'areas')
        ->assertJsonCount(1, 'riders')
        ->assertJsonPath('riders.0.id', '20000000-0000-0000-0000-000000000002');
});
