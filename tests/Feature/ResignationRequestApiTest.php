<?php

use App\Models\CourierApplication;
use App\Models\LogisticsCompany;
use App\Models\ResignationRequest;
use App\Services\SupabaseStorageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    // logistics_companies / courier_applications / courier_details are
    // Supabase-managed (no Laravel migration) — recreate the ad hoc shapes
    // these tests need, same approach as LogisticsApplicationApiTest.
    Schema::create('logistics_companies', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('owner_profile_id');
        $table->string('company_name');
        $table->string('company_email')->nullable();
        $table->string('company_contact_no')->nullable();
        $table->string('region')->nullable();
        $table->text('description')->nullable();
        $table->decimal('monthly_salary', 12, 2)->nullable();
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
        $table->string('status')->default('pending');
        $table->string('resume_path')->nullable();
        $table->string('license_path')->nullable();
        $table->timestamp('applied_at')->nullable();
        $table->string('reviewed_by')->nullable();
        $table->timestamp('reviewed_at')->nullable();
        $table->text('rejection_reason')->nullable();
        $table->timestamps();
    });

    $this->mock(SupabaseStorageService::class, function ($mock) {
        $mock->shouldReceive('upload')->andReturnNull();
        $mock->shouldReceive('signedUrl')->andReturn('https://example.test/letter.pdf');
    });
});

function makeEmployedCourier(): array
{
    $courier = makeCourier();
    $company = LogisticsCompany::create([
        'id' => (string) Str::uuid(),
        'owner_profile_id' => 'logistics-owner',
        'company_name' => 'Luzon Logistics',
        'company_contact_no' => '+63 900 000 0000',
        'region' => 'NCR',
        'monthly_salary' => 18000,
        'status' => 'approved',
        'account_status' => 'active',
    ]);
    $application = CourierApplication::create([
        'id' => (string) Str::uuid(),
        'courier_profile_id' => $courier->id,
        'logistics_company_id' => $company->id,
        'status' => CourierApplication::STATUS_ACCEPTED,
        'applied_at' => now()->subMonth(),
        'reviewed_at' => now()->subMonth(),
    ]);

    return [$courier, $company, $application];
}

function actingAsLogisticsOwner(): void
{
    config([
        'services.supabase.url' => 'https://unit-test.supabase.co',
        'services.supabase.anon_key' => 'test-anon-key',
    ]);
    Http::fake(['https://unit-test.supabase.co/auth/v1/user' => Http::response(['id' => 'logistics-owner'], 200)]);
    test()->withHeader('Authorization', 'Bearer logistics-token');
}

it('exposes the employer details and salary on the employment endpoint', function () {
    [$courier] = makeEmployedCourier();
    actingAsDriver($courier);

    $this->getJson('/api/courier/profile/employment')
        ->assertOk()
        ->assertJsonPath('data.is_employed', true)
        ->assertJsonPath('data.company_name', 'Luzon Logistics')
        ->assertJsonPath('data.company_contact_no', '+63 900 000 0000')
        ->assertJsonPath('data.region', 'NCR')
        ->assertJsonPath('data.monthly_salary', 18000)
        ->assertJsonPath('data.pending_resignation', null);
});

it('blocks a resignation when the courier is not employed', function () {
    $courier = makeCourier();
    actingAsDriver($courier);

    $this->postJson('/api/courier/resignation-requests', [
        'letter' => UploadedFile::fake()->create('letter.pdf', 20, 'application/pdf'),
    ])->assertStatus(422)->assertJsonPath('message', "You're not currently employed by a logistics company.");
});

it('lets an employed courier submit one resignation request and blocks a second', function () {
    [$courier] = makeEmployedCourier();
    actingAsDriver($courier);

    $this->postJson('/api/courier/resignation-requests', [
        'letter' => UploadedFile::fake()->create('letter.pdf', 20, 'application/pdf'),
        'reason' => 'Relocating.',
    ])->assertCreated()->assertJsonPath('data.status', 'pending');

    $this->postJson('/api/courier/resignation-requests', [
        'letter' => UploadedFile::fake()->create('letter2.pdf', 20, 'application/pdf'),
    ])->assertStatus(422)->assertJsonPath('message', 'You already have a resignation request awaiting review.');

    // The employment endpoint now reports the pending request.
    $this->getJson('/api/courier/profile/employment')
        ->assertOk()
        ->assertJsonPath('data.pending_resignation.status', 'pending');
});

it('frees the courier when the logistics company approves the resignation', function () {
    [$courier, $company, $application] = makeEmployedCourier();
    $resignation = ResignationRequest::create([
        'courier_profile_id' => $courier->id,
        'logistics_company_id' => $company->id,
        'courier_application_id' => $application->id,
        'status' => ResignationRequest::STATUS_PENDING,
        'submitted_at' => now(),
    ]);

    actingAsLogisticsOwner();

    $this->getJson('/api/logistics/resignation-requests')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $resignation->id);

    $this->postJson("/api/logistics/resignation-requests/{$resignation->id}/approve", [
        'note' => 'Cleared. Best of luck.',
    ])->assertOk()->assertJsonPath('data.status', 'approved');

    expect($application->fresh()->status)->toBe(CourierApplication::STATUS_WITHDRAWN);

    // A second approve is rejected as already-reviewed.
    $this->postJson("/api/logistics/resignation-requests/{$resignation->id}/approve")
        ->assertStatus(422);
});

it('requires a reason to reject and keeps the courier employed', function () {
    [$courier, $company, $application] = makeEmployedCourier();
    $resignation = ResignationRequest::create([
        'courier_profile_id' => $courier->id,
        'logistics_company_id' => $company->id,
        'courier_application_id' => $application->id,
        'status' => ResignationRequest::STATUS_PENDING,
        'submitted_at' => now(),
    ]);

    actingAsLogisticsOwner();

    $this->postJson("/api/logistics/resignation-requests/{$resignation->id}/reject")
        ->assertStatus(422)->assertJsonValidationErrors('note');

    $this->postJson("/api/logistics/resignation-requests/{$resignation->id}/reject", [
        'note' => 'Please stay through the peak season.',
    ])->assertOk()->assertJsonPath('data.status', 'rejected');

    expect($application->fresh()->status)->toBe(CourierApplication::STATUS_ACCEPTED);
});

it('stops an employed courier from applying to another company', function () {
    [$courier] = makeEmployedCourier();
    $other = LogisticsCompany::create([
        'id' => (string) Str::uuid(),
        'owner_profile_id' => (string) Str::uuid(),
        'company_name' => 'Visayas Freight',
        'status' => 'approved',
        'account_status' => 'active',
    ]);

    actingAsDriver($courier);

    $this->postJson('/api/courier/applications', [
        'logistics_company_id' => $other->id,
        'resume' => UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf'),
        'license' => UploadedFile::fake()->create('license.pdf', 20, 'application/pdf'),
    ])->assertStatus(422)->assertJsonPath('message', "You're already employed by Luzon Logistics. Submit a resignation request before applying to another company.");
});
