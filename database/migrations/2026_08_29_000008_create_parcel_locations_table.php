<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Location pings for an in-transit order — the read side of live parcel
 * tracking (OrderTrackingService reads the latest ping + a short trail;
 * OrderJourneyMap.vue polls and animates the courier marker between them).
 *
 * Rows are written by:
 *   - the `tracking:simulate` artisan command (source = 'simulator') for
 *     demos / until a real courier feed exists;
 *   - POST /api/logistics/orders/{n}/location from a courier client
 *     (source = the poster's role), once such a client is built.
 *
 * There is deliberately no unique constraint: a parcel reports many pings
 * over its journey and the newest `recorded_at` wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('parcel_locations')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('parcel_locations', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('order_id');
            $table->decimal('lat', 9, 6);
            $table->decimal('lng', 9, 6);
            $table->timestampTz('recorded_at');
            $table->string('source', 32)->default('courier');
            $table->decimal('speed_kph', 6, 2)->nullable();
            $table->smallInteger('heading')->nullable();
            $table->string('note')->nullable();
            $table->timestampsTz();

            $table->index(['order_id', 'recorded_at']);

            if ($driver === 'pgsql') {
                $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_locations');
    }
};
