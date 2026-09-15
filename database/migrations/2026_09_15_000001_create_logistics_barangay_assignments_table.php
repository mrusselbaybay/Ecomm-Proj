<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces logistics_delivery_areas / logistics_delivery_area_municipalities
 * / logistics_delivery_area_riders with one row per barangay a company
 * covers — assignment is now "one courier per barangay" rather than a named
 * area spanning several municipalities with a rider roster. See
 * App\Services\ParcelIntakeService::matchingBarangayAssignment().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_barangay_assignments', function (Blueprint $table) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('logistics_company_id');
            $table->string('province_name', 150);
            $table->string('municipality_code', 20)->nullable();
            $table->string('municipality_name', 150);
            $table->string('barangay', 150);
            // A barangay has at most one assigned courier — a rider may
            // still cover several barangays, so this is not unique on its
            // own. Deliberately not a foreign key, same as
            // parcel_assignments.rider_profile_id: a rider who leaves the
            // company just stops being eligible, the row itself is left
            // for staff to reassign rather than cascading a delete.
            $table->uuid('rider_profile_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['logistics_company_id', 'municipality_name', 'barangay'], 'logistics_barangay_unique');
            $table->index(['logistics_company_id', 'is_active']);
            $table->index('rider_profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_barangay_assignments');
    }
};
