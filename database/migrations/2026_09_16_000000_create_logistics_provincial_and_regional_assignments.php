<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two broader fallback tiers above barangay coverage, each a POOL of
 * riders (unlike a barangay, which has at most one) — see
 * App\Services\ParcelAutoAssignService:
 *
 *   - Provincial: every barangay in the company's own province that isn't
 *     individually covered falls back here. Car or van riders only.
 *   - Regional: anything outside the company's own region falls back
 *     here. Van riders only (the only vehicle presumed capable of an
 *     inter-region haul).
 *
 * Auto-provisioned (one of each, at most, per company) off the company's
 * own registered address the moment they have any barangay coverage — see
 * LogisticsBarangayAssignmentController — rather than something staff
 * create by hand.
 *
 * A rider sits in at most one of: a barangay's rider_profile_id, this
 * provincial pool, or the regional pool — never more than one area,
 * company-wide (enforced in the controllers, same rule as barangays).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_provincial_assignments', function (Blueprint $table) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('logistics_company_id');
            $table->string('region_name', 150);
            $table->string('province_name', 150);
            $table->boolean('is_active')->default(true);
            // Round-robin cursor for this pool, same mechanism as
            // logistics_companies.last_auto_assigned_rider_profile_id.
            $table->uuid('last_auto_assigned_rider_profile_id')->nullable();
            $table->timestampsTz();

            $table->unique(['logistics_company_id', 'province_name'], 'logistics_provincial_unique');
        });

        Schema::create('logistics_provincial_assignment_riders', function (Blueprint $table) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('provincial_assignment_id');
            $table->uuid('rider_profile_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['provincial_assignment_id', 'rider_profile_id'], 'logistics_provincial_rider_unique');
            // A rider is in at most one provincial pool company-wide —
            // enforced at the app level alongside the cross-tier check,
            // but a unique index on its own catches the simple case too.
            $table->unique('rider_profile_id', 'logistics_provincial_rider_solo');
        });

        Schema::create('logistics_regional_assignments', function (Blueprint $table) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('logistics_company_id');
            $table->string('region_name', 150);
            $table->boolean('is_active')->default(true);
            $table->uuid('last_auto_assigned_rider_profile_id')->nullable();
            $table->timestampsTz();

            $table->unique(['logistics_company_id', 'region_name'], 'logistics_regional_unique');
        });

        Schema::create('logistics_regional_assignment_riders', function (Blueprint $table) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('regional_assignment_id');
            $table->uuid('rider_profile_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['regional_assignment_id', 'rider_profile_id'], 'logistics_regional_rider_unique');
            $table->unique('rider_profile_id', 'logistics_regional_rider_solo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_regional_assignment_riders');
        Schema::dropIfExists('logistics_regional_assignments');
        Schema::dropIfExists('logistics_provincial_assignment_riders');
        Schema::dropIfExists('logistics_provincial_assignments');
    }
};
