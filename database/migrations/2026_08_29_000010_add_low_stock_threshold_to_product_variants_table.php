<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-variant low-stock threshold. Nullable — a variant with no value of
 * its own falls back to its product's low_stock_threshold, and then to
 * the app default (InventoryService::DEFAULT_LOW_STOCK_THRESHOLD).
 *
 * Additive and nullable, so the buyer branch (which reads product_variants
 * but never writes this column) is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_variants') || Schema::hasColumn('product_variants', 'low_stock_threshold')) {
            return;
        }

        Schema::table('product_variants', function (Blueprint $table) {
            $table->integer('low_stock_threshold')->nullable()->after('stock');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('product_variants') && Schema::hasColumn('product_variants', 'low_stock_threshold')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->dropColumn('low_stock_threshold');
            });
        }
    }
};
