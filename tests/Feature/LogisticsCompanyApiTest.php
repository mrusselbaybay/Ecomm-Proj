<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('logistics_companies', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('owner_profile_id')->unique();
        $table->string('company_name');
        $table->string('company_email');
        $table->string('company_contact_no');
        $table->string('tin');
        $table->string('sec_registration')->nullable();
        $table->string('status')->default('pending');
        $table->string('account_status')->default('pending');
        $table->string('region')->nullable();
        // Courier-recruitment fields edited from the logistics portal's
        // Account Settings page (see the 2026_09_04 migration).
        $table->text('description')->nullable();
        $table->decimal('monthly_salary', 12, 2)->nullable();
        $table->boolean('is_hiring')->default(false);
        $table->timestamps();
    });
});

it('lists only logistics companies that are hiring, with their available regions', function () {
    DB::table('logistics_companies')->insert([
        [
            'id' => 'company-luzon',
            'owner_profile_id' => 'owner-luzon',
            'company_name' => 'Luzon Express',
            'company_email' => 'hello@luzon.test',
            'company_contact_no' => '09170000001',
            'tin' => '123',
            'status' => 'approved',
            'account_status' => 'active',
            'region' => 'Luzon',
            'description' => 'Same-day parcel runs across Metro Manila.',
            'monthly_salary' => 18000,
            'is_hiring' => true,
        ],
        [
            'id' => 'company-visayas',
            'owner_profile_id' => 'owner-visayas',
            'company_name' => 'Visayas Delivery',
            'company_email' => 'hello@visayas.test',
            'company_contact_no' => '09170000002',
            'tin' => '456',
            'status' => 'pending',
            'account_status' => 'pending',
            'region' => 'Visayas',
            'description' => null,
            'monthly_salary' => null,
            'is_hiring' => true,
        ],
        [
            // Not hiring — must not appear, and its region must not leak
            // into the region filter list.
            'id' => 'company-mindanao',
            'owner_profile_id' => 'owner-mindanao',
            'company_name' => 'Mindanao Freight',
            'company_email' => 'hello@mindanao.test',
            'company_contact_no' => '09170000003',
            'tin' => '789',
            'status' => 'approved',
            'account_status' => 'active',
            'region' => 'Mindanao',
            'description' => null,
            'monthly_salary' => null,
            'is_hiring' => false,
        ],
    ]);

    $response = $this->getJson('/api/courier/logistics-companies');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.company_name', 'Luzon Express')
        ->assertJsonPath('data.1.company_name', 'Visayas Delivery')
        ->assertJsonPath('data.0.description', 'Same-day parcel runs across Metro Manila.')
        ->assertJsonPath('data.0.monthly_salary', 18000)
        ->assertJsonPath('data.0.is_hiring', true)
        ->assertJsonPath('data.1.monthly_salary', null)
        ->assertJsonPath('meta.regions', ['Luzon', 'Visayas'])
        ->assertJsonMissingPath('data.0.tin')
        ->assertJsonMissingPath('data.0.sec_registration');
});

it('excludes logistics companies that are not hiring', function () {
    DB::table('logistics_companies')->insert([
        [
            'id' => 'company-open',
            'owner_profile_id' => 'owner-open',
            'company_name' => 'Open Roles Logistics',
            'company_email' => 'jobs@open.test',
            'company_contact_no' => '09170000010',
            'tin' => '111',
            'region' => 'Luzon',
            'is_hiring' => true,
        ],
        [
            'id' => 'company-closed',
            'owner_profile_id' => 'owner-closed',
            'company_name' => 'Closed Roles Logistics',
            'company_email' => 'jobs@closed.test',
            'company_contact_no' => '09170000011',
            'tin' => '222',
            'region' => 'Luzon',
            'is_hiring' => false,
        ],
    ]);

    $response = $this->getJson('/api/courier/logistics-companies');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', 'company-open');
});

it('filters hiring logistics company accounts by region and company details', function () {
    DB::table('logistics_companies')->insert([
        [
            'id' => 'company-luzon',
            'owner_profile_id' => 'owner-luzon',
            'company_name' => 'Luzon Express',
            'company_email' => 'hello@luzon.test',
            'company_contact_no' => '09170000001',
            'tin' => '123',
            'region' => 'Luzon',
            'is_hiring' => true,
        ],
        [
            'id' => 'company-visayas',
            'owner_profile_id' => 'owner-visayas',
            'company_name' => 'Visayas Delivery',
            'company_email' => 'hello@visayas.test',
            'company_contact_no' => '09170000002',
            'tin' => '456',
            'region' => 'Visayas',
            'is_hiring' => true,
        ],
    ]);

    $response = $this->getJson('/api/courier/logistics-companies?region=Luzon&search=express');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', 'company-luzon');
});
