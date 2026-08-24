<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * order_items already has a free-text `variant` column (label shown in
 * the seller Orders UI). This adds the structured snapshot alongside it:
 * variant_id (reference, nulled if the variant is later deleted —
 * order_items keeps its own copy of everything needed to display the
 * order regardless), variant_sku, and variant_options (the exact
 * {option name: value} pairs at the time of purchase, independent of
 * later edits to the product's options).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::table('order_items', function (Blueprint $table) use ($driver) {
            $table->uuid('variant_id')->nullable()->after('product_id');
            $table->string('variant_sku')->nullable()->after('variant');
            $table->jsonb('variant_options')->nullable()->after('variant_sku');

            if ($driver === 'pgsql') {
                $table->foreign('variant_id')->references('id')->on('product_variants')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['variant_id', 'variant_sku', 'variant_options']);
        });
    }
};
