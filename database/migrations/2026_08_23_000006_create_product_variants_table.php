<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * One row per sellable option combination (e.g. Color: Black + Size:
 * Large). price is nullable: null means "use the parent product's
 * price" (a price adjustment/override is optional per the task —
 * sellers aren't forced to re-enter the same price on every variant).
 * stock is required and independently tracked/decremented per variant.
 *
 * status is 'active' | 'unavailable' — a seller-controlled toggle,
 * distinct from the parent product's approval `status` (pending_review /
 * active / archived). A variant can only ever be purchasable when BOTH
 * the parent product is 'active' and the variant itself is 'active'
 * with stock > 0 — enforced in CheckoutService, not trusted from the
 * client.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::create('product_variants', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('product_id');
            $table->string('sku')->nullable();
            $table->decimal('price', 12, 2)->nullable(); // null = use parent product price
            $table->unsignedInteger('stock')->default(0);
            $table->jsonb('image')->nullable(); // {url} — mirrors the shape used in products.images
            $table->string('status')->default('active'); // active | unavailable
            $table->timestampsTz();

            $table->index('product_id');

            // products isn't a Laravel-migrated table (pgsql/Supabase
            // only); skip the hard FK under sqlite where it doesn't exist.
            if ($driver === 'pgsql') {
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            } else {
                $table->unique('sku');
            }
        });

        // Must run after Schema::create() above has actually executed the
        // CREATE TABLE statement — DB::statement() runs immediately when
        // called, unlike the Blueprint methods inside the closure, which
        // only get built into a table once the closure returns.
        if ($driver === 'pgsql') {
            // A SKU, when set, must be globally unique across all
            // products/variants (partial index skips NULLs so sellers can
            // leave SKU blank on more than one variant).
            DB::statement('CREATE UNIQUE INDEX product_variants_sku_unique ON public.product_variants (sku) WHERE sku IS NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
