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
