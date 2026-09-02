<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Backs the rider's "Mark as Delivered" action (Driver\DriverDeliveryController
     * ::deliver) — a photo of the parcel is required before the assignment
     * (and its order) can be completed. Guarded with hasTable(), same as
     * the courier_applications license migration, since parcel_assignments
     * only reliably exists in the real Supabase database.
     */
    public function up(): void
    {
        if (! Schema::hasTable('parcel_assignments')) {
            return;
        }

        Schema::table('parcel_assignments', function (Blueprint $table) {
            $table->timestampTz('delivered_at')->nullable()->after('handed_off_at');
            $table->string('delivery_photo_path')->nullable()->after('delivered_at');
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
            $table->dropColumn(['delivered_at', 'delivery_photo_path']);
        });
    }
};
