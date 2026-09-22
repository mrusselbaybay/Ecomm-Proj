<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalized from products.seller_id so a variant's own row can be
 * scoped by seller without a join — needed for the per-seller (not
 * global) SKU uniqueness enforced by the next migration, and so
 * SellerProductService::generateVariantSku() can build/verify a SKU off
 * the variant's own attributes directly.
 *
 * Left nullable at the DB level: every insert path goes through
 * SellerProductService, which always sets it, so a hard NOT NULL isn't
 * needed to keep the column reliably populated, and skipping it avoids
 * an ALTER COLUMN ... SET NOT NULL against the shared Supabase database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_variants') || Schema::hasColumn('product_variants', 'seller_id')) {
            return;
        }

        Schema::table('product_variants', function (Blueprint $table) {
            $table->uuid('seller_id')->nullable()->after('product_id');
            $table->index('seller_id');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('
                UPDATE product_variants
                SET seller_id = products.seller_id
                FROM products
                WHERE products.id = product_variants.product_id
            ');

            Schema::table('product_variants', function (Blueprint $table) {
                $table->foreign('seller_id')->references('id')->on('profiles')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_variants') || ! Schema::hasColumn('product_variants', 'seller_id')) {
            return;
        }

        Schema::table('product_variants', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->dropForeign(['seller_id']);
            }
            $table->dropColumn('seller_id');
        });
    }
};
