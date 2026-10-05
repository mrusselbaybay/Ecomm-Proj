<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shared public.addresses table (Profile::address()), which the store
 * directory reads for a seller's city / province.
 *
 * In production it already exists in the Supabase database (written by the
 * seller / logistics registration flows), so this is a no-op there. It only
 * creates the table on a fresh database such as the sqlite :memory: test
 * database, with the columns App\Models\Address declares. No foreign keys,
 * same as 2026_08_18_000000_create_supabase_baseline_tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('addresses')) {
            return;
        }

        Schema::create('addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner_kind');
            $table->uuid('profile_id')->nullable();
            $table->uuid('logistics_company_id')->nullable();
            $table->string('region_code')->nullable();
            $table->string('region_name')->nullable();
            $table->string('province_code')->nullable();
            $table->string('province_name')->nullable();
            $table->string('municipality_code')->nullable();
            $table->string('municipality_name')->nullable();
            $table->string('barangay')->nullable();
            $table->string('street')->nullable();
            $table->string('house_no')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        // Never drop the shared production table; only the test copy.
        if (app()->environment('testing')) {
            Schema::dropIfExists('addresses');
        }
    }
};
