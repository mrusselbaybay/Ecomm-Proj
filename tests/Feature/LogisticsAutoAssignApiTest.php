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
 * Covers the rules that make the feature safe to press: a barangay match is
 * exact (never a nearby barangay), a barangay's own assigned rider is tried
 * first, everything else falls back to a company-wide round-robin pool,
 * riders who are off shift / at quota / not accepted / driving the wrong
 * vehicle for a transfer parcel are passed over, and a parcel that can't be
 * routed anywhere is left alone rather than forced somewhere wrong.
 */
const AA_OWNER = '10000000-0000-0000-0000-000000000001';
const AA_COMPANY = '30000000-0000-0000-0000-000000000003';
const AA_ASSIGNMENT = '50000000-0000-0000-0000-000000000005';
const AA_MUNICIPALITY = 'San Pablo City';
const AA_PROVINCE = 'Laguna';
const AA_BARANGAY = 'Barangay I';

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
        $table->uuid('last_auto_assigned_rider_profile_id')->nullable();
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

    // One barangay covered: Laguna -> San Pablo City -> Barangay I. No
    // rider assigned to it by default — individual tests opt in via
    // appointBarangayRider().
    DB::table('logistics_barangay_assignments')->insert([
        'id' => AA_ASSIGNMENT,
        'logistics_company_id' => AA_COMPANY,
        'province_name' => AA_PROVINCE,
        'municipality_name' => AA_MUNICIPALITY,
        'barangay' => AA_BARANGAY,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake(['*' => Http::response(['id' => AA_OWNER])]);
});

/** Accepts a rider into the company roster, on shift unless told otherwise. */
function acceptAutoAssignRider(string $id, string $status = 'available', string $vehicle = 'Motorcycle'): void
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
        'vehicle' => $vehicle,
        'delivery_status' => $status,
    ]);
}

/** Directly appoints a rider to the one covered barangay (AA_ASSIGNMENT). */
function appointBarangayRider(string $id, string $status = 'available', string $vehicle = 'Motorcycle'): void
{
    acceptAutoAssignRider($id, $status, $vehicle);

    DB::table('logistics_barangay_assignments')
        ->where('id', AA_ASSIGNMENT)
        ->update(['rider_profile_id' => $id]);
}

/**
 * A parcel on the sorting desk, waiting for a rider. Pickup defaults to the
 * same municipality/province/region as delivery, so no transfer trigger
 * fires unless a test overrides one of the pickup_* fields.
 */
/**
 * A stand-in for App\Services\TransferTriggerService — these test parcels
 * are inserted straight into the database rather than going through
 * ParcelIntakeService::intake(), so is_transfer/transfer_trigger/
 * required_vehicle_type (normally computed once at intake) have to be
 * worked out here instead, the same region > province > municipality
 * precedence the real service uses.
 *
 * @return array{is_transfer: bool, trigger: ?string, required_vehicle_type: ?string}
 */
function computeTransferTrigger(array $order): array
{
    $differs = fn (?string $a, ?string $b): bool => filled($a) && filled($b)
        && mb_strtolower(trim($a)) !== mb_strtolower(trim($b));

    if ($differs($order['pickup_region_name'] ?? null, $order['shipping_region_name'] ?? null)) {
        return ['is_transfer' => true, 'trigger' => 'region', 'required_vehicle_type' => 'van_or_truck'];
    }

    if ($differs($order['pickup_province_name'] ?? null, $order['shipping_province_name'] ?? null)) {
        return ['is_transfer' => false, 'trigger' => 'province', 'required_vehicle_type' => 'van_or_truck'];
    }

    if ($differs($order['pickup_municipality_name'] ?? null, $order['shipping_municipality_name'] ?? null)) {
        return ['is_transfer' => false, 'trigger' => 'municipality', 'required_vehicle_type' => 'car'];
    }

    return ['is_transfer' => false, 'trigger' => null, 'required_vehicle_type' => null];
}

