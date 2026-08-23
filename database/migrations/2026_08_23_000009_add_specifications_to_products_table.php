<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Category-specific customer-facing specs (e.g. Pet Supplies: animal_type,
 * food_type; Electronics and Gadgets: connectivity, voltage) are stored as
 * validated JSONB rather than dozens of nullable columns — per task, and
 * consistent with the existing `dimensions`/`images` JSONB columns on this
 * same table. What keys are allowed and their allowed values are defined
 * centrally in App\Support\CategoryFieldConfig and enforced server-side in
 * SellerProductService; nothing here is trusted as submitted by the client.
 *
 * Deliberately separate from `dimensions`/`weight` (added in
 * 2026_08_23_000003): those two remain shipping-only measurements used
 * for logistics, per the task's explicit "pack weight is a customer spec,
 * shipping weight is for delivery" distinction.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->jsonb('specifications')->nullable()->after('dimensions');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('specifications');
        });
    }
};