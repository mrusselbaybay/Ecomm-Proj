<?php

use App\Models\DeliveryAttempt;
use App\Models\LogisticsBarangayAssignment;
use App\Models\LogisticsCompany;
use App\Models\ParcelAssignment;
use App\Models\Profile;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function failedDeliveryActAs(Profile $profile): void
{
    test()->withHeader('Authorization', 'Bearer failed-delivery-test:'.$profile->id);
}

beforeEach(function () {
    fakeApiTokens(fn ($token) => str_starts_with($token, 'failed-delivery-test:') ? substr($token, 21) : null);
    if (! Schema::hasTable('logistics_admin_details')) {
        Schema::create('logistics_admin_details', function (Blueprint $table) {
            $table->uuid('profile_id')->primary();
            $table->uuid('logistics_company_id');
            $table->string('role')->default('operator');
            $table->string('status')->default('active');
        });
    }
    $this->mock(\App\Services\FileStorage::class, fn ($mock) => $mock->shouldReceive('upload')->andReturn('proof.png'));
    config(['delivery.attempt_limit' => 3]);
    if (! Schema::hasTable('logistics_companies')) {
        Schema::create('logistics_companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_profile_id');
            $table->string('company_name');
            $table->string('status')->default('approved');
            $table->string('account_status')->default('active');
            $table->timestamps();
        });
    }
    if (! Schema::hasTable('courier_applications')) {
        Schema::create('courier_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('courier_profile_id');
            $table->uuid('logistics_company_id');
            $table->string('status');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }
    $this->owner = makeLogistics();
    $this->courier = makeCourier();
    $this->company = LogisticsCompany::create([
        'id' => (string) Str::uuid(), 'owner_profile_id' => $this->owner->id,
        'company_name' => 'Test Logistics', 'status' => 'approved', 'account_status' => 'active',
    ]);
    [$this->order] = makeOrder(makeBuyer(), makeSeller(), ['status' => 'In Transit']);
    $area = LogisticsBarangayAssignment::factory()->create(['logistics_company_id' => $this->company->id]);
    $this->assignment = ParcelAssignment::create([
        'barangay_assignment_id' => $area->id,
        'order_id' => $this->order->id, 'logistics_company_id' => $this->company->id,
        'rider_profile_id' => $this->courier->id, 'status' => ParcelAssignment::STATUS_HANDED_OFF,
        'received_at' => now(), 'assigned_at' => now(), 'handed_off_at' => now(), 'assigned_by' => $this->owner->id,
    ]);
    $this->driverUrl = '/api/driver/deliveries/'.$this->assignment->id;
    $this->dispatchUrl = '/api/logistics/parcel-assignments/'.$this->assignment->id;
    failedDeliveryActAs($this->courier);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

it('uses the next Manila calendar midnight for early morning and late night failures', function (string $failedAt, string $eligibleAt) {
    $this->travelTo(CarbonImmutable::parse($failedAt));
    $response = $this->postJson($this->driverUrl.'/failed-attempt', ['photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=')), 'reason_code' => 'recipient_unavailable'])
        ->assertOk()->assertJsonPath('data.status', 'failed_attempt')
        ->assertJsonPath('data.delivery_attempt.can_reattempt', false);
    expect(CarbonImmutable::parse($response->json('data.delivery_attempt.retry_at'))->equalTo(CarbonImmutable::parse($eligibleAt)))->toBeTrue();
    $attempt = DeliveryAttempt::firstOrFail();
    expect($attempt->failed_at->equalTo(CarbonImmutable::parse($failedAt)))->toBeTrue();
    expect($this->order->fresh()->status)->toBe('Failed Delivery Attempt');
    $this->postJson($this->driverUrl.'/reattempt')->assertUnprocessable();
    $this->travelTo(CarbonImmutable::parse($eligibleAt)->subSecond());
    $this->postJson($this->driverUrl.'/reattempt')->assertUnprocessable();
    $this->travelTo(CarbonImmutable::parse($eligibleAt));
    $this->postJson($this->driverUrl.'/reattempt')->assertOk()->assertJsonPath('data.status', 'picked_up');
    expect($this->order->fresh()->status)->toBe('In Transit');
})->with([
    ['2026-10-05T15:59:00Z', '2026-10-05T16:00:00Z'],
    ['2026-10-04T22:00:00Z', '2026-10-05T16:00:00Z'],
    ['2026-10-31T15:59:00Z', '2026-10-31T16:00:00Z'],
    ['2026-12-31T15:59:00Z', '2026-12-31T16:00:00Z'],
]);

