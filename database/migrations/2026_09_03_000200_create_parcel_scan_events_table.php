<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An append-only log of parcel-confirmation-QR scans. One row is written
 * each time a courier's scan resolves the order's confirmation_token at a
 * checkpoint:
 *
 *   - 'verify'   — the courier scanned to look the parcel up (no state change)
 *   - 'pickup'   — scanned while confirming pickup  ('assigned' -> 'handed_off')
 *   - 'delivery' — scanned while confirming delivery (order -> 'Delivered')
 *
 * Written by Driver\DriverDeliveryController. The photo-based proof on the
 * pickup/deliver actions is unchanged — a scan is recorded alongside it when
 * the app sends the token, never in place of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('parcel_scan_events')) {
            return;
        }

        Schema::create('parcel_scan_events', function (Blueprint $table) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('order_id');
            $table->uuid('parcel_assignment_id')->nullable();
            $table->string('checkpoint', 30);
            $table->uuid('scanned_by')->nullable();
            $table->string('scanned_by_role', 30)->nullable();
            $table->string('note')->nullable();
            $table->timestampTz('scanned_at');
            $table->timestampsTz();

            $table->index(['order_id', 'checkpoint']);
            $table->index('parcel_assignment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_scan_events');
    }
};
