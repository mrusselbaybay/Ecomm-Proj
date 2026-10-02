<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured PSGC destination on saved addresses — the same region /
 * province / municipality / barangay shape the Account page writes to
 * public.addresses — so an order shipped to a saved address routes
 * (delivery area matching, transfer detection, same-day eligibility) off
 * that address instead of the buyer's profile address.
 *
 * `city` / `province` keep holding the human-readable names; the codes
 * are new. Nullable so pre-existing free-text rows survive; the UI asks
 * the buyer to complete those before they can be used at checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyer_addresses', function (Blueprint $table) {
            $table->string('region_name')->nullable()->after('line1');
            $table->string('province_code', 20)->nullable()->after('region_name');
            $table->string('municipality_code', 20)->nullable()->after('province');
            $table->string('barangay')->nullable()->after('city');
            $table->timestampTz('last_used_at')->nullable()->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('buyer_addresses', function (Blueprint $table) {
            $table->dropColumn(['region_name', 'province_code', 'municipality_code', 'barangay', 'last_used_at']);
        });
    }
};
