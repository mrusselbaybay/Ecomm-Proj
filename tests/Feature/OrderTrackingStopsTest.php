<?php

use App\Models\Address;
use App\Models\LogisticsCompany;
use App\Models\ParcelAssignment;
use App\Services\OrderTrackingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    // Supabase-managed tables with no Laravel migration.
    if (! Schema::hasTable('logistics_companies')) {
        Schema::create('logistics_companies', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('owner_profile_id')->nullable();
            $t->string('company_name')->nullable();
            $t->string('status')->nullable();
            $t->string('account_status')->nullable();
            $t->timestamps();
        });
    }

    Schema::dropIfExists('addresses');
    Schema::create('addresses', function (Blueprint $t) {
        $t->uuid('id')->primary();
        $t->string('owner_kind')->nullable();
        $t->uuid('profile_id')->nullable();
        $t->string('logistics_company_id')->nullable();
        foreach (['region_code', 'region_name', 'province_code', 'province_name', 'municipality_code',
            'municipality_name', 'barangay', 'street', 'house_no'] as $col) {
            $t->string($col)->nullable();
        }
        $t->decimal('latitude', 10, 7)->nullable();
        $t->decimal('longitude', 10, 7)->nullable();
        $t->timestamps();
    });
});

function hubCompany(string $name, ?array $pin, string $municipality = 'Calamba', string $province = 'Laguna'): LogisticsCompany
{
    $company = LogisticsCompany::create([
        'id' => (string) Str::uuid(),
        'owner_profile_id' => (string) Str::uuid(),
        'company_name' => $name,
        'status' => 'approved',
        'account_status' => 'active',
    ]);

    Address::create([
        'owner_kind' => 'logistics_company',
        'logistics_company_id' => $company->id,
        'municipality_name' => $municipality,
        'province_name' => $province,
        'latitude' => $pin[0] ?? null,
        'longitude' => $pin[1] ?? null,
    ]);

    return $company;
}

function pinnedOrder(): \App\Models\Order
{
    [$order] = makeOrder(makeBuyer(), makeSeller(), [
        'status' => 'In Transit',
        'pickup_municipality_name' => 'Calamba', 'pickup_province_name' => 'Laguna',
        'pickup_latitude' => 14.21, 'pickup_longitude' => 121.16,
        'shipping_municipality_name' => 'Cebu City', 'shipping_province_name' => 'Cebu',
        'shipping_latitude' => 10.31, 'shipping_longitude' => 123.89,
    ]);

    return $order->load('seller.address', 'seller.sellerDetail');
}

it('routes seller -> hub A -> hub B -> buyer and marks the transfer leg active', function () {
    $order = pinnedOrder();
    $hubA = hubCompany('Hub A', [14.20, 121.15]);
    $hubB = hubCompany('Hub B', null, 'Cebu City', 'Cebu'); // no pin -> town centre

    ParcelAssignment::create([
        'order_id' => $order->id,
        'received_at' => now()->subHours(4),
        'logistics_company_id' => $hubA->id,
        'status' => ParcelAssignment::STATUS_READY_TO_TRANSFER,
        'transfer_to_company_id' => $hubB->id,
        'for_inventory_at' => now()->subHours(3),
        'inventory_origin' => ParcelAssignment::INVENTORY_ORIGIN_PICKUP,
        'inventory_scanned_at' => now()->subHours(2),
    ]);

    $journey = (new OrderTrackingService)->journey($order);

    expect(collect($journey['stops'])->pluck('name')->all())->toBe([
        $order->seller->sellerDetail->business_name ?: 'Seller', 'Hub A', 'Hub B', 'Cebu City, Cebu',
    ])
        ->and(collect($journey['stops'])->pluck('exact')->all())->toBe([true, true, false, true])
        ->and($journey['reachedIndex'])->toBe(1)
        ->and($journey['activeLeg'])->toBe(1)
        ->and($journey['statusLabel'])->toBe('In transfer to Hub B')
        // No live ping: drawn at the last stop reached, flagged as not its real spot.
        ->and($journey['parcel'])->toBe(['lat' => 14.2, 'lng' => 121.15])
        ->and($journey['estimated'])->toBeTrue();
});