it('requires a predefined reason and a nonempty description for Others', function () {
    $this->postJson($this->driverUrl.'/failed-attempt', ['reason_code' => 'recipient_unavailable'])
        ->assertUnprocessable()->assertJsonValidationErrors('photo');
    expect(\App\Models\CourierEarning::count())->toBe(0);
    $this->postJson($this->driverUrl.'/failed-attempt', ['photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=')), ])->assertUnprocessable()->assertJsonValidationErrors('reason_code');
    $this->postJson($this->driverUrl.'/failed-attempt', ['photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=')), 'reason_code' => 'invalid'])->assertUnprocessable();
    $this->postJson($this->driverUrl.'/failed-attempt', ['photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=')), 'reason_code' => 'others', 'description' => '   '])->assertUnprocessable()->assertJsonValidationErrors('description');
    $this->postJson($this->driverUrl.'/failed-attempt', ['photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=')), 'reason_code' => 'others', 'description' => str_repeat('a', 1001)])->assertUnprocessable();
    expect(DeliveryAttempt::count())->toBe(0);
    $this->postJson($this->driverUrl.'/failed-attempt', ['photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=')), 'reason_code' => 'others', 'description' => '  Recipient moved away  '])->assertOk();
    expect(DeliveryAttempt::first()->description)->toBe('Recipient moved away');
});

it('keeps failures visible and rejects duplicate submissions and direct completion', function () {
    $this->postJson($this->driverUrl.'/failed-attempt', ['photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=')), 'reason_code' => 'business_closed'])->assertOk();
    $this->postJson($this->driverUrl.'/failed-attempt', ['photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=')), 'reason_code' => 'business_closed'])->assertUnprocessable();
    expect(DeliveryAttempt::count())->toBe(1);
    $this->getJson('/api/driver/deliveries')->assertOk()->assertJsonPath('data.0.status', 'failed_attempt')
        ->assertJsonPath('data.0.delivery_attempt.latest_attempt.reason_label', 'Business closed');
    $this->post($this->driverUrl.'/deliver', ['photo' => UploadedFile::fake()->create('proof.jpg', 1, 'image/jpeg')], ['Accept' => 'application/json'])->assertUnprocessable();
    expect($this->order->fresh()->status)->toBe('Failed Delivery Attempt');
});

it('requires the assigned courier and an active delivery leg', function () {
    failedDeliveryActAs(makeCourier());
    $this->postJson($this->driverUrl.'/failed-attempt', ['photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=')), 'reason_code' => 'package_damaged'])->assertNotFound();
    $this->postJson($this->driverUrl.'/reattempt')->assertNotFound();
    failedDeliveryActAs($this->courier);
    $this->assignment->update(['status' => ParcelAssignment::STATUS_ASSIGNED]);
    $this->postJson($this->driverUrl.'/failed-attempt', ['photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=')), 'reason_code' => 'package_damaged'])->assertUnprocessable();
    $this->assignment->update(['status' => ParcelAssignment::STATUS_HANDED_OFF, 'transfer_to_company_id' => $this->company->id]);
    $this->postJson($this->driverUrl.'/failed-attempt', ['photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=')), 'reason_code' => 'package_damaged'])->assertUnprocessable();
});

it('escalates the third failure, preserves all history and grants exactly one additional attempt', function () {
    foreach ([1, 2, 3] as $number) {
        $this->travelTo(CarbonImmutable::parse('2026-10-05T01:00:00Z')->addDays($number - 1));
        $this->postJson($this->driverUrl.'/failed-attempt', ['photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=')), 'reason_code' => 'access_denied'])->assertOk();
        if ($number < 3) {
            $this->travelTo(CarbonImmutable::parse('2026-10-05T16:00:00Z')->addDays($number - 1));
            $this->postJson($this->driverUrl.'/reattempt')->assertOk();
        }
    }
    expect($this->order->fresh()->status)->toBe('Needs Dispatcher Review');
    expect(DeliveryAttempt::where('order_id', $this->order->id)->count())->toBe(3);
    $this->travelTo(CarbonImmutable::parse('2026-10-07T16:00:00Z'));
    $this->postJson($this->driverUrl.'/reattempt')->assertUnprocessable();
    failedDeliveryActAs($this->owner);
    $this->getJson($this->dispatchUrl.'/details')->assertOk()->assertJsonCount(3, 'data.delivery_attempts');
    $this->postJson($this->dispatchUrl.'/approve-reattempt')->assertOk()->assertJsonPath('data.delivery_attempt.attempt_limit', 4);
    $this->postJson($this->dispatchUrl.'/approve-reattempt')->assertUnprocessable();
    failedDeliveryActAs($this->courier);
    $this->postJson($this->driverUrl.'/reattempt')->assertOk();
    $this->postJson($this->driverUrl.'/failed-attempt', ['photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=')), 'reason_code' => 'access_denied'])->assertOk()->assertJsonPath('data.status', 'needs_dispatcher_review');
    expect(DeliveryAttempt::count())->toBe(4);
});

