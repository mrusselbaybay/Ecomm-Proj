<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remembers which courier ran the *pickup* leg once they confirm it
     * (Driver\DriverDeliveryController::pickup) — at that moment
     * rider_profile_id is cleared so the parcel returns to the logistics
     * queue for delivery dispatch, but the pickup courier still needs a
     * read-only record of the parcels they collected. The Deliveries API
     * keys off this column to keep showing them that row (no scan / no
     * "mark delivered" — it's someone else's leg now).
     *
     * Guarded with hasTable(), same as the delivery_photo_path /
     * pickup_photo_path migrations, since parcel_assignments only reliably
     * exists in the real Supabase database.
     */
    public function up(): void
    {
        if (! Schema::hasTable('parcel_assignments')) {
            return;
        }

        Schema::table('parcel_assignments', function (Blueprint $table) {
            $table->uuid('picked_up_by')->nullable()->after('rider_profile_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('parcel_assignments')) {
            return;
        }

        Schema::table('parcel_assignments', function (Blueprint $table) {
            $table->dropColumn(['picked_up_by']);
        });
    }
};