it('shows the parcel picked up and heading to the first hub before it is scanned in', function () {
    $order = pinnedOrder();
    $hubA = hubCompany('Hub A', [14.20, 121.15]);

    ParcelAssignment::create([
        'order_id' => $order->id,
        'received_at' => now()->subHours(4),
        'logistics_company_id' => $hubA->id,
        'status' => ParcelAssignment::STATUS_FOR_INVENTORY,
        'handed_off_at' => now(),
        'for_inventory_at' => now(),
        'inventory_origin' => ParcelAssignment::INVENTORY_ORIGIN_PICKUP,
    ]);

    $journey = (new OrderTrackingService)->journey($order);

    expect($journey['reachedIndex'])->toBe(0)
        ->and($journey['activeLeg'])->toBe(0)
        ->and($journey['statusLabel'])->toBe('Picked up — heading to Hub A')
        ->and($journey['parcel'])->toBe(['lat' => 14.21, 'lng' => 121.16]);
});

it('reverses the route for a return: buyer -> hub -> seller', function () {
    $order = pinnedOrder();
    $hub = hubCompany('Hub B', [10.30, 123.90], 'Cebu City', 'Cebu');
    $return = \App\Models\OrderReturnRequest::create([
        'order_id' => $order->id, 'order_item_id' => $order->items()->value('id'),
        'buyer_profile_id' => $order->buyer_profile_id, 'seller_id' => $order->seller_id,
        'request_type' => 'return_refund', 'reason' => 'damaged', 'details' => 'Cracked.',
        'quantity' => 1, 'estimated_amount' => 100, 'evidence' => ['x'], 'status' => 'approved',
    ]);

    ParcelAssignment::create([
        'order_id' => $order->id,
        'received_at' => now()->subHours(4),
        'logistics_company_id' => $hub->id,
        'status' => ParcelAssignment::STATUS_HANDED_OFF,
        'return_request_id' => $return->id,
        'inventory_scanned_at' => now(),
    ]);

    $journey = (new OrderTrackingService)->journey($order);

    expect($journey['isReturn'])->toBeTrue()
        ->and($journey['stops'][0]['lat'])->toBe(10.31)
        ->and(last($journey['stops'])['lat'])->toBe(14.21)
        ->and($journey['statusLabel'])->toBe('At Hub B');
});

it('says the courier is on the way while one is dispatched to the seller', function () {
    $order = pinnedOrder();
    $hub = hubCompany('Hub A', [14.20, 121.15]);

    ParcelAssignment::create([
        'order_id' => $order->id,
        'received_at' => now(),
        'logistics_company_id' => $hub->id,
        'status' => ParcelAssignment::STATUS_ASSIGNED,
        'rider_profile_id' => makeBuyer()->id,
        'assigned_at' => now(),
    ]);

    $journey = (new OrderTrackingService)->journey($order);

    expect($journey['statusLabel'])->toBe('Courier on the way to pickup')
        ->and($journey['parcel'])->toBe(['lat' => 14.21, 'lng' => 121.16])
        ->and($journey['activeLeg'])->toBeNull()
        ->and($journey['active'])->toBeTrue();
});

it('shows a transferred parcel at the receiving hub while it is being checked in', function () {
    $order = pinnedOrder();
    $hubA = hubCompany('Hub A', [14.20, 121.15]);
    $hubB = hubCompany('Hub B', [10.30, 123.90], 'Cebu City', 'Cebu');

    ParcelAssignment::create([
        'order_id' => $order->id, 'received_at' => now()->subDay(),
        'logistics_company_id' => $hubA->id, 'status' => ParcelAssignment::STATUS_TRANSFERRED,
        'inventory_scanned_at' => now()->subDay(), 'transferred_at' => now()->subHour(),
    ]);
    ParcelAssignment::create([
        'order_id' => $order->id, 'received_at' => now(),
        'logistics_company_id' => $hubB->id, 'status' => ParcelAssignment::STATUS_FOR_INVENTORY,
        'for_inventory_at' => now(), 'inventory_origin' => ParcelAssignment::INVENTORY_ORIGIN_TRANSFER_RECEIPT,
    ]);

    $journey = (new OrderTrackingService)->journey($order);

    expect($journey['reachedIndex'])->toBe(2)
        ->and($journey['statusLabel'])->toBe('Arrived at Hub B — being checked in')
        ->and($journey['parcel'])->toBe(['lat' => 10.3, 'lng' => 123.9]);
});

