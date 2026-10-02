<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local copy of the PSGC reference data (psgc.gitlab.io) so address
 * dropdowns don't depend on the upstream API being fast or reachable.
 * Filled by `php artisan psgc:sync`; PsgcProxyController falls back to the
 * live API while these are empty. Codes are the 9-digit PSGC codes the
 * upstream API (and every saved address) already uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('psgc_regions', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name');
            $table->string('region_name')->nullable();
            $table->string('island_group_code', 20)->nullable();
        });

        Schema::create('psgc_provinces', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name');
            $table->string('region_code', 10)->index();
            $table->string('island_group_code', 20)->nullable();
        });

        Schema::create('psgc_cities_municipalities', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name');
            $table->boolean('is_city')->default(false);
            $table->boolean('is_capital')->default(false);
            // Null for NCR cities, which belong to no province upstream.
            $table->string('province_code', 10)->nullable()->index();
            $table->string('region_code', 10)->index();
            $table->string('island_group_code', 20)->nullable();
        });

        Schema::create('psgc_barangays', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name');
            // Upstream cityCode or municipalityCode, whichever is set.
            $table->string('city_municipality_code', 10)->index();
            $table->string('province_code', 10)->nullable();
            $table->string('region_code', 10);
            $table->string('island_group_code', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psgc_barangays');
        Schema::dropIfExists('psgc_cities_municipalities');
        Schema::dropIfExists('psgc_provinces');
        Schema::dropIfExists('psgc_regions');
    }
};