it('honors the configurable limit and keeps same-day retries blocked after approval or reassignment', function () {
    config(['delivery.attempt_limit' => 1]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05T04:00:00Z'));
    $this->postJson($this->driverUrl.'/failed-attempt', ['photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=')), 'reason_code' => 'incorrect_address'])->assertOk()->assertJsonPath('data.status', 'needs_dispatcher_review');
    $retryAt = $this->order->fresh()->delivery_retry_at;
    $other = makeCourier();
    DB::table('courier_applications')->insert([
        'id' => (string) Str::uuid(), 'courier_profile_id' => $other->id,
        'logistics_company_id' => $this->company->id, 'status' => 'accepted',
    ]);
    failedDeliveryActAs($this->owner);
    $this->postJson($this->dispatchUrl.'/approve-reattempt')->assertOk();
    $this->postJson($this->dispatchUrl.'/reassign-failed', ['rider_profile_id' => $other->id])->assertOk();
    expect($this->order->fresh()->delivery_retry_at->equalTo($retryAt))->toBeTrue();
    expect($this->order->fresh()->delivery_attempt_limit)->toBe(2);
    failedDeliveryActAs($this->courier);
    $this->postJson($this->driverUrl.'/reattempt')->assertNotFound();
    failedDeliveryActAs($other);
    $this->postJson($this->driverUrl.'/reattempt')->assertUnprocessable();
    $this->travelTo($retryAt);
    $this->postJson($this->driverUrl.'/reattempt')->assertOk();
    expect(DeliveryAttempt::first()->courier_id)->toBe($this->courier->id);
    expect($this->order->fresh()->statusHistory->pluck('note')->filter(fn ($note) => str_contains($note ?? '', 'reassigned'))->count())->toBe(1);
});

it('scopes dispatcher actions to the owning company', function () {
    config(['delivery.attempt_limit' => 1]);
    $this->postJson($this->driverUrl.'/failed-attempt', ['photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=')), 'reason_code' => 'weather_or_roads'])->assertOk();
    failedDeliveryActAs(makeLogistics());
    $this->postJson($this->dispatchUrl.'/approve-reattempt')->assertNotFound();
    expect($this->order->fresh()->status)->toBe('Needs Dispatcher Review');
});

it('blocks ordinary dispatcher actions from bypassing the retry workflow', function () {
    $this->postJson($this->driverUrl.'/failed-attempt', ['photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=')), 'reason_code' => 'recipient_unavailable'])->assertOk();
    DB::table('courier_applications')->insert([
        'id' => (string) Str::uuid(), 'courier_profile_id' => $this->courier->id,
        'logistics_company_id' => $this->company->id, 'status' => 'accepted',
    ]);
    failedDeliveryActAs($this->owner);
    $this->putJson($this->dispatchUrl.'/assign', [
        'rider_profile_id' => $this->courier->id,
        'barangay_assignment_id' => $this->assignment->barangay_assignment_id,
    ])->assertUnprocessable();
    $this->putJson($this->dispatchUrl.'/handoff')->assertUnprocessable();
    $this->putJson($this->dispatchUrl.'/auto-assign')->assertOk()->assertJsonPath('outcome', 'skipped');
    expect($this->assignment->fresh()->status)->toBe(ParcelAssignment::STATUS_FAILED_ATTEMPT);
    expect($this->order->fresh()->canTransitionTo('In Transit'))->toBeFalse();
});

it('records a return-process flag without starting a return shipment or permitting courier retries', function () {
    config(['delivery.attempt_limit' => 1]);
    $this->postJson($this->driverUrl.'/failed-attempt', ['photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1sAAAAASUVORK5CYII=')), 'reason_code' => 'refused_delivery'])->assertOk();
    failedDeliveryActAs($this->owner);
    $response = $this->postJson($this->dispatchUrl.'/flag-failed-return')->assertOk()
        ->assertJsonPath('data.status', 'needs_dispatcher_review');
    expect($response->json('data.delivery_attempt.return_flagged_at'))->not->toBeNull();
    expect($this->assignment->fresh()->return_request_id)->toBeNull();
    $this->postJson($this->dispatchUrl.'/flag-failed-return')->assertUnprocessable();
    failedDeliveryActAs($this->courier);
    $this->travelTo($this->order->fresh()->delivery_retry_at);
    $this->postJson($this->driverUrl.'/reattempt')->assertUnprocessable();
    failedDeliveryActAs($this->owner);
    $this->postJson($this->dispatchUrl.'/approve-reattempt')->assertOk()->assertJsonPath('data.delivery_attempt.return_flagged_at', null);
});
