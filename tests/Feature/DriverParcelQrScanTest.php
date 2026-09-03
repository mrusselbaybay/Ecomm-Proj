<?php

use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\ParcelAssignment;
use App\Models\ParcelScanEvent;
use App\Services\SupabaseStorageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Covers the parcel confirmation QR at the courier checkpoints:
 * Driver\DriverDeliveryController::verifyQr plus the optional
 * `confirmation_token` on ::pickup / ::deliver. The token is a non-breaking
 * add — the photo proof on pickup/deliver is unchanged; a scan is recorded
 * alongside it (App\Models\ParcelScanEvent) when the token is sent.
 *
 * `logistics_companies` is a Supabase-managed table with no Laravel
 * migration, so create the ad hoc fixture here (same shape the other
 * Logistics*ApiTest files use) rather than depending on another test
 * file's global helper. Supabase storage is stubbed so the photo-gated
 * actions don't reach the network.
 */
beforeEach(function () {
    if (! Schema::hasTable('logistics_companies')) {
        Schema::create('logistics_companies', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('owner_profile_id');
            $table->string('company_name');
            $table->string('status')->default('approved');
            $table->string('account_status')->default('active');
            $table->timestamps();
        });
    }

    $this->mock(SupabaseStorageService::class, function ($mock) {
        $mock->shouldReceive('upload')->andReturnNull();
        $mock->shouldReceive('signedUrl')->andReturn('https://example.test/photo.jpg');
    });
});

function dispatchedAssignment(array $orderOverrides = [], array $assignmentOverrides = []): array
{
    $buyer = makeBuyer();
    $seller = makeSeller();
    $courier = makeCourier();
    $company = LogisticsCompany::create([
        'id' => (string) Str::uuid(),
        'owner_profile_id' => (string) Str::uuid(),
        'company_name' => 'Luzon Logistics',
        'status' => 'approved',
        'account_status' => 'active',
    ]);

    [$order] = makeOrder($buyer, $seller, array_merge([
        'status' => 'In Transit',
        'recipient_name' => 'Liza Cruz',
    ], $orderOverrides));

    $order->forceFill(['confirmation_token' => Order::generateConfirmationToken()])->save();

    $assignment = ParcelAssignment::create(array_merge([
        'order_id' => $order->id,
        'logistics_company_id' => $company->id,
        'rider_profile_id' => $courier->id,
        'status' => ParcelAssignment::STATUS_ASSIGNED,
        'received_by' => $seller->id,
        'received_at' => now()->subHour(),
        'assigned_at' => now()->subMinutes(20),
    ], $assignmentOverrides));

    return [$order, $assignment, $courier];
}

it('resolves a scanned QR to the rider\'s delivery and logs a verify scan', function () {
    [$order, $assignment, $courier] = dispatchedAssignment();

    actingAsDriver($courier);

    $this->postJson('/api/driver/deliveries/verify-qr', [
        'token' => 'NXP:'.$order->confirmation_token,
    ])
        ->assertOk()
        ->assertJsonPath('data.id', $assignment->id)
        ->assertJsonPath('data.order_number', $order->order_number)
        ->assertJsonPath('data.customer_name', 'Liza Cruz')
        ->assertJsonPath('next_action', 'pickup');

    $scan = ParcelScanEvent::where('order_id', $order->id)->sole();
    expect($scan->checkpoint)->toBe(ParcelScanEvent::CHECKPOINT_VERIFY)
        ->and($scan->parcel_assignment_id)->toBe($assignment->id)
        ->and($scan->scanned_by)->toBe($courier->id);
});

it('accepts the bare token without the NXP: prefix', function () {
    [$order, , $courier] = dispatchedAssignment();

    actingAsDriver($courier);

    $this->postJson('/api/driver/deliveries/verify-qr', [
        'token' => $order->confirmation_token,
    ])->assertOk()->assertJsonPath('data.order_number', $order->order_number);
});

it('returns 404 for a code that maps to no order', function () {
    [, , $courier] = dispatchedAssignment();

    actingAsDriver($courier);

    $this->postJson('/api/driver/deliveries/verify-qr', ['token' => 'NXP:deadbeef'])
        ->assertStatus(404);

    expect(ParcelScanEvent::count())->toBe(0);
});

