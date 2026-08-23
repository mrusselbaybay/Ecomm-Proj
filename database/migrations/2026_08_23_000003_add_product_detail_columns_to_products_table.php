<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the remaining "standard product information" fields onto the
 * existing products table (per the task: reuse the existing table,
 * don't duplicate fields already covered — price/compare_price already
 * serve as sale price / regular price, images already covers the
 * gallery, sku/stock already exist).
 *
 * has_variants is a small denormalized flag set by
 * SellerProductService so the buyer/seller UIs can tell "simple product"
 * from "has variants" with a single column instead of an extra query
 * against product_variants on every listing render.
 */
return new class extends Migration
{
    public function up(): void
    {
        // products isn't a Laravel-migrated table (it lives in the
        // Supabase/pgsql database only — see 2026_08_23_000000's note on
        // this) — nothing to alter on sqlite, where it doesn't exist.
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->string('brand')->nullable()->after('category');
            $table->string('condition')->nullable()->after('brand'); // new | used | refurbished
            $table->jsonb('dimensions')->nullable()->after('condition'); // {length, width, height, unit}
            $table->decimal('weight', 10, 3)->nullable()->after('dimensions'); // kg
            $table->unsignedInteger('low_stock_threshold')->nullable()->after('stock');
            $table->boolean('has_variants')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'brand', 'condition', 'dimensions', 'weight',
                'low_stock_threshold', 'has_variants',
            ]);
        });
    }
};
