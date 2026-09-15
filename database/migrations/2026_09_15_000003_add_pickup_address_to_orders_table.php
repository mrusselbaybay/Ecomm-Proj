<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The seller's pickup location, captured at checkout from the seller's own
 * profile address — mirrors the existing shipping_region_name/
 * shipping_province_name/shipping_municipality_name/shipping_barangay
 * snapshot of the buyer's address. Needed so
 * App\Services\TransferTriggerService can compare seller vs buyer location
 * (not the holding company's region vs buyer, as before) to decide whether
 * a parcel needs a bigger vehicle and/or a cross-company handoff.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('pickup_region_name')->nullable()->after('shipping_barangay');
            $table->string('pickup_province_name')->nullable()->after('pickup_region_name');
            $table->string('pickup_municipality_name')->nullable()->after('pickup_province_name');
            $table->string('pickup_barangay')->nullable()->after('pickup_municipality_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'pickup_region_name', 'pickup_province_name',
                'pickup_municipality_name', 'pickup_barangay',
            ]);
        });
    }
};
