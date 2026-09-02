<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A delivery area now takes an arbitrary roster of riders (the "Assigned
 * drivers" tab on the area modal — Couriers.vue) instead of exactly one.
 * This pivot replaces logistics_delivery_areas.rider_profile_id, which
 * only ever allowed a single appointment.
 *
 * Existing single-rider appointments are backfilled into the pivot before
 * the old column is dropped, so nobody's current assignment is silently
 * lost by this change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_delivery_area_riders', function (Blueprint $table) {
            $table->uuid('delivery_area_id');
            $table->uuid('rider_profile_id');
            $table->timestampTz('created_at')->nullable();

            $table->primary(['delivery_area_id', 'rider_profile_id']);
            $table->index('rider_profile_id');

            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->foreign('delivery_area_id')
                    ->references('id')->on('logistics_delivery_areas')
                    ->cascadeOnDelete();
            }
        });

        if (Schema::hasColumn('logistics_delivery_areas', 'rider_profile_id')) {
            DB::table('logistics_delivery_areas')
                ->whereNotNull('rider_profile_id')
                ->orderBy('id')
                ->each(function (object $area): void {
                    DB::table('logistics_delivery_area_riders')->insert([
                        'delivery_area_id' => $area->id,
                        'rider_profile_id' => $area->rider_profile_id,
                        'created_at' => $area->updated_at ?? now(),
                    ]);
                });

            Schema::table('logistics_delivery_areas', function (Blueprint $table) {
                // Drop the index explicitly first — SQLite won't drop a
                // column that a named index still references.
                $table->dropIndex(['rider_profile_id']);
                $table->dropColumn('rider_profile_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('logistics_delivery_areas', 'rider_profile_id')) {
            Schema::table('logistics_delivery_areas', function (Blueprint $table) {
                $table->uuid('rider_profile_id')->nullable();
            });

            // Only a single rider fits back into the old column — take
            // whichever one was added first per area.
            DB::table('logistics_delivery_area_riders')
                ->select('delivery_area_id', 'rider_profile_id')
                ->orderBy('created_at')
                ->get()
                ->unique('delivery_area_id')
                ->each(function (object $row): void {
                    DB::table('logistics_delivery_areas')
                        ->where('id', $row->delivery_area_id)
                        ->update(['rider_profile_id' => $row->rider_profile_id]);
                });
        }

        Schema::dropIfExists('logistics_delivery_area_riders');
    }
};
