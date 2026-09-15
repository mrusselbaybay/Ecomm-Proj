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
    // eager-loaded on every assignment response now, so this just needs
    // to exist (see App\Models\Profile::address / Address::full_address).
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

it('creates a barangay assignment with no rider required', function () {
    $response = $this->withToken('valid-token')
        ->postJson('/api/logistics/barangay-assignments', [
            'province_name' => 'Laguna',
            'municipality_name' => 'Santa Cruz',
            'barangay' => 'Poblacion',
            'is_active' => true,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.barangay', 'Poblacion')
        ->assertJsonPath('data.rider', null);

    $this->assertDatabaseHas('logistics_barangay_assignments', [
        'logistics_company_id' => '40000000-0000-0000-0000-000000000004',
        'municipality_name' => 'Santa Cruz',
        'barangay' => 'Poblacion',
    ]);
});

it('rejects a second assignment for a barangay the company already covers', function () {
    DB::table('logistics_barangay_assignments')->insert([
        'id' => '70000000-0000-0000-0000-000000000007',
        'logistics_company_id' => '40000000-0000-0000-0000-000000000004',
        'province_name' => 'Laguna',
        'municipality_name' => 'Santa Cruz',
        'barangay' => 'Poblacion',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->withToken('valid-token')
        ->postJson('/api/logistics/barangay-assignments', [
            'province_name' => 'Laguna',
            // Different casing — still the same barangay to a person.
            'municipality_name' => 'santa cruz',
            'barangay' => 'poblacion',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('barangay');

    expect($response->json('errors.barangay.0'))->toContain('already has a courier assignment');
    $this->assertDatabaseCount('logistics_barangay_assignments', 1);
});

it('lets an assignment update its own municipality and barangay', function () {
    DB::table('logistics_barangay_assignments')->insert([
        'id' => '70000000-0000-0000-0000-000000000007',
        'logistics_company_id' => '40000000-0000-0000-0000-000000000004',
        'province_name' => 'Laguna',
        'municipality_name' => 'Santa Cruz',
        'barangay' => 'Poblacion',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withToken('valid-token')
        ->putJson('/api/logistics/barangay-assignments/70000000-0000-0000-0000-000000000007', [
            'barangay' => 'Bubukal',
        ])
        ->assertOk()
        ->assertJsonPath('data.barangay', 'Bubukal')
        ->assertJsonPath('data.municipality_name', 'Santa Cruz');
});

it('assigns an accepted rider to a barangay assignment', function () {
    DB::table('logistics_barangay_assignments')->insert([
        'id' => '70000000-0000-0000-0000-000000000007',
        'logistics_company_id' => '40000000-0000-0000-0000-000000000004',
        'province_name' => 'Laguna',
        'municipality_name' => 'Los Baños',
        'barangay' => 'Batong Malake',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withToken('valid-token')
        ->putJson('/api/logistics/barangay-assignments/70000000-0000-0000-0000-000000000007/rider', [
            'rider_profile_id' => '20000000-0000-0000-0000-000000000002',
        ])
        ->assertOk()
        ->assertJsonPath('data.rider.first_name', 'Rider')
        ->assertJsonPath('data.rider.address', 'Santa Cruz, Laguna');

    $this->assertDatabaseHas('logistics_barangay_assignments', [
        'id' => '70000000-0000-0000-0000-000000000007',
        'rider_profile_id' => '20000000-0000-0000-0000-000000000002',
    ]);
});

it('allows the same rider to be assigned to more than one barangay', function () {
    DB::table('logistics_barangay_assignments')->insert([
        [
            'id' => '70000000-0000-0000-0000-000000000007',
            'logistics_company_id' => '40000000-0000-0000-0000-000000000004',
            'province_name' => 'Laguna',
            'municipality_name' => 'Los Baños',
            'barangay' => 'Batong Malake',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => '70000000-0000-0000-0000-000000000008',
            'logistics_company_id' => '40000000-0000-0000-0000-000000000004',
            'province_name' => 'Laguna',
            'municipality_name' => 'Los Baños',
            'barangay' => 'Anos',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    DB::table('logistics_barangay_assignments')
        ->where('id', '70000000-0000-0000-0000-000000000007')
        ->update(['rider_profile_id' => '20000000-0000-0000-0000-000000000002']);

    // Unlike the old one-area-per-rider rule, appointing the same rider to
    // a second barangay is allowed.
    $this->withToken('valid-token')
        ->putJson('/api/logistics/barangay-assignments/70000000-0000-0000-0000-000000000008/rider', [
            'rider_profile_id' => '20000000-0000-0000-0000-000000000002',
        ])
        ->assertOk()
        ->assertJsonPath('data.rider.id', '20000000-0000-0000-0000-000000000002');

    $this->assertDatabaseHas('logistics_barangay_assignments', [
        'id' => '70000000-0000-0000-0000-000000000007',
        'rider_profile_id' => '20000000-0000-0000-0000-000000000002',
    ]);
    $this->assertDatabaseHas('logistics_barangay_assignments', [
        'id' => '70000000-0000-0000-0000-000000000008',
        'rider_profile_id' => '20000000-0000-0000-0000-000000000002',
    ]);
});

it('rejects a rider accepted by another logistics company', function () {
    DB::table('logistics_barangay_assignments')->insert([
        'id' => '70000000-0000-0000-0000-000000000007',
        'logistics_company_id' => '40000000-0000-0000-0000-000000000004',
        'province_name' => 'Laguna',
        'municipality_name' => 'Los Baños',
        'barangay' => 'Batong Malake',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withToken('valid-token')
        ->putJson('/api/logistics/barangay-assignments/70000000-0000-0000-0000-000000000007/rider', [
            'rider_profile_id' => '30000000-0000-0000-0000-000000000003',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rider_profile_id');
});

it('clears the appointed rider from a barangay assignment', function () {
    DB::table('logistics_barangay_assignments')->insert([
        'id' => '70000000-0000-0000-0000-000000000007',
        'logistics_company_id' => '40000000-0000-0000-0000-000000000004',
        'province_name' => 'Laguna',
        'municipality_name' => 'Los Baños',
        'barangay' => 'Batong Malake',
        'rider_profile_id' => '20000000-0000-0000-0000-000000000002',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withToken('valid-token')
        ->putJson('/api/logistics/barangay-assignments/70000000-0000-0000-0000-000000000007/rider', [
            'rider_profile_id' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.rider', null);

    $this->assertDatabaseHas('logistics_barangay_assignments', [
        'id' => '70000000-0000-0000-0000-000000000007',
        'rider_profile_id' => null,
    ]);
});

it('returns only the owners barangay assignments and accepted riders', function () {
    DB::table('logistics_barangay_assignments')->insert([
        'id' => '70000000-0000-0000-0000-000000000007',
        'logistics_company_id' => '40000000-0000-0000-0000-000000000004',
        'province_name' => 'Laguna',
        'municipality_name' => 'Los Baños',
        'barangay' => 'Batong Malake',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withToken('valid-token')
        ->getJson('/api/logistics/barangay-assignments')
        ->assertOk()
        ->assertJsonCount(1, 'assignments')
        ->assertJsonCount(1, 'riders')
        ->assertJsonPath('riders.0.id', '20000000-0000-0000-0000-000000000002');
});

describe('available riders (Assign courier panel)', function () {
    beforeEach(function () {
        DB::table('logistics_barangay_assignments')->insert([
            'id' => '70000000-0000-0000-0000-000000000007',
            'logistics_company_id' => '40000000-0000-0000-0000-000000000004',
            'province_name' => 'Laguna',
            'municipality_name' => 'Los Baños',
            'barangay' => 'Batong Malake',
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
            ->getJson('/api/logistics/barangay-assignments/70000000-0000-0000-0000-000000000007/available-riders')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 7);

        $this->withToken('valid-token')
            ->getJson('/api/logistics/barangay-assignments/70000000-0000-0000-0000-000000000007/available-riders?page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2);
    });

    it('still lists a rider already appointed to this barangay — a rider can cover more than one', function () {
        DB::table('logistics_barangay_assignments')
            ->where('id', '70000000-0000-0000-0000-000000000007')
            ->update(['rider_profile_id' => '20000000-0000-0000-0000-000000000002']);

        // Searched rather than paged through — "Rider One" sorts after the
        // 6 "Extra Rider" fixtures alphabetically, so an unfiltered page 1
        // wouldn't include them regardless of the exclusion rule this test
        // is actually checking.
        $ids = collect(
            $this->withToken('valid-token')
                ->getJson('/api/logistics/barangay-assignments/70000000-0000-0000-0000-000000000007/available-riders?search=Rider+One')
                ->assertOk()
                ->json('data'),
        )->pluck('id');

        expect($ids)->toContain('20000000-0000-0000-0000-000000000002');
    });

    it('searches riders by name and includes their address', function () {
        $this->withToken('valid-token')
            ->getJson('/api/logistics/barangay-assignments/70000000-0000-0000-0000-000000000007/available-riders?search=Rider+One')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', '20000000-0000-0000-0000-000000000002')
            ->assertJsonPath('data.0.address', 'Santa Cruz, Laguna');
    });
});