it('gives the buyer their own order map, with the seller only at town level', function () {
    $order = pinnedOrder();
    $buyer = \App\Models\Profile::find($order->buyer_profile_id);

    actingAsBuyer($buyer);

    $this->getJson("/api/buyer/orders/{$order->order_number}/tracking")
        ->assertOk()
        ->assertJsonPath('data.stops.0.exact', false)
        ->assertJsonPath('data.stops.1.exact', true)
        ->assertJsonPath('data.stops.1.lat', 10.31);

    actingAsBuyer(makeBuyer());

    $this->getJson("/api/buyer/orders/{$order->order_number}/tracking")->assertNotFound();
});

it('freezes the route when the order is cancelled', function () {
    $order = pinnedOrder();
    $order->update(['status' => 'Cancelled']);

    $journey = (new OrderTrackingService)->journey($order->fresh(['seller.address', 'seller.sellerDetail']));

    expect($journey['statusLabel'])->toBe('Cancelled')
        ->and($journey['activeLeg'])->toBeNull()
        ->and($journey['active'])->toBeFalse();
});

it('keeps the original delivery route as history while a return is under way', function () {
    $order = pinnedOrder();
    $hubA = hubCompany('Hub A', [14.20, 121.15]);
    $hubB = hubCompany('Hub B', [10.30, 123.90], 'Cebu City', 'Cebu');

    ParcelAssignment::create([
        'order_id' => $order->id, 'received_at' => now()->subDays(3),
        'logistics_company_id' => $hubA->id, 'status' => ParcelAssignment::STATUS_HANDED_OFF,
        'inventory_scanned_at' => now()->subDays(3), 'delivered_at' => now()->subDays(2),
    ]);
    $return = \App\Models\OrderReturnRequest::create([
        'order_id' => $order->id, 'order_item_id' => $order->items()->value('id'),
        'buyer_profile_id' => $order->buyer_profile_id, 'seller_id' => $order->seller_id,
        'request_type' => 'return_refund', 'reason' => 'damaged', 'details' => 'Cracked.',
        'quantity' => 1, 'estimated_amount' => 100, 'evidence' => ['x'], 'status' => 'approved',
    ]);
    ParcelAssignment::create([
        'order_id' => $order->id, 'received_at' => now(), 'return_request_id' => $return->id,
        'logistics_company_id' => $hubB->id, 'status' => ParcelAssignment::STATUS_RECEIVED,
    ]);

    $journey = (new OrderTrackingService)->journey($order);

    expect($journey['isReturn'])->toBeTrue()
        ->and(collect($journey['previousStops'])->pluck('name')->all())->toContain('Hub A')
        ->and(last($journey['previousStops'])['at'])->not->toBeNull()
        ->and($journey['stops'][0]['lat'])->toBe(10.31);
});

