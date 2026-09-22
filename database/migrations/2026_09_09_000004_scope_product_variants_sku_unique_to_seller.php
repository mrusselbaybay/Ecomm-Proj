<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SKUs are now auto-generated (see SellerProductService::
 * generateVariantSku()), and different sellers' catalogs are entirely
 * independent — there's no reason two unrelated sellers can't end up
 * with the same-looking code (in practice they won't, since the
 * generator embeds each seller's own id, but a seller who still has an
 * old, manually-typed SKU from before this feature could coincidentally
 * match another seller's). The uniqueness that actually matters is
 * "within one seller's own inventory," not globally across the whole
 * marketplace, so the old global partial-unique index on `sku` alone is
 * replaced with one scoped to (seller_id, sku).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_variants') || ! Schema::hasColumn('product_variants', 'seller_id')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS product_variants_sku_unique');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS product_variants_seller_sku_unique ON public.product_variants (seller_id, sku) WHERE sku IS NOT NULL');

            return;
        }

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique('product_variants_sku_unique');
            $table->unique(['seller_id', 'sku'], 'product_variants_seller_sku_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_variants') || ! Schema::hasColumn('product_variants', 'seller_id')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS product_variants_seller_sku_unique');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS product_variants_sku_unique ON public.product_variants (sku) WHERE sku IS NOT NULL');

            return;
        }

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique('product_variants_seller_sku_unique');
            $table->unique('sku', 'product_variants_sku_unique');
        });
    }
};
