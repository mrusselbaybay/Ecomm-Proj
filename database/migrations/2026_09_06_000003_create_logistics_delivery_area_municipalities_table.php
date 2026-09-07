<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A delivery area still pins exactly one province, but can now cover
 * several municipalities/cities within it (each optionally narrowed to
 * one barangay, same as the single municipality_name/barangay pair used
 * to work) — one row per municipality here instead of two columns on
 * logistics_delivery_areas.
 *
 * Every existing area had exactly one municipality, so this migrates
 * each one into a single row here before dropping the old columns —
 * no data is lost, and ParcelIntakeService::matchingArea() is updated
 * (see the service) to match against this table instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('logistics_delivery_areas')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('logistics_delivery_area_municipalities', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('delivery_area_id');
            $table->string('municipality_code', 20)->nullable();
            $table->string('municipality_name', 150);
            $table->string('barangay', 150)->nullable();
            $table->timestampsTz();

            $table->foreign('delivery_area_id')
                ->references('id')->on('logistics_delivery_areas')
                ->onDelete('cascade');
            $table->unique(['delivery_area_id', 'municipality_name']);
        });

        // Carry every existing area's single municipality/barangay over as
        // its first (and, until edited, only) row in the new table.
        DB::table('logistics_delivery_areas')
            ->select('id', 'municipality_name', 'barangay')
            ->whereNotNull('municipality_name')
            ->orderBy('id')
            ->get()
            ->each(function ($area): void {
                DB::table('logistics_delivery_area_municipalities')->insert([
                    'id' => (string) Str::uuid(),
                    'delivery_area_id' => $area->id,
                    'municipality_code' => null,
                    'municipality_name' => $area->municipality_name,
                    'barangay' => $area->barangay,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('logistics_delivery_areas', function (Blueprint $table) {
            $table->dropColumn(['municipality_name', 'barangay']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('logistics_delivery_areas')) {
            return;
        }

        Schema::table('logistics_delivery_areas', function (Blueprint $table) {
            $table->string('municipality_name', 150)->nullable();
            $table->string('barangay', 150)->nullable();
        });

        if (Schema::hasTable('logistics_delivery_area_municipalities')) {
            DB::table('logistics_delivery_area_municipalities')
                ->orderBy('created_at')
                ->get()
                ->groupBy('delivery_area_id')
                ->each(function ($rows, $areaId): void {
                    $first = $rows->first();

                    DB::table('logistics_delivery_areas')
                        ->where('id', $areaId)
                        ->update([
                            'municipality_name' => $first->municipality_name,
                            'barangay' => $first->barangay,
                        ]);
                });
        }

        Schema::dropIfExists('logistics_delivery_area_municipalities');
    }
};
