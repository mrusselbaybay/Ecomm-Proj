<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log of every stock change for a seller's products.
 *
 * One row per change, capturing the quantity before, the signed delta,
 * the quantity after, why it changed (movement_type + reason), who did
 * it (actor_id / actor_type), and the related order when the change came
 * from checkout / cancellation / a return.
 *
 * Written exclusively by App\Services\InventoryService — nothing else may
 * mutate products.stock / product_variants.stock without going through it,
 * so this table stays complete.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_movements')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('inventory_movements', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('seller_id');
            $table->uuid('product_id');
            $table->uuid('variant_id')->nullable();
            $table->uuid('order_id')->nullable();

            // What kind of change: restock, manual_increase, manual_decrease,
            // damaged, incorrect_count, returned_item, lost_item,
            // initial_stock, sale, cancellation_restock, return_restock, other
            $table->string('movement_type', 40);
            // The seller-chosen reason on a manual adjustment (restock,
            // damaged, incorrect_count, returned_item, lost_item, other).
            $table->string('reason', 40)->nullable();
            $table->text('note')->nullable();

            $table->integer('quantity_before');
            $table->integer('quantity_change'); // signed: +restock / -sale
            $table->integer('quantity_after');

            $table->uuid('actor_id')->nullable();
            $table->string('actor_type', 16)->default('seller'); // seller | system

            $table->timestampsTz();

            $table->index(['product_id', 'created_at']);
            $table->index(['variant_id', 'created_at']);
            $table->index(['seller_id', 'created_at']);
            $table->index('order_id');

            if ($driver === 'pgsql') {
                $table->foreign('seller_id')->references('id')->on('profiles')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->foreign('variant_id')->references('id')->on('product_variants')->nullOnDelete();
                $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
                $table->foreign('actor_id')->references('id')->on('profiles')->nullOnDelete();
            }
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE public.inventory_movements ADD CONSTRAINT inventory_movements_actor_type_check CHECK (actor_type IN ('seller','system'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
