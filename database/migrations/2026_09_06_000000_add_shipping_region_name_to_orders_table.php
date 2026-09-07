<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The buyer's island-group region (Luzon/Visayas/Mindanao) for the
 * shipping destination, captured at checkout alongside the existing
 * shipping_province_name/municipality_name/barangay snapshot. Needed to
 * detect a cross-region parcel (seller region != buyer region) so
 * ParcelIntakeService can flag it "To Transfer" instead of routing it
 * within the receiving logistics company.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_region_name')->nullable()->after('recipient_contact_no');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_region_name']);
        });
    }
};
