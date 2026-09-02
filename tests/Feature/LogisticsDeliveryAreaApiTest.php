<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // A real `profiles` table already exists by this point (see the
    // 2026_08_18_000000 baseline migration) — only fall back to this
    // ad-hoc one if it's somehow missing.
    if (! Schema::hasTable('profiles')) {
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
    }

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

    // Supabase-managed table, no Laravel migration — riders.address is
    // eager-loaded on every area response now, so this just needs to
    // exist (see App\Models\Profile::address / Address::full_address).
    if (! Schema::hasTable('addresses')) {
        Schema::create('addresses', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('owner_kind');
            $table->string('profile_id')->nullable();
            $table->string('house_no')->nullable();
            $table->string('street')->nullable();
            $table->string('barangay')->nullable();
            $table->string('municipality_name')->nullable();
            $table->string('province_name')->nullable();
            $table->string('region_name')->nullable();
        });
    }

    DB::table('profiles')->insert([
        // status/account_status set explicitly: the real `profiles` table
        // (2026_08_18_000000 baseline) defaults both to 'pending', and
        // EnsureUserIsLogistics requires 'approved'/'active'.
        [
            'id' => '10000000-0000-0000-0000-000000000001',
            'role' => 'logistics',
            'status' => 'approved',
            'account_status' => 'active',
            'first_name' => 'Logistics',
            'last_name' => 'Owner',
        ],
        [
            'id' => '20000000-0000-0000-0000-000000000002',
            'role' => 'courier',
            'status' => 'approved',
            'account_status' => 'active',
            'first_name' => 'Rider',
            'last_name' => 'One',
        ],
        [
            'id' => '30000000-0000-0000-0000-000000000003',
            'role' => 'courier',
            'status' => 'approved',
            'account_status' => 'active',
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

    DB::table('addresses')->insert([
        'id' => '90000000-0000-0000-0000-000000000009',
        'owner_kind' => 'profile',
        'profile_id' => '20000000-0000-0000-0000-000000000002',
        'municipality_name' => 'Santa Cruz',
        'province_name' => 'Laguna',
    ]);

    Http::fake([
        '*' => Http::response(['id' => '10000000-0000-0000-0000-000000000001']),
    ]);
});

it('creates a delivery area with no rider assignment required', function () {
    $response = $this->withToken('valid-token')
        ->postJson('/api/logistics/delivery-areas', [
            'name' => 'Area A',
            'province_name' => 'Laguna',
            'municipality_name' => 'Santa Cruz',
            'is_active' => true,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Area A')
        ->assertJsonPath('data.riders', []);

    $this->assertDatabaseHas('logistics_delivery_areas', [
        'logistics_company_id' => '40000000-0000-0000-0000-000000000004',
        'name' => 'Area A',
    ]);
});

it('appoints an accepted rider to an area', function () {
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
        ->postJson('/api/logistics/delivery-areas/70000000-0000-0000-0000-000000000007/riders', [
            'rider_profile_id' => '20000000-0000-0000-0000-000000000002',
        ])
        ->assertOk()
        ->assertJsonCount(1, 'data.riders')
        ->assertJsonPath('data.riders.0.first_name', 'Rider')
        ->assertJsonPath('data.riders.0.address', 'Santa Cruz, Laguna');

    $this->assertDatabaseHas('logistics_delivery_area_riders', [
        'delivery_area_id' => '70000000-0000-0000-0000-000000000007',
        'rider_profile_id' => '20000000-0000-0000-0000-000000000002',
    ]);
});

it('rejects a rider accepted by another logistics company', function () {
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
        ->postJson('/api/logistics/delivery-areas/70000000-0000-0000-0000-000000000007/riders', [
            'rider_profile_id' => '30000000-0000-0000-0000-000000000003',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rider_profile_id');
});

it('removes an appointed rider from an area', function () {
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
    DB::table('logistics_delivery_area_riders')->insert([
        'delivery_area_id' => '70000000-0000-0000-0000-000000000007',
        'rider_profile_id' => '20000000-0000-0000-0000-000000000002',
        'created_at' => now(),
    ]);

    $this->withToken('valid-token')
        ->deleteJson('/api/logistics/delivery-areas/70000000-0000-0000-0000-000000000007/riders/20000000-0000-0000-0000-000000000002')
        ->assertOk()
        ->assertJsonPath('data.riders', []);

    $this->assertDatabaseMissing('logistics_delivery_area_riders', [
        'delivery_area_id' => '70000000-0000-0000-0000-000000000007',
        'rider_profile_id' => '20000000-0000-0000-0000-000000000002',
    ]);
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

describe('available riders (Add driver panel)', function () {
    beforeEach(function () {
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

        // 6 more accepted riders (7 total with Rider One from the outer
        // beforeEach) so pagination (5/page) actually has a second page.
        for ($i = 1; $i <= 6; $i++) {
            $profileId = sprintf('a000000%d-0000-0000-0000-00000000000%d', $i, $i);
            $applicationId = sprintf('b000000%d-0000-0000-0000-00000000000%d', $i, $i);

            DB::table('profiles')->insert([
                'id' => $profileId,
                'role' => 'courier',
                'status' => 'approved',
                'account_status' => 'active',
                'first_name' => 'Extra',
                'last_name' => "Rider {$i}",
            ]);
            DB::table('courier_applications')->insert([
                'id' => $applicationId,
                'courier_profile_id' => $profileId,
                'logistics_company_id' => '40000000-0000-0000-0000-000000000004',
                'status' => 'accepted',
                'applied_at' => now(),
            ]);
        }
    });

    it('paginates 5 riders per page', function () {
        $this->withToken('valid-token')
            ->getJson('/api/logistics/delivery-areas/70000000-0000-0000-0000-000000000007/available-riders')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 7);

        $this->withToken('valid-token')
            ->getJson('/api/logistics/delivery-areas/70000000-0000-0000-0000-000000000007/available-riders?page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2);
    });

    it('excludes riders already appointed to the area', function () {
        DB::table('logistics_delivery_area_riders')->insert([
            'delivery_area_id' => '70000000-0000-0000-0000-000000000007',
            'rider_profile_id' => '20000000-0000-0000-0000-000000000002',
            'created_at' => now(),
        ]);

        $ids = collect(
            $this->withToken('valid-token')
                ->getJson('/api/logistics/delivery-areas/70000000-0000-0000-0000-000000000007/available-riders')
                ->assertOk()
                ->json('data'),
        )->pluck('id');

        expect($ids)->not->toContain('20000000-0000-0000-0000-000000000002');
    });

    it('searches riders by name and includes their address', function () {
        $this->withToken('valid-token')
            ->getJson('/api/logistics/delivery-areas/70000000-0000-0000-0000-000000000007/available-riders?search=Rider+One')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', '20000000-0000-0000-0000-000000000002')
            ->assertJsonPath('data.0.address', 'Santa Cruz, Laguna');
    });
});
