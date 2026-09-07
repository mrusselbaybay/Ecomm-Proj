<?php

use App\Models\CourierApplication;
use App\Models\LogisticsCompany;
use App\Models\LogisticsDeliveryArea;
use App\Models\ParcelAssignment;
use App\Models\ParcelTransferRequest;
use App\Models\Profile;
use App\Services\SupabaseStorageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * End-to-end coverage of the cross-region "To Transfer" workflow: a
 * seller in one island-group region shipping to a buyer in another can't
 * be routed within the company that first received the parcel, so once a
 * courier has collected it that company raises a transfer *request* to a
 * second company. Nothing moves until the receiving company accepts —
 * only then does the origin row close as 'transferred' and a "to be
 * delivered" row open at the target (no transfer courier involved). A
 * rejection drops the parcel back on the origin desk. See
 * App\Services\ParcelIntakeService and Api\Logistics\
 * ParcelAssignmentController (requestTransfer / acceptTransferRequest /
 * rejectTransferRequest).
 */
beforeEach(function () {
    if (! Schema::hasTable('addresses')) {
        Schema::create('addresses', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('owner_kind');
            $table->string('profile_id')->nullable();
            $table->string('region_name')->nullable();
        });
    }

    if (! Schema::hasTable('logistics_companies')) {
        Schema::create('logistics_companies', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('owner_profile_id');
            $table->string('company_name');
            $table->string('region')->nullable();
            $table->string('status')->default('approved');
            $table->string('account_status')->default('active');
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('courier_applications')) {
        Schema::create('courier_applications', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('courier_profile_id');
            $table->string('logistics_company_id');
            $table->string('status');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    // The shared actingAsSeller()/actingAsDriver()/actingAsTransferCompanyOwner()
    // helpers each fake the exact same GoTrue URL — fine when a test only
    // ever acts as one identity, but Http::fake() calls accumulate rather
    // than replace, so switching identities mid-test (seller, then
    // logistics, then the courier) would keep resolving to whichever
    // identity was faked *first*. Registering this generic decoder first
    // — before any of those helpers run — wins that resolution race for
    // every request in this file, since every test token here is shaped
    // exactly 'test-token-<profile id>'.
    Http::fake(function ($request) {
        $header = $request->header('Authorization');
        $token = str_replace('Bearer ', '', is_array($header) ? ($header[0] ?? '') : (string) $header);
        $id = str_starts_with($token, 'test-token-') ? substr($token, strlen('test-token-')) : null;

        return $id
            ? Http::response(['id' => $id])
            : Http::response(['message' => 'invalid token'], 401);
    });

    $this->mock(SupabaseStorageService::class, function ($mock) {
        $mock->shouldReceive('upload')->andReturnNull();
        $mock->shouldReceive('signedUrl')->andReturn('https://example.test/photo.jpg');
    });
});

function makeSellerAddress(string $profileId, string $regionName): void
{
    DB::table('addresses')->insert([
        'id' => (string) Str::uuid(),
        'owner_kind' => 'profile',
        'profile_id' => $profileId,
        'region_name' => $regionName,
    ]);
}

function makeTransferTestCompany(string $region): LogisticsCompany
{
    return LogisticsCompany::create([
        'id' => (string) Str::uuid(),
        'owner_profile_id' => (string) Str::uuid(),
        'company_name' => "{$region} Logistics ".Str::random(4),
        'region' => $region,
        'status' => 'approved',
        'account_status' => 'active',
    ]);
}

it('starts every parcel in the pickup queue, then routes/transfers it end to end once collected', function () {
    $seller = makeSeller();
    makeSellerAddress($seller->id, 'Luzon');

    $buyer = makeBuyer();
    [$order] = makeOrder($buyer, $seller, [
        'status' => 'Processing',
        'shipping_region_name' => 'Visayas',
    ]);

    $originCompany = makeTransferTestCompany('Luzon');
    $targetCompany = makeTransferTestCompany('Visayas');

    // The courier only runs the pickup leg at the ORIGIN company — the
    // transfer itself is a paperwork handover with no courier.
    $courier = makeCourier();
    CourierApplication::create([
        'id' => (string) Str::uuid(),
        'courier_profile_id' => $courier->id,
        'logistics_company_id' => $originCompany->id,
        'status' => CourierApplication::STATUS_ACCEPTED,
        'applied_at' => now(),
    ]);

    // 1) Seller hands over to the origin company. Even though the regions
    // differ, the parcel just lands in the normal pickup queue — the
    // region mismatch is recorded only as a hint for staff.
    actingAsSeller($seller);
    $this->putJson("/api/seller/orders/{$order->order_number}/status", [
        'status' => 'In Transit',
        'shipping_carrier' => $originCompany->company_name,
    ])->assertOk();

    $assignment = ParcelAssignment::where('order_id', $order->id)->first();
    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(ParcelAssignment::STATUS_RECEIVED)
        ->and($assignment->is_transfer)->toBeTrue();

    actingAsTransferCompanyOwner($originCompany);

    // Nothing can be routed anywhere until a courier has collected it.
    $this->getJson("/api/logistics/parcel-assignments/{$assignment->id}/transfer-options")
        ->assertStatus(422);

    // 2) Dispatch sends a courier to pick it up — no delivery area needed
    // at this point, since where it's going hasn't been decided yet.
    $this->putJson("/api/logistics/parcel-assignments/{$assignment->id}/assign", [
        'rider_profile_id' => $courier->id,
    ])->assertOk()
        ->assertJsonPath('data.status', 'assigned');

    // 3) The courier confirms the pickup themselves, which releases them
    // and puts the parcel back on this desk for the deliver-or-transfer
    // decision. (The staff-side handoff endpoint is the other way to move
    // an assigned parcel along, but that one keeps the rider attached —
    // it's dispatch saying "this rider takes it from here".)
    actingAsDriver($courier);

    // The courier app sees an ordinary pickup: the region mismatch is
    // only a hint on the row, and transfers never reach a courier at all.
    $this->getJson('/api/driver/deliveries')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'assigned');

    $this->post("/api/driver/deliveries/{$assignment->id}/pickup", [
        'photo' => UploadedFile::fake()->create('pickup.jpg', 40, 'image/jpeg'),
    ])->assertOk();

    expect($assignment->refresh()->rider_profile_id)->toBeNull()
        ->and($assignment->status)->toBe(ParcelAssignment::STATUS_HANDED_OFF);

    // 4) Only NOW can staff route it to the target company. The options
    // are scoped to companies operating in the buyer's region — handing
    // it to another Luzon company would be no more deliverable than
    // keeping it here.
    actingAsTransferCompanyOwner($originCompany);

    $wrongRegionCompany = makeTransferTestCompany('Luzon');

    $options = $this->getJson("/api/logistics/parcel-assignments/{$assignment->id}/transfer-options")
        ->assertOk()
        ->assertJsonPath('meta.buyer_region', 'Visayas')
        ->assertJsonFragment(['id' => $targetCompany->id])
        ->json('data');

    expect(collect($options)->pluck('id'))
        ->toContain($targetCompany->id)
        ->not->toContain($wrongRegionCompany->id)
        ->not->toContain($originCompany->id);

    // The target company is only *asked* — custody does not move yet.
    $this->putJson("/api/logistics/parcel-assignments/{$assignment->id}/transfer", [
        'transfer_to_company_id' => $targetCompany->id,
    ])->assertOk()
        ->assertJsonPath('data.status', 'transfer_pending')
        ->assertJsonPath('data.transfer_to_company.id', $targetCompany->id);

    $assignment->refresh();
    expect($assignment->status)->toBe(ParcelAssignment::STATUS_TRANSFER_PENDING)
        ->and($assignment->transferred_at)->toBeNull()
        ->and($assignment->rider_profile_id)->toBeNull();

    // No row has opened at the target company yet — just a pending
    // request.
    expect(ParcelAssignment::where('order_id', $order->id)
        ->where('logistics_company_id', $targetCompany->id)
        ->exists())->toBeFalse();

    $transferRequest = ParcelTransferRequest::where('parcel_assignment_id', $assignment->id)->first();
    expect($transferRequest)->not->toBeNull()
        ->and($transferRequest->status)->toBe(ParcelTransferRequest::STATUS_PENDING)
        ->and($transferRequest->to_company_id)->toBe($targetCompany->id);

    // 5) The receiving company sees it in its inbox and accepts. Only now
    // does custody move: the origin row closes as 'transferred' and a
    // fresh "to be delivered" row opens at the target.
    actingAsTransferCompanyOwner($targetCompany);

    $this->getJson('/api/logistics/parcel-transfer-requests')
        ->assertOk()
        ->assertJsonPath('meta.pending_total', 1)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $transferRequest->id)
        ->assertJsonPath('data.0.from_company.id', $originCompany->id);

    $this->postJson("/api/logistics/parcel-transfer-requests/{$transferRequest->id}/accept")
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');

    $assignment->refresh();
    expect($assignment->status)->toBe(ParcelAssignment::STATUS_TRANSFERRED)
        ->and($assignment->transferred_at)->not->toBeNull()
        ->and($assignment->rider_profile_id)->toBeNull();

    $newAssignment = ParcelAssignment::where('order_id', $order->id)
        ->where('logistics_company_id', $targetCompany->id)
        ->first();

    expect($newAssignment)->not->toBeNull()
        ->and($newAssignment->status)->toBe(ParcelAssignment::STATUS_HANDED_OFF)
        ->and($newAssignment->rider_profile_id)->toBeNull()
        ->and($newAssignment->is_transfer)->toBeFalse()
        ->and($newAssignment->previous_assignment_id)->toBe($assignment->id);

    expect($transferRequest->fresh()->resulting_assignment_id)->toBe($newAssignment->id);

    // The transfer leaves no work on any courier's plate — the origin
    // courier's list keeps only their read-only record of the pickup.
    actingAsDriver($courier);
    $this->getJson('/api/driver/deliveries')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // 6) The target company dispatches it straight to a delivery rider,
    // with no second pickup leg.
    $targetRider = makeCourier();
    CourierApplication::create([
        'id' => (string) Str::uuid(),
        'courier_profile_id' => $targetRider->id,
        'logistics_company_id' => $targetCompany->id,
        'status' => CourierApplication::STATUS_ACCEPTED,
        'applied_at' => now(),
    ]);

    $area = LogisticsDeliveryArea::factory()->create([
        'logistics_company_id' => $targetCompany->id,
        'is_active' => true,
    ]);

    actingAsTransferCompanyOwner($targetCompany);
    $this->putJson("/api/logistics/parcel-assignments/{$newAssignment->id}/assign", [
        'delivery_area_id' => $area->id,
        'rider_profile_id' => $targetRider->id,
    ])->assertOk()
        ->assertJsonPath('data.status', 'handed_off');

    // The order itself never left 'In Transit' throughout the transfer.
    expect($order->fresh()->status)->toBe('In Transit');
});

