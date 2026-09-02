<?php

use App\Models\LogisticsCompany;
use App\Models\ParcelAssignment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Covers App\Services\ParcelIntakeService as wired into
 * SellerOrderController::updateStatus: a seller confirming handover to a
 * registered courier (Courier Handover / Prepare Orders -> "In Transit")
 * should put the parcel straight into that company's sorting queue —
 * App\Http\Controllers\Api\Logistics\ParcelAssignmentController@index,
 * rendered by resources/js/logistics/components/ParcelOperations.vue —
 * without logistics staff having to scan it in first.
 */
beforeEach(function () {
    // logistics_companies and addresses are Supabase-managed tables with
    // no Laravel migration (see the other Logistics*ApiTest files for the
    // same ad hoc logistics_companies schema). SellerOrderController
    // eager-loads seller.address/buyer.address on every status update, so
    // `addresses` just needs to exist — no rows required.
    Schema::create('logistics_companies', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('owner_profile_id');
        $table->string('company_name');
        $table->string('status')->default('approved');
        $table->string('account_status')->default('active');
        $table->timestamps();
    });

    if (! Schema::hasTable('addresses')) {
        Schema::create('addresses', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('profile_id');
            $table->string('owner_kind');
        });
    }
});

function makeLogisticsCompany(array $overrides = []): LogisticsCompany
{
    return LogisticsCompany::create(array_merge([
        'id' => (string) Str::uuid(),
        'owner_profile_id' => (string) Str::uuid(),
        'company_name' => 'Luzon Logistics',
        'status' => 'approved',
        'account_status' => 'active',
    ], $overrides));
}

it('puts the parcel in the courier sorting queue the moment the seller confirms handover', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    [$order] = makeOrder($buyer, $seller, ['status' => 'Processing']);

    $company = makeLogisticsCompany(['company_name' => 'Luzon Logistics']);

    actingAsSeller($seller);

    // Carrier text is matched case-insensitively, same as the manual
    // scan-in flow already did.
    $this->putJson("/api/seller/orders/{$order->order_number}/status", [
        'status' => 'In Transit',
        'shipping_carrier' => 'luzon logistics',
        'shipping_service' => 'Standard',
    ])->assertOk();

    $assignment = ParcelAssignment::where('order_id', $order->id)->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->logistics_company_id)->toBe($company->id)
        ->and($assignment->status)->toBe(ParcelAssignment::STATUS_RECEIVED)
        // Nobody at the sorting center has scanned it yet — it's only
        // expected, not physically received.
        ->and($assignment->received_by)->toBeNull()
        ->and($assignment->scanned_at)->toBeNull();
});

it('sorts the parcel into a matching delivery area automatically', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    [$order] = makeOrder($buyer, $seller, [
        'status' => 'Processing',
        'shipping_province_name' => 'Laguna',
        'shipping_municipality_name' => 'Santa Cruz',
        'shipping_barangay' => 'Poblacion',
    ]);

    $company = makeLogisticsCompany(['company_name' => 'Luzon Logistics']);

    \App\Models\LogisticsDeliveryArea::create([
        'logistics_company_id' => $company->id,
        'name' => 'Area A',
        'province_name' => 'Laguna',
        'municipality_name' => 'Santa Cruz',
        'is_active' => true,
    ]);

    actingAsSeller($seller);

    $this->putJson("/api/seller/orders/{$order->order_number}/status", [
        'status' => 'In Transit',
        'shipping_carrier' => 'Luzon Logistics',
    ])->assertOk();

    $assignment = ParcelAssignment::where('order_id', $order->id)->first();

    expect($assignment->status)->toBe(ParcelAssignment::STATUS_SORTED)
        ->and($assignment->delivery_area_id)->not->toBeNull();
});

it('does not create a parcel assignment when the carrier text matches no registered company', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    [$order] = makeOrder($buyer, $seller, ['status' => 'Processing']);

    actingAsSeller($seller);

    $this->putJson("/api/seller/orders/{$order->order_number}/status", [
        'status' => 'In Transit',
        'shipping_carrier' => 'Some Random Courier',
    ])->assertOk();

    expect(ParcelAssignment::where('order_id', $order->id)->exists())->toBeFalse();
});

it('lets logistics staff scan in a parcel the seller already handed over without duplicating it', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    [$order] = makeOrder($buyer, $seller, [
        'status' => 'Processing',
        'tracking_number' => 'NXM-99001',
    ]);

    $companyOwner = \App\Models\Profile::create([
        'id' => (string) Str::uuid(),
        'role' => 'logistics',
        'status' => 'approved',
        'account_status' => 'active',
        'first_name' => 'Logistics',
        'last_name' => 'Owner',
    ]);
    $company = makeLogisticsCompany([
        'owner_profile_id' => $companyOwner->id,
        'company_name' => 'Luzon Logistics',
    ]);

    // One fake covering both identities for the rest of the test — a
    // second Http::fake() call for the same URL doesn't replace the
    // first, so the seller and logistics requests below are told apart
    // by their own bearer token instead.
    config([
        'services.supabase.url' => 'https://unit-test.supabase.co',
        'services.supabase.anon_key' => 'test-anon-key',
    ]);
    $sellerToken = 'test-token-'.$seller->id;
    $logisticsToken = 'test-token-'.$companyOwner->id;
    \Illuminate\Support\Facades\Http::fake(function (\Illuminate\Http\Client\Request $request) use ($sellerToken, $logisticsToken, $seller, $companyOwner) {
        $auth = $request->header('Authorization')[0] ?? '';

        return match ($auth) {
            "Bearer {$sellerToken}" => \Illuminate\Support\Facades\Http::response(['id' => $seller->id], 200),
            "Bearer {$logisticsToken}" => \Illuminate\Support\Facades\Http::response(['id' => $companyOwner->id], 200),
            default => \Illuminate\Support\Facades\Http::response([], 401),
        };
    });

    $this->withHeader('Authorization', 'Bearer '.$sellerToken)
        ->putJson("/api/seller/orders/{$order->order_number}/status", [
            'status' => 'In Transit',
            'shipping_carrier' => 'Luzon Logistics',
        ])->assertOk();

    expect(ParcelAssignment::where('order_id', $order->id)->count())->toBe(1);

    $this->withHeader('Authorization', 'Bearer '.$logisticsToken)
        ->postJson('/api/logistics/parcel-assignments/receive', [
            'tracking_number' => 'NXM-99001',
        ])
        ->assertOk() // 200, not 201 — the row already existed from handover
        ->assertJsonPath('data.order.order_number', $order->order_number);

    $assignment = ParcelAssignment::where('order_id', $order->id)->first();

    expect(ParcelAssignment::where('order_id', $order->id)->count())->toBe(1)
        ->and($assignment->received_by)->toBe($companyOwner->id)
        ->and($assignment->scanned_at)->not->toBeNull();
});
