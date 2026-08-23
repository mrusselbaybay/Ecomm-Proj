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
        $table->timestamps();
    });
});

it('lists every logistics company account with its available regions', function () {
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
        ],
    ]);

    $response = $this->getJson('/api/courier/logistics-companies');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.company_name', 'Luzon Express')
        ->assertJsonPath('data.1.company_name', 'Visayas Delivery')
        ->assertJsonPath('meta.regions', ['Luzon', 'Visayas'])
        ->assertJsonMissingPath('data.0.tin')
        ->assertJsonMissingPath('data.0.sec_registration');
});

it('filters logistics company accounts by region and company details', function () {
    DB::table('logistics_companies')->insert([
        [
            'id' => 'company-luzon',
            'owner_profile_id' => 'owner-luzon',
            'company_name' => 'Luzon Express',
            'company_email' => 'hello@luzon.test',
            'company_contact_no' => '09170000001',
            'tin' => '123',
            'region' => 'Luzon',
        ],
        [
            'id' => 'company-visayas',
            'owner_profile_id' => 'owner-visayas',
            'company_name' => 'Visayas Delivery',
            'company_email' => 'hello@visayas.test',
            'company_contact_no' => '09170000002',
            'tin' => '456',
            'region' => 'Visayas',
        ],
    ]);

    $response = $this->getJson('/api/courier/logistics-companies?region=Luzon&search=express');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', 'company-luzon');
});