it('puts the parcel back on the origin desk when the receiving company rejects the transfer', function () {
    $seller = makeSeller();
    $buyer = makeBuyer();
    [$order] = makeOrder($buyer, $seller, [
        'status' => 'In Transit',
        'shipping_region_name' => 'Visayas',
    ]);

    $originCompany = makeTransferTestCompany('Luzon');
    $targetCompany = makeTransferTestCompany('Visayas');

    // Straight to the post-pickup state: collected, rider released, on the
    // origin desk for the deliver-or-transfer call.
    $assignment = ParcelAssignment::create([
        'order_id' => $order->id,
        'logistics_company_id' => $originCompany->id,
        'status' => ParcelAssignment::STATUS_HANDED_OFF,
        'is_transfer' => true,
        'received_at' => now(),
        'handed_off_at' => now(),
    ]);

    // 1) Origin raises the request.
    actingAsTransferCompanyOwner($originCompany);
    $this->putJson("/api/logistics/parcel-assignments/{$assignment->id}/transfer", [
        'transfer_to_company_id' => $targetCompany->id,
    ])->assertOk()->assertJsonPath('data.status', 'transfer_pending');

    $request = ParcelTransferRequest::where('parcel_assignment_id', $assignment->id)->firstOrFail();

    // 2) Receiving company rejects it with a reason.
    actingAsTransferCompanyOwner($targetCompany);
    $this->postJson("/api/logistics/parcel-transfer-requests/{$request->id}/reject", [
        'note' => 'We do not cover that municipality.',
    ])->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.response_note', 'We do not cover that municipality.');

    // 3) The origin parcel is back where it started — handed off, no
    // rider, no target — free to be delivered or offered elsewhere.
    $assignment->refresh();
    expect($assignment->status)->toBe(ParcelAssignment::STATUS_HANDED_OFF)
        ->and($assignment->transfer_to_company_id)->toBeNull()
        ->and($assignment->transferred_at)->toBeNull();

    // Nothing opened at the target company.
    expect(ParcelAssignment::where('order_id', $order->id)
        ->where('logistics_company_id', $targetCompany->id)
        ->exists())->toBeFalse();

    // A second answer on the same request is refused.
    $this->postJson("/api/logistics/parcel-transfer-requests/{$request->id}/reject", [
        'note' => 'again',
    ])->assertStatus(422);
});

