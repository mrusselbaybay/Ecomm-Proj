<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Backs the rider's "Mark as picked up" action
     * (Driver\DriverDeliveryController::pickup) — a photo of the parcel at
     * the sorting hub is required before the assignment can move from
     * 'assigned' ("To pick up") to 'handed_off' ("To deliver") from the
     * rider's own side, mirroring the delivery_photo_path column added by
     * 2026_09_02_124521_add_delivery_photo_columns_to_parcel_assignments_table
     * for the drop-off end of the same trip. Guarded with hasTable(), same
     * as that migration, since parcel_assignments only reliably exists in
     * the real Supabase database.
     */
    public function up(): void
    {
        if (! Schema::hasTable('parcel_assignments')) {
            return;
        }

        Schema::table('parcel_assignments', function (Blueprint $table) {
            $table->string('pickup_photo_path')->nullable()->after('handed_off_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('parcel_assignments')) {
            return;
        }

        Schema::table('parcel_assignments', function (Blueprint $table) {
            $table->dropColumn(['pickup_photo_path']);
        });
    }
};
