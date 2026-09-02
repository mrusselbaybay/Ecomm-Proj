<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
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
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('contact_no')->nullable();
        });
    }

    Schema::create('logistics_companies', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('owner_profile_id')->unique();
        $table->string('company_name');
        $table->string('company_email');
        $table->string('company_contact_no');
        $table->string('tin');
        $table->string('status')->default('pending');
        $table->string('account_status')->default('pending');
        $table->string('region')->nullable();
        $table->timestamps();
    });

    Schema::create('courier_details', function (Blueprint $table) {
        $table->string('profile_id')->primary();
        $table->string('vehicle');
        $table->string('plate_number');
        $table->string('logistics_company_id')->nullable();
    });

    Schema::create('courier_applications', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('courier_profile_id');
        $table->string('logistics_company_id');
        $table->string('status')->default('pending');
        $table->timestamp('applied_at')->nullable();
        $table->string('reviewed_by')->nullable();
        $table->timestamp('reviewed_at')->nullable();
        $table->text('rejection_reason')->nullable();
        $table->timestamps();
    });

    Http::fake(['*' => Http::response(['id' => 'logistics-owner'])]);
});

it('returns only applications sent to the signed-in logistics company', function () {
    DB::table('logistics_companies')->insert([
        'id' => 'company-a',
        'owner_profile_id' => 'logistics-owner',
        'company_name' => 'Company A',
        'company_email' => 'a@example.test',
        'company_contact_no' => '09170000001',
        'tin' => '123',
    ]);
    DB::table('profiles')->insert([
        'id' => 'courier-a',
        'role' => 'courier',
        'first_name' => 'Ana',
        'last_name' => 'Cruz',
        'email' => 'ana@example.test',
    ]);
    DB::table('courier_details')->insert([
        'profile_id' => 'courier-a',
        'vehicle' => 'Motorcycle',
        'plate_number' => 'ABC-1234',
    ]);
    DB::table('courier_applications')->insert([
        [
            'id' => 'application-a',
            'courier_profile_id' => 'courier-a',
            'logistics_company_id' => 'company-a',
            'status' => 'pending',
            'applied_at' => now(),
        ],
        [
            'id' => 'application-other-company',
            'courier_profile_id' => 'courier-a',
            'logistics_company_id' => 'company-b',
            'status' => 'pending',
            'applied_at' => now(),
        ],
    ]);

    $response = $this->withToken('valid-access-token')
        ->getJson('/api/logistics/applications?status=pending&search=ana');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', 'application-a')
        ->assertJsonPath('data.0.courier.first_name', 'Ana')
        ->assertJsonPath('data.0.courier_details.vehicle', 'Motorcycle');
});

it('rejects requests without a Supabase access token', function () {
    $this->getJson('/api/logistics/applications')
        ->assertUnauthorized();
});

it('returns a service unavailable response when Supabase authentication cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('TLS unavailable'));

    $this->withToken('valid-access-token')
        ->getJson('/api/logistics/applications')
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'The authentication service is temporarily unavailable.');
});

it('persists a courier application through the Laravel API', function () {
    $courierId = 'logistics-owner';
    $companyId = '4e5d343e-4318-49fe-af20-126416f3c53f';

    DB::table('profiles')->insert([
        'id' => $courierId,
        'role' => 'courier',
    ]);
    DB::table('logistics_companies')->insert([
        'id' => $companyId,
        'owner_profile_id' => 'logistics-owner',
        'company_name' => 'Company A',
        'company_email' => 'a@example.test',
        'company_contact_no' => '09170000001',
        'tin' => '123',
    ]);
    $response = $this->withToken('valid-access-token')
        ->postJson('/api/courier/applications', [
            'logistics_company_id' => $companyId,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.logistics_company_id', $companyId)
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('courier_applications', [
        'courier_profile_id' => $courierId,
        'logistics_company_id' => $companyId,
        'status' => 'pending',
    ]);
});

it('reactivates a withdrawn application when the courier applies again', function () {
    $courierId = 'logistics-owner';
    $companyId = '4e5d343e-4318-49fe-af20-126416f3c53f';

    DB::table('profiles')->insert([
        'id' => $courierId,
        'role' => 'courier',
    ]);
    DB::table('logistics_companies')->insert([
        'id' => $companyId,
        'owner_profile_id' => 'company-owner',
        'company_name' => 'Company A',
        'company_email' => 'a@example.test',
        'company_contact_no' => '09170000001',
        'tin' => '123',
    ]);
    DB::table('courier_applications')->insert([
        'id' => 'existing-application',
        'courier_profile_id' => $courierId,
        'logistics_company_id' => $companyId,
        'status' => 'withdrawn',
        'applied_at' => now()->subDay(),
        'reviewed_by' => 'reviewer',
        'reviewed_at' => now()->subDay(),
        'rejection_reason' => 'Withdrawn by courier.',
    ]);

    $response = $this->withToken('valid-access-token')
        ->postJson('/api/courier/applications', [
            'logistics_company_id' => $companyId,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.id', 'existing-application')
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('courier_applications', [
        'id' => 'existing-application',
        'status' => 'pending',
        'reviewed_by' => null,
        'reviewed_at' => null,
        'rejection_reason' => null,
    ]);
});

it('withdraws only the signed-in courier\'s pending application', function () {
    $courierId = 'logistics-owner';
    $applicationId = '59fb933e-f8c1-4f57-82ff-b3ab155309eb';

    DB::table('profiles')->insert([
        'id' => $courierId,
        'role' => 'courier',
    ]);
    DB::table('courier_applications')->insert([
        'id' => $applicationId,
        'courier_profile_id' => $courierId,
        'logistics_company_id' => 'company-a',
        'status' => 'pending',
        'applied_at' => now(),
    ]);

    $response = $this->withToken('valid-access-token')
        ->patchJson("/api/courier/applications/{$applicationId}/withdraw");

    $response->assertOk()
        ->assertJsonPath('data.id', $applicationId)
        ->assertJsonPath('data.status', 'withdrawn');

    $this->assertDatabaseHas('courier_applications', [
        'id' => $applicationId,
        'status' => 'withdrawn',
    ]);
});

it('does not withdraw a reviewed courier application', function () {
    $courierId = 'logistics-owner';
    $applicationId = '59fb933e-f8c1-4f57-82ff-b3ab155309eb';

    DB::table('profiles')->insert([
        'id' => $courierId,
        'role' => 'courier',
    ]);
    DB::table('courier_applications')->insert([
        'id' => $applicationId,
        'courier_profile_id' => $courierId,
        'logistics_company_id' => 'company-a',
        'status' => 'accepted',
        'applied_at' => now(),
    ]);

    $this->withToken('valid-access-token')
        ->patchJson("/api/courier/applications/{$applicationId}/withdraw")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only pending applications can be withdrawn.');

    $this->assertDatabaseHas('courier_applications', [
        'id' => $applicationId,
        'status' => 'accepted',
    ]);
});
