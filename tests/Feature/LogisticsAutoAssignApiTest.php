<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/**
 * "Auto assign" on the Parcel sorting page —
 * App\Services\ParcelAutoAssignService.
 *
 * Covers the rules that make the feature safe to press: the area match is
 * exact (never a nearby area), riders rotate evenly, riders who are off
 * shift or at quota are passed over, and a parcel that can't be routed is
 * left alone rather than forced somewhere wrong.
 */
const AA_OWNER = '10000000-0000-0000-0000-000000000001';
const AA_COMPANY = '30000000-0000-0000-0000-000000000003';
const AA_AREA = '50000000-0000-0000-0000-000000000005';

const AA_RIDER_A = '20000000-0000-0000-0000-00000000000a';
const AA_RIDER_B = '20000000-0000-0000-0000-00000000000b';
const AA_RIDER_C = '20000000-0000-0000-0000-00000000000c';

beforeEach(function () {
    Schema::create('logistics_companies', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('owner_profile_id');
        $table->string('company_name');
        $table->string('status')->default('approved');
        $table->string('account_status')->default('active');
        $table->string('region')->nullable();
        $table->timestamps();
    });
    Schema::create('courier_details', function (Blueprint $table) {
        $table->string('profile_id')->primary();
        $table->string('vehicle')->nullable();
        $table->string('plate_number')->nullable();
        $table->string('logistics_company_id')->nullable();
        // The "Go online" shift flag the whole feature hinges on — see
        // 2026_09_07_000000_add_delivery_status_to_rider_details_tables.
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
        ['id' => AA_OWNER, 'role' => 'logistics', 'status' => 'approved', 'account_status' => 'active', 'first_name' => 'Logistics', 'last_name' => 'Owner'],
        ['id' => AA_RIDER_A, 'role' => 'courier', 'status' => 'approved', 'account_status' => 'active', 'first_name' => 'Rider', 'last_name' => 'A'],
        ['id' => AA_RIDER_B, 'role' => 'courier', 'status' => 'approved', 'account_status' => 'active', 'first_name' => 'Rider', 'last_name' => 'B'],
        ['id' => AA_RIDER_C, 'role' => 'courier', 'status' => 'approved', 'account_status' => 'active', 'first_name' => 'Rider', 'last_name' => 'C'],
    ]);
    DB::table('logistics_companies')->insert([
        'id' => AA_COMPANY,
        'owner_profile_id' => AA_OWNER,
        'company_name' => 'Luzon Logistics',
    ]);

    // Area A covers exactly Laguna -> San Pablo City.
    DB::table('logistics_delivery_areas')->insert([
        'id' => AA_AREA,
        'logistics_company_id' => AA_COMPANY,
        'name' => 'Area A',
        'province_name' => 'Laguna',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('logistics_delivery_area_municipalities')->insert([
        'id' => '51000000-0000-0000-0000-000000000005',
        'delivery_area_id' => AA_AREA,
        'municipality_name' => 'San Pablo City',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake(['*' => Http::response(['id' => AA_OWNER])]);
});

/**
 * An accepted rider of the company, appointed to Area A, on shift unless
 * told otherwise. `$appointedAt` fixes the rotation order.
 */
function appointAutoAssignRider(string $id, string $status = 'available', int $appointedAt = 0): void
{
    DB::table('courier_applications')->insert([
        'id' => 'a'.substr($id, 1),
        'courier_profile_id' => $id,
        'logistics_company_id' => AA_COMPANY,
        'status' => 'accepted',
        'applied_at' => now(),
    ]);
    DB::table('courier_details')->insert([
        'profile_id' => $id,
        'vehicle' => 'Motorcycle',
        'delivery_status' => $status,
    ]);
    DB::table('logistics_delivery_area_riders')->insert([
        'delivery_area_id' => AA_AREA,
        'rider_profile_id' => $id,
        'created_at' => now()->addSeconds($appointedAt),
    ]);
}

/** A parcel on the sorting desk, waiting for a rider. */
function queuedParcelFor(int $n, string $municipality = 'San Pablo City', string $province = 'Laguna'): string
{
    $orderId = sprintf('60000000-0000-0000-0000-%012d', $n);
    $parcelId = sprintf('90000000-0000-0000-0000-%012d', $n);

    DB::table('orders')->insert([
        'id' => $orderId,
        'order_number' => "SN-1000{$n}",
        'seller_id' => '70000000-0000-0000-0000-000000000007',
        'buyer_profile_id' => '80000000-0000-0000-0000-000000000008',
        'recipient_name' => "Buyer {$n}",
        'shipping_province_name' => $province,
        'shipping_municipality_name' => $municipality,
        'status' => 'In Transit',
        'payment_status' => 'Paid',
        'subtotal' => 100, 'shipping_fee' => 60, 'tax' => 0, 'discount' => 0, 'total' => 160,
        'shipping_carrier' => 'Luzon Logistics',
        'tracking_number' => "NXM-1000{$n}",
        'placed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('parcel_assignments')->insert([
        'id' => $parcelId,
        'order_id' => $orderId,
        'logistics_company_id' => AA_COMPANY,
        'status' => 'received',
        'received_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $parcelId;
}

function pressAutoAssign(string $parcelId): TestResponse
{
    return test()->withToken('valid-token')
        ->putJson("/api/logistics/parcel-assignments/{$parcelId}/auto-assign");
}

it('matches the area by province and municipality, then assigns an available rider', function () {
    appointAutoAssignRider(AA_RIDER_A);

    pressAutoAssign(queuedParcelFor(1))
        ->assertOk()
        ->assertJsonPath('outcome', 'assigned')
        ->assertJsonPath('data.status', 'assigned')
        ->assertJsonPath('data.delivery_area.name', 'Area A')
        ->assertJsonPath('data.rider.last_name', 'A');
});

it('rotates riders round-robin and continues the rotation across sweeps', function () {
    appointAutoAssignRider(AA_RIDER_A, 'available', 0);
    appointAutoAssignRider(AA_RIDER_B, 'available', 1);
    appointAutoAssignRider(AA_RIDER_C, 'available', 2);

    $assigned = collect(range(1, 4))->map(
        fn (int $n): string => pressAutoAssign(queuedParcelFor($n))->json('data.rider.last_name')
    );

    // Parcel 4 wraps back to the first rider — the cursor persisted on
    // the area row is what carries the rotation between calls.
    expect($assigned->all())->toBe(['A', 'B', 'C', 'A']);
});

it('skips riders who are off shift', function () {
    appointAutoAssignRider(AA_RIDER_A, 'available', 0);
    appointAutoAssignRider(AA_RIDER_B, 'unavailable', 1);
    appointAutoAssignRider(AA_RIDER_C, 'available', 2);

    $assigned = collect(range(1, 4))->map(
        fn (int $n): string => pressAutoAssign(queuedParcelFor($n))->json('data.rider.last_name')
    );

    expect($assigned->all())->toBe(['A', 'C', 'A', 'C']);
});

it('skips riders who are already at their parcel quota', function () {
    appointAutoAssignRider(AA_RIDER_A, 'available', 0);
    appointAutoAssignRider(AA_RIDER_B, 'available', 1);

    // Rider A is already holding a full load (COURIER_QUOTA = 20).
    collect(range(101, 120))->each(function (int $n): void {
        DB::table('parcel_assignments')->where('id', queuedParcelFor($n))->update([
            'rider_profile_id' => AA_RIDER_A,
            'status' => 'assigned',
        ]);
    });

    pressAutoAssign(queuedParcelFor(1))->assertJsonPath('data.rider.last_name', 'B');
});

it('leaves a parcel unassigned when no area covers its address', function () {
    appointAutoAssignRider(AA_RIDER_A);

    // The company only covers Laguna -> San Pablo City. Calamba is in the
    // same province, which must NOT be treated as close enough.
    $response = pressAutoAssign(queuedParcelFor(1, 'Calamba City'))
        ->assertOk()
        ->assertJsonPath('outcome', 'no_area')
        ->assertJsonPath('data.status', 'received')
        ->assertJsonPath('data.delivery_area', null)
        ->assertJsonPath('data.rider', null);

    expect($response->json('message'))->toContain('No logistics area is available');
});

it('leaves a parcel unassigned when the address has no municipality on file', function () {
    appointAutoAssignRider(AA_RIDER_A);

    pressAutoAssign(queuedParcelFor(1, ''))
        ->assertJsonPath('outcome', 'no_area')
        ->assertJsonPath('data.rider', null);
});

it('keeps the matched area but leaves the rider empty when everyone is off shift', function () {
    appointAutoAssignRider(AA_RIDER_A, 'unavailable', 0);
    appointAutoAssignRider(AA_RIDER_B, 'unavailable', 1);

    pressAutoAssign(queuedParcelFor(1))
        ->assertOk()
        ->assertJsonPath('outcome', 'no_rider')
        ->assertJsonPath('data.delivery_area.name', 'Area A')
        ->assertJsonPath('data.rider', null);
});

it('handles an area that has no riders appointed to it at all', function () {
    pressAutoAssign(queuedParcelFor(1))
        ->assertOk()
        ->assertJsonPath('outcome', 'no_rider')
        ->assertJsonPath('data.delivery_area.name', 'Area A')
        ->assertJsonPath('data.rider', null);
});

it('will not reassign a parcel that already has a rider', function () {
    appointAutoAssignRider(AA_RIDER_A, 'available', 0);
    appointAutoAssignRider(AA_RIDER_B, 'available', 1);

    $parcelId = queuedParcelFor(1);

    pressAutoAssign($parcelId)->assertJsonPath('data.rider.last_name', 'A');

    // Pressing Auto assign again must not hand the same parcel to the
    // next rider in the rotation.
    pressAutoAssign($parcelId)
        ->assertJsonPath('outcome', 'skipped')
        ->assertJsonPath('data.rider.last_name', 'A');
});

it('ignores riders appointed to the area but no longer accepted by the company', function () {
    appointAutoAssignRider(AA_RIDER_A, 'available', 0);
    appointAutoAssignRider(AA_RIDER_B, 'available', 1);

    DB::table('courier_applications')
        ->where('courier_profile_id', AA_RIDER_A)
        ->update(['status' => 'withdrawn']);

    pressAutoAssign(queuedParcelFor(1))->assertJsonPath('data.rider.last_name', 'B');
    pressAutoAssign(queuedParcelFor(2))->assertJsonPath('data.rider.last_name', 'B');
});

it('assigns a delivery rider to a picked-up parcel without knocking it back to assigned', function () {
    appointAutoAssignRider(AA_RIDER_A);

    $parcelId = queuedParcelFor(1);
    // The pickup courier has collected it and been released — this is the
    // second leg, where a delivery rider is chosen.
    DB::table('parcel_assignments')->where('id', $parcelId)->update([
        'status' => 'handed_off',
        'handed_off_at' => now(),
    ]);

    pressAutoAssign($parcelId)
        ->assertJsonPath('outcome', 'assigned')
        ->assertJsonPath('data.status', 'handed_off')
        ->assertJsonPath('data.rider.last_name', 'A');
});

it('refuses a parcel belonging to another logistics company', function () {
    appointAutoAssignRider(AA_RIDER_A);

    $parcelId = queuedParcelFor(1);
    DB::table('parcel_assignments')->where('id', $parcelId)->update([
        'logistics_company_id' => '30000000-0000-0000-0000-0000000000ff',
    ]);

    pressAutoAssign($parcelId)->assertNotFound();
});
