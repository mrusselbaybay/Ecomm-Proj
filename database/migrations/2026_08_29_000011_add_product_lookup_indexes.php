<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the seller catalogue read path. The products FK columns
 * (products.seller_id, product_variants.product_id, ...) have foreign-key
 * constraints but Postgres does not auto-index the referencing side, so
 * "every product for this seller" + the option/variant eager loads were
 * doing sequential scans.
 *
 * pgsql only (the app's real DB); CREATE INDEX IF NOT EXISTS makes it a
 * no-op where an index already exists.
 */
return new class extends Migration
{
    private const INDEXES = [
        'idx_products_seller_id' => 'products (seller_id)',
        'idx_products_seller_status' => 'products (seller_id, status)',
        'idx_product_variants_product_id' => 'product_variants (product_id)',
        'idx_product_options_product_id' => 'product_options (product_id)',
        'idx_product_option_values_option_id' => 'product_option_values (product_option_id)',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::INDEXES as $name => $target) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON public.{$target}");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS public.{$name}");
        }
    }
};
