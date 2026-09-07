<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The round-robin cursor behind "Auto assign" on the Parcel sorting page
 * (App\Services\ParcelAutoAssignService).
 *
 * Auto-assignment rotates through an area's appointed riders one parcel
 * at a time, so it has to remember where the last rotation stopped —
 * otherwise every sweep would start at the first rider again and pile
 * everything onto them. The rotation is per area, so the cursor lives on
 * the area row rather than in one global counter.
 *
 * Deliberately NOT a foreign key: it's a "where were we" bookmark, not an
 * appointment. A rider who leaves the area (or the company) simply stops
 * matching the eligible list and the rotation restarts from the top —
 * see ParcelAutoAssignService::nextRider().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_delivery_areas', function (Blueprint $table): void {
            $table->uuid('last_auto_assigned_rider_profile_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('logistics_delivery_areas', function (Blueprint $table): void {
            $table->dropColumn('last_auto_assigned_rider_profile_id');
        });
    }
};
