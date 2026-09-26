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
        // Shift flag Profile::isAvailableForDelivery() reads — intake only
        // pre-fills the sole area rider when it's 'available'.
        $table->string('delivery_status')->default('unavailable');
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
        // status/account_status set explicitly: the real `profiles` table
        // (2026_08_18_000000 baseline) defaults both to 'pending', and
        // EnsureUserIsLogistics requires 'approved'/'active'.
        ['id' => '10000000-0000-0000-0000-000000000001', 'role' => 'logistics', 'status' => 'approved', 'account_status' => 'active', 'first_name' => 'Logistics', 'last_name' => 'Owner'],
        ['id' => '20000000-0000-0000-0000-000000000002', 'role' => 'courier', 'status' => 'approved', 'account_status' => 'active', 'first_name' => 'Rider', 'last_name' => 'One'],
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
    // On shift, so intake's "sole rider of the area" auto-fill applies.
    DB::table('courier_details')->insert([
        'profile_id' => '20000000-0000-0000-0000-000000000002',
        'delivery_status' => 'available',
    ]);
    // A barangay has at most one assigned rider now (see
    // LogisticsBarangayAssignment) — ParcelIntakeService's "auto-fill the
    // barangay's assigned rider when they're eligible" rule (see
    // it_receives_an_in_transit_parcel_and_automatically_matches_...)
    // applies directly off this one row.
    DB::table('logistics_barangay_assignments')->insert([
        'id' => '50000000-0000-0000-0000-000000000005',
        'logistics_company_id' => '30000000-0000-0000-0000-000000000003',
        'province_name' => 'Laguna',
        'municipality_name' => 'Santa Cruz',
        'barangay' => 'Poblacion',
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
    fakeApiTokens(fn () => '10000000-0000-0000-0000-000000000001');
});

it('receives an in-transit parcel and automatically matches its area and rider', function () {
    $this->withToken('valid-token')
        ->postJson('/api/logistics/parcel-assignments/receive', [
            'tracking_number' => 'NXM-10001',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'sorted')
        ->assertJsonPath('data.barangay_assignment.barangay', 'Poblacion')
        ->assertJsonPath('data.barangay_assignment.municipality_name', 'Santa Cruz')
        ->assertJsonPath('data.rider.first_name', 'Rider');
});

it('matches the barangay but leaves the rider empty when the assigned rider is off shift', function () {
    DB::table('courier_details')
        ->where('profile_id', '20000000-0000-0000-0000-000000000002')
        ->update(['delivery_status' => 'unavailable']);

    $this->withToken('valid-token')
        ->postJson('/api/logistics/parcel-assignments/receive', [
            'tracking_number' => 'NXM-10001',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'sorted')
        ->assertJsonPath('data.barangay_assignment.barangay', 'Poblacion')
        ->assertJsonPath('data.rider', null);
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
            'barangay_assignment_id' => '50000000-0000-0000-0000-000000000005',
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
