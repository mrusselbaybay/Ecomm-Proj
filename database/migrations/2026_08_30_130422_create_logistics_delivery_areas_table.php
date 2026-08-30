<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('logistics_delivery_areas', function (Blueprint $table) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('logistics_company_id');
            $table->string('name', 100);
            $table->string('province_name', 150);
            $table->string('municipality_name', 150);
            $table->string('barangay', 150)->nullable();
            $table->uuid('rider_profile_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['logistics_company_id', 'name']);
            $table->index(['logistics_company_id', 'is_active']);
            $table->index('rider_profile_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logistics_delivery_areas');
    }
};