it('will not resolve another rider\'s parcel', function () {
    [$order] = dispatchedAssignment();
    $otherCourier = makeCourier();

    actingAsDriver($otherCourier);

    $this->postJson('/api/driver/deliveries/verify-qr', [
        'token' => 'NXP:'.$order->confirmation_token,
    ])->assertStatus(404);

    expect(ParcelScanEvent::count())->toBe(0);
});

it('logs a pickup scan when the matching token rides along with the pickup photo', function () {
    [$order, $assignment, $courier] = dispatchedAssignment();

    actingAsDriver($courier);

    $this->postJson("/api/driver/deliveries/{$assignment->id}/pickup", [
        'photo' => UploadedFile::fake()->create('parcel.jpg', 40, 'image/jpeg'),
        'confirmation_token' => 'NXP:'.$order->confirmation_token,
    ])->assertOk();

    expect($assignment->fresh()->status)->toBe(ParcelAssignment::STATUS_HANDED_OFF);

    $scan = ParcelScanEvent::where('checkpoint', ParcelScanEvent::CHECKPOINT_PICKUP)->sole();
    expect($scan->order_id)->toBe($order->id)
        ->and($scan->scanned_by)->toBe($courier->id);
});

it('rejects a pickup whose scanned code does not match the parcel', function () {
    [, $assignment, $courier] = dispatchedAssignment();

    actingAsDriver($courier);

    $this->postJson("/api/driver/deliveries/{$assignment->id}/pickup", [
        'photo' => UploadedFile::fake()->create('parcel.jpg', 40, 'image/jpeg'),
        'confirmation_token' => 'NXP:0000000000000000000000000000000000000000',
    ])->assertStatus(422);

    expect($assignment->fresh()->status)->toBe(ParcelAssignment::STATUS_ASSIGNED)
        ->and(ParcelScanEvent::count())->toBe(0);
});

it('still works with no token at all (photo-only pickup, no scan logged)', function () {
    [$order, $assignment, $courier] = dispatchedAssignment();

    actingAsDriver($courier);

    $this->postJson("/api/driver/deliveries/{$assignment->id}/pickup", [
        'photo' => UploadedFile::fake()->create('parcel.jpg', 40, 'image/jpeg'),
    ])->assertOk();

    expect($assignment->fresh()->status)->toBe(ParcelAssignment::STATUS_HANDED_OFF)
        ->and(ParcelScanEvent::count())->toBe(0);
});

it('releases the parcel back to logistics on pickup, keeping a read-only record for the pickup courier', function () {
    [$order, $assignment, $courier] = dispatchedAssignment();

    actingAsDriver($courier);

    $this->postJson("/api/driver/deliveries/{$assignment->id}/pickup", [
        'photo' => UploadedFile::fake()->create('parcel.jpg', 40, 'image/jpeg'),
    ])->assertOk();

    $fresh = $assignment->fresh();
    expect($fresh->status)->toBe(ParcelAssignment::STATUS_HANDED_OFF)
        ->and($fresh->rider_profile_id)->toBeNull()
        ->and($fresh->picked_up_by)->toBe($courier->id);

    // Still on the courier's list, but as a terminal "handed_over" record.
    $this->getJson('/api/driver/deliveries')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $assignment->id)
        ->assertJsonPath('data.0.status', 'handed_over');

    // ...and they can no longer mark it delivered — that's a different rider's job.
    $this->postJson("/api/driver/deliveries/{$assignment->id}/deliver", [
        'photo' => UploadedFile::fake()->create('parcel.jpg', 40, 'image/jpeg'),
    ])->assertStatus(404);

    expect($order->fresh()->status)->toBe('In Transit');
});

it('logs a delivery scan when the matching token rides along with the delivery photo', function () {
    [$order, $assignment, $courier] = dispatchedAssignment([], [
        'status' => ParcelAssignment::STATUS_HANDED_OFF,
        'handed_off_at' => now()->subMinutes(5),
    ]);

    actingAsDriver($courier);

    $this->postJson("/api/driver/deliveries/{$assignment->id}/deliver", [
        'photo' => UploadedFile::fake()->create('parcel.jpg', 40, 'image/jpeg'),
        'confirmation_token' => 'NXP:'.$order->confirmation_token,
    ])->assertOk();

    expect($order->fresh()->status)->toBe('Delivered');

    $scan = ParcelScanEvent::where('checkpoint', ParcelScanEvent::CHECKPOINT_DELIVERY)->sole();
    expect($scan->order_id)->toBe($order->id)
        ->and($scan->scanned_by)->toBe($courier->id);
});
