<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-wide round-robin cursor for App\Services\ParcelAutoAssignService's
 * fallback pool — used when a parcel's barangay has no courier assigned, so
 * it rotates across every eligible rider in the company instead. The
 * per-barangay assignment itself needs no cursor (exactly one rider per
 * barangay, see logistics_barangay_assignments).
 *
 * Deliberately not a foreign key, same reasoning as the old
 * logistics_delivery_areas.last_auto_assigned_rider_profile_id it
 * replaces: a bookmark, not an appointment.
 *
 * Like the other Supabase-native tables (see
 * 2026_09_04_000000_add_hiring_fields_to_logistics_companies_table),
 * `logistics_companies` is NOT Laravel-migrated in production — this
 * migration is guarded and a no-op against the real database and the
 * feature suite (which builds its own `logistics_companies` schema per
 * test). Run this by hand against Supabase:
 *
 *   alter table public.logistics_companies
 *     add column if not exists last_auto_assigned_rider_profile_id uuid;
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('logistics_companies')) {
            return;
        }

        if (Schema::hasColumn('logistics_companies', 'last_auto_assigned_rider_profile_id')) {
            return;
        }

        Schema::table('logistics_companies', function (Blueprint $table): void {
            $table->uuid('last_auto_assigned_rider_profile_id')->nullable();
        });
    }

    public function down(): void
    {
        // Never drop columns on the real database — it isn't ours. Only
        // reversible on a throwaway (non-pgsql) test database.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            return;
        }

        if (! Schema::hasTable('logistics_companies') || ! Schema::hasColumn('logistics_companies', 'last_auto_assigned_rider_profile_id')) {
            return;
        }

        Schema::table('logistics_companies', function (Blueprint $table): void {
            $table->dropColumn('last_auto_assigned_rider_profile_id');
        });
    }
};