it('draws the whole GPS trail, not just the last day, thinned to a sane size', function () {
    $order = pinnedOrder();

    $rows = collect(range(1, 450))->map(fn (int $i) => [
        'id' => (string) Str::uuid(),
        'order_id' => $order->id,
        'lat' => 14.0 + $i / 1000,
        'lng' => 121.0,
        'source' => 'simulator',
        'recorded_at' => now()->subDays(3)->addMinutes($i),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    \App\Models\ParcelLocation::insert($rows->all());

    $trail = (new OrderTrackingService)->journey($order)['trail'];

    expect($trail)->toHaveCount(300)
        ->and($trail[0]['lat'])->toBe(14.001)   // oldest kept
        ->and(last($trail)['lat'])->toBe(14.45); // newest kept
});

it('follows a return from buyer pickup to delivery back at the seller, latest return only', function () {
    $order = pinnedOrder();
    $hub = hubCompany('Hub B', [10.30, 123.90], 'Cebu City', 'Cebu');
    $makeReturn = fn () => \App\Models\OrderReturnRequest::create([
        'order_id' => $order->id, 'order_item_id' => $order->items()->value('id'),
        'buyer_profile_id' => $order->buyer_profile_id, 'seller_id' => $order->seller_id,
        'request_type' => 'return_refund', 'reason' => 'damaged', 'details' => 'Cracked.',
        'quantity' => 1, 'estimated_amount' => 100, 'evidence' => ['x'], 'status' => 'approved',
    ]);

    // An older, finished return on another hub must not leak into the route.
    $old = $makeReturn();
    ParcelAssignment::create([
        'order_id' => $order->id, 'received_at' => now()->subDays(5), 'return_request_id' => $old->id,
        'logistics_company_id' => hubCompany('Old Hub', [12.0, 122.0])->id,
        'status' => ParcelAssignment::STATUS_HANDED_OFF, 'delivered_at' => now()->subDays(4),
    ])->forceFill(['created_at' => now()->subDays(5)])->save();

    $return = $makeReturn();
    $leg = ParcelAssignment::create([
        'order_id' => $order->id, 'received_at' => now(), 'return_request_id' => $return->id,
        'logistics_company_id' => $hub->id, 'status' => ParcelAssignment::STATUS_SORTED,
    ]);

    $tracking = fn () => (new OrderTrackingService)->journey($order->fresh(['seller.address', 'seller.sellerDetail']));

    $j = $tracking();
    expect(collect($j['stops'])->pluck('name')->all())->not->toContain('Old Hub')
        ->and($j['statusLabel'])->toBe('Waiting for return pickup')
        ->and($j['active'])->toBeTrue();

    expect($j['parcel'])->toBe(['lat' => 10.31, 'lng' => 123.89]); // to pick up -> at the buyer

    // Collected from the buyer — a return skips the inventory scan
    // (returnPickupBypass): straight to handed_off, delivery rider assigned.
    $leg->update(['status' => ParcelAssignment::STATUS_HANDED_OFF, 'handed_off_at' => now(),
        'for_inventory_at' => null, 'inventory_origin' => null,
        'rider_profile_id' => makeBuyer()->id, 'assigned_at' => now()]);
    $j = $tracking();
    expect($j['reachedIndex'])->toBe(1)
        ->and($j['statusLabel'])->toBe('On the way back to the seller')
        ->and($j['parcel'])->toBe(['lat' => 10.3, 'lng' => 123.9])
        ->and($j['delivered'])->toBeFalse();

    // Delivered back to the seller — still never scanned, must not freeze.
    $leg->update(['delivered_at' => now()]);
    $j = $tracking();
    expect($j['statusLabel'])->toBe('Returned to seller')
        ->and($j['parcel'])->toBe(['lat' => 14.21, 'lng' => 121.16])
        ->and($j['returned'])->toBeTrue()
        ->and($j['active'])->toBeFalse();
});

it('shows a return that was transferred back at the origin hub', function () {
    $order = pinnedOrder();
    $origin = hubCompany('Origin Hub', [14.20, 121.15]);
    $dest = hubCompany('Destination Hub', [10.30, 123.90], 'Cebu City', 'Cebu');
    $return = \App\Models\OrderReturnRequest::create([
        'order_id' => $order->id, 'order_item_id' => $order->items()->value('id'),
        'buyer_profile_id' => $order->buyer_profile_id, 'seller_id' => $order->seller_id,
        'request_type' => 'return_refund', 'reason' => 'damaged', 'details' => 'Cracked.',
        'quantity' => 1, 'estimated_amount' => 100, 'evidence' => ['x'], 'status' => 'approved',
    ]);

    // Leg 1 at the destination hub: collected from the buyer, transferred on.
    $first = ParcelAssignment::create([
        'order_id' => $order->id, 'received_at' => now()->subDay(), 'return_request_id' => $return->id,
        'logistics_company_id' => $dest->id, 'status' => ParcelAssignment::STATUS_TRANSFERRED,
        'handed_off_at' => now()->subDay(), 'transfer_to_company_id' => $origin->id, 'transferred_at' => now()->subHour(),
    ]);
    $first->forceFill(['created_at' => now()->subDay()])->save();

    // Leg 2: the transfer receipt at the origin hub (born past the scan).
    ParcelAssignment::create([
        'order_id' => $order->id, 'received_at' => now(), 'return_request_id' => $return->id,
        'logistics_company_id' => $origin->id, 'status' => ParcelAssignment::STATUS_HANDED_OFF,
        'previous_assignment_id' => $first->id,
    ]);

    $j = (new OrderTrackingService)->journey($order->fresh(['seller.address', 'seller.sellerDetail']));

    expect(collect($j['stops'])->pluck('name')->slice(1, 2)->values()->all())->toBe(['Destination Hub', 'Origin Hub'])
        ->and($j['reachedIndex'])->toBe(2)
        ->and($j['statusLabel'])->toBe('At Origin Hub')
        ->and($j['parcel'])->toBe(['lat' => 14.2, 'lng' => 121.15]);
});