function queuedParcelFor(
    int $n,
    string $municipality = AA_MUNICIPALITY,
    string $province = AA_PROVINCE,
    string $barangay = AA_BARANGAY,
    array $overrides = [],
): string {
    $orderId = sprintf('60000000-0000-0000-0000-%012d', $n);
    $parcelId = sprintf('90000000-0000-0000-0000-%012d', $n);

    $order = array_merge([
        'id' => $orderId,
        'order_number' => "SN-1000{$n}",
        'seller_id' => '70000000-0000-0000-0000-000000000007',
        'buyer_profile_id' => '80000000-0000-0000-0000-000000000008',
        'recipient_name' => "Buyer {$n}",
        'shipping_region_name' => 'Luzon',
        'shipping_province_name' => $province,
        'shipping_municipality_name' => $municipality,
        'shipping_barangay' => $barangay,
        'pickup_region_name' => 'Luzon',
        'pickup_province_name' => $province,
        'pickup_municipality_name' => $municipality,
        'pickup_barangay' => $barangay,
        'status' => 'In Transit',
        'payment_status' => 'Paid',
        'subtotal' => 100, 'shipping_fee' => 60, 'tax' => 0, 'discount' => 0, 'total' => 160,
        'shipping_carrier' => 'Luzon Logistics',
        'tracking_number' => "NXM-1000{$n}",
        'placed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    DB::table('orders')->insert($order);

    $trigger = computeTransferTrigger($order);

    DB::table('parcel_assignments')->insert([
        'id' => $parcelId,
        'order_id' => $orderId,
        'logistics_company_id' => AA_COMPANY,
        'status' => 'received',
        'is_transfer' => $trigger['is_transfer'],
        'transfer_trigger' => $trigger['trigger'],
        'required_vehicle_type' => $trigger['required_vehicle_type'],
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

it('routes straight to the barangay\'s own assigned rider without touching the pool', function () {
    appointBarangayRider(AA_RIDER_A);
    // In the pool too, but never picked — the direct match wins.
    acceptAutoAssignRider(AA_RIDER_B);

    foreach (range(1, 3) as $n) {
        pressAutoAssign(queuedParcelFor($n))
            ->assertOk()
            ->assertJsonPath('outcome', 'assigned')
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.barangay_assignment.barangay', AA_BARANGAY)
            ->assertJsonPath('data.rider.last_name', 'A');
    }
});

it('falls back to the company-wide pool and rotates round-robin across sweeps', function () {
    acceptAutoAssignRider(AA_RIDER_A);
    acceptAutoAssignRider(AA_RIDER_B);
    acceptAutoAssignRider(AA_RIDER_C);

    // No rider assigned to AA_ASSIGNMENT, so every parcel falls straight to
    // the company-wide pool.
    $assigned = collect(range(1, 4))->map(
        fn (int $n): string => pressAutoAssign(queuedParcelFor($n))->json('data.rider.last_name')
    );

    // Parcel 4 wraps back to the first rider — the cursor persisted on the
    // company row is what carries the rotation between calls.
    expect($assigned->all())->toBe(['A', 'B', 'C', 'A']);
});

it('falls back to the pool when the barangay has no rider assigned to it', function () {
    acceptAutoAssignRider(AA_RIDER_A);

    // AA_ASSIGNMENT exists (matched) but has no rider_profile_id.
    pressAutoAssign(queuedParcelFor(1))
        ->assertOk()
        ->assertJsonPath('outcome', 'assigned')
        ->assertJsonPath('data.barangay_assignment.barangay', AA_BARANGAY)
        ->assertJsonPath('data.rider.last_name', 'A');
});

it('falls back to the pool when the barangay\'s assigned rider is off shift', function () {
    appointBarangayRider(AA_RIDER_A, 'unavailable');
    acceptAutoAssignRider(AA_RIDER_B);

    pressAutoAssign(queuedParcelFor(1))->assertJsonPath('data.rider.last_name', 'B');
});

it('assigns via the company-wide pool when no barangay assignment covers the address', function () {
    acceptAutoAssignRider(AA_RIDER_A);

    // The company only covers Barangay I of San Pablo City. Barangay II is
    // a different (unmatched) barangay, but that no longer strands the
    // parcel — it just skips straight to the pool.
    pressAutoAssign(queuedParcelFor(1, barangay: 'Barangay II'))
        ->assertOk()
        ->assertJsonPath('outcome', 'assigned')
        ->assertJsonPath('data.barangay_assignment', null)
        ->assertJsonPath('data.rider.last_name', 'A');
});

it('skips riders who are off shift in the pool', function () {
    acceptAutoAssignRider(AA_RIDER_A, 'available');
    acceptAutoAssignRider(AA_RIDER_B, 'unavailable');
    acceptAutoAssignRider(AA_RIDER_C, 'available');

    $assigned = collect(range(1, 4))->map(
        fn (int $n): string => pressAutoAssign(queuedParcelFor($n))->json('data.rider.last_name')
    );

    expect($assigned->all())->toBe(['A', 'C', 'A', 'C']);
});

it('skips riders who are already at their parcel quota', function () {
    acceptAutoAssignRider(AA_RIDER_A);
    acceptAutoAssignRider(AA_RIDER_B);

    // Rider A is already holding a full load (COURIER_QUOTA = 20).
    collect(range(101, 120))->each(function (int $n): void {
        DB::table('parcel_assignments')->where('id', queuedParcelFor($n))->update([
            'rider_profile_id' => AA_RIDER_A,
            'status' => 'assigned',
        ]);
    });

    pressAutoAssign(queuedParcelFor(1))->assertJsonPath('data.rider.last_name', 'B');
});

it('leaves a parcel unassigned when the address has no municipality or barangay on file', function () {
    acceptAutoAssignRider(AA_RIDER_A);

    $response = pressAutoAssign(queuedParcelFor(1, municipality: ''))
        ->assertOk()
        ->assertJsonPath('outcome', 'no_area')
        ->assertJsonPath('data.status', 'received')
        ->assertJsonPath('data.barangay_assignment', null)
        ->assertJsonPath('data.rider', null);

    expect($response->json('message'))->toContain('barangay or municipality');
});

it('reports no_rider when the pool is also empty', function () {
    pressAutoAssign(queuedParcelFor(1))
        ->assertOk()
        ->assertJsonPath('outcome', 'no_rider')
        ->assertJsonPath('data.rider', null);
});

it('will not reassign a parcel that already has a rider', function () {
    acceptAutoAssignRider(AA_RIDER_A);
    acceptAutoAssignRider(AA_RIDER_B);

    $parcelId = queuedParcelFor(1);

    pressAutoAssign($parcelId)->assertJsonPath('data.rider.last_name', 'A');

    // Pressing Auto assign again must not hand the same parcel to the
    // next rider in the rotation.
    pressAutoAssign($parcelId)
        ->assertJsonPath('outcome', 'skipped')
        ->assertJsonPath('data.rider.last_name', 'A');
});

it('ignores riders no longer accepted by the company', function () {
    acceptAutoAssignRider(AA_RIDER_A);
    acceptAutoAssignRider(AA_RIDER_B);

    DB::table('courier_applications')
        ->where('courier_profile_id', AA_RIDER_A)
        ->update(['status' => 'withdrawn']);

    pressAutoAssign(queuedParcelFor(1))->assertJsonPath('data.rider.last_name', 'B');
    pressAutoAssign(queuedParcelFor(2))->assertJsonPath('data.rider.last_name', 'B');
});

it('assigns a delivery rider to a picked-up parcel without knocking it back to assigned', function () {
    acceptAutoAssignRider(AA_RIDER_A);

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
    acceptAutoAssignRider(AA_RIDER_A);

    $parcelId = queuedParcelFor(1);
    DB::table('parcel_assignments')->where('id', $parcelId)->update([
        'logistics_company_id' => '30000000-0000-0000-0000-0000000000ff',
    ]);

    pressAutoAssign($parcelId)->assertNotFound();
});

describe('vehicle-gated transfer parcels', function () {
    it('skips a Motorcycle rider for a same-province, different-municipality parcel that needs a Car', function () {
        acceptAutoAssignRider(AA_RIDER_A, 'available', 'Motorcycle');
        acceptAutoAssignRider(AA_RIDER_B, 'available', 'Car');

        // Seller in a different municipality, same province -> 'car'.
        $parcelId = queuedParcelFor(1, overrides: [
            'pickup_municipality_name' => 'Calamba City',
        ]);

        pressAutoAssign($parcelId)
            ->assertJsonPath('outcome', 'assigned')
            ->assertJsonPath('data.required_vehicle_type', 'car')
            ->assertJsonPath('data.rider.last_name', 'B');
    });

    it('skips Car and Motorcycle riders for a cross-region parcel that needs a Van or Truck', function () {
        acceptAutoAssignRider(AA_RIDER_A, 'available', 'Motorcycle');
        acceptAutoAssignRider(AA_RIDER_B, 'available', 'Car');
        acceptAutoAssignRider(AA_RIDER_C, 'available', 'Truck');

        $parcelId = queuedParcelFor(1, overrides: [
            'pickup_region_name' => 'Visayas',
            'shipping_region_name' => 'Luzon',
        ]);

        pressAutoAssign($parcelId)
            ->assertJsonPath('outcome', 'assigned')
            ->assertJsonPath('data.is_transfer', true)
            ->assertJsonPath('data.transfer_trigger', 'region')
            ->assertJsonPath('data.required_vehicle_type', 'van_or_truck')
            ->assertJsonPath('data.rider.last_name', 'C');
    });

    it('bypasses a directly-assigned rider whose vehicle can\'t take a transfer parcel', function () {
        appointBarangayRider(AA_RIDER_A, 'available', 'Motorcycle');
        acceptAutoAssignRider(AA_RIDER_B, 'available', 'Van');

        $parcelId = queuedParcelFor(1, overrides: [
            'pickup_municipality_name' => 'Calamba City',
        ]);

        pressAutoAssign($parcelId)
            ->assertJsonPath('outcome', 'assigned')
            ->assertJsonPath('data.rider.last_name', 'B');
    });
});
