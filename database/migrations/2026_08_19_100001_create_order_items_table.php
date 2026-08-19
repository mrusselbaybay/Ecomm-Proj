<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Line items are snapshotted (product_name/sku/category/unit_price) so an
 * order still displays correctly even if the seller later edits or
 * deletes the product row in public.products.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::create('order_items', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('order_id');
            $table->uuid('product_id')->nullable();

            $table->string('product_name');
            $table->string('category')->nullable();
            $table->string('sku')->nullable();
            $table->string('variant')->nullable();

            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('subtotal', 12, 2);

            $table->timestampsTz();

            $table->index('order_id');
            $table->index('product_id');

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            if ($driver === 'pgsql') {
                $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