it('records no transfer hint when the holding company covers the buyer region', function () {
    $seller = makeSeller();
    makeSellerAddress($seller->id, 'Luzon');

    $buyer = makeBuyer();
    [$order] = makeOrder($buyer, $seller, [
        'status' => 'Processing',
        'shipping_region_name' => 'Luzon',
    ]);

    $company = makeTransferTestCompany('Luzon');

    actingAsSeller($seller);
    $this->putJson("/api/seller/orders/{$order->order_number}/status", [
        'status' => 'In Transit',
        'shipping_carrier' => $company->company_name,
    ])->assertOk();

    $assignment = ParcelAssignment::where('order_id', $order->id)->first();
    expect($assignment->is_transfer)->toBeFalse()
        ->and($assignment->status)->toBe(ParcelAssignment::STATUS_RECEIVED);
});

it('records no transfer hint when a region is missing on either side', function () {
    $seller = makeSeller();

    $buyer = makeBuyer();
    [$order] = makeOrder($buyer, $seller, [
        'status' => 'Processing',
        // No shipping region captured — nothing to compare against, so
        // the parcel is treated as deliverable rather than stranded.
        'shipping_region_name' => null,
    ]);

    $company = makeTransferTestCompany('Luzon');

    actingAsSeller($seller);
    $this->putJson("/api/seller/orders/{$order->order_number}/status", [
        'status' => 'In Transit',
        'shipping_carrier' => $company->company_name,
    ])->assertOk();

    $assignment = ParcelAssignment::where('order_id', $order->id)->first();
    expect($assignment->is_transfer)->toBeFalse();
});

function actingAsTransferCompanyOwner(LogisticsCompany $company): void
{
    /** @var Profile $owner */
    $owner = Profile::firstOrCreate(
        ['id' => $company->owner_profile_id],
        ['role' => 'logistics', 'status' => 'approved', 'account_status' => 'active', 'first_name' => 'Logistics', 'last_name' => 'Owner']
    );

    $token = 'test-token-'.$owner->id;

    config([
        'services.supabase.url' => 'https://unit-test.supabase.co',
        'services.supabase.anon_key' => 'test-anon-key',
    ]);

    Http::fake([
        'https://unit-test.supabase.co/auth/v1/user' => Http::response(['id' => $owner->id], 200),
    ]);

    test()->withHeader('Authorization', 'Bearer '.$token);
}
