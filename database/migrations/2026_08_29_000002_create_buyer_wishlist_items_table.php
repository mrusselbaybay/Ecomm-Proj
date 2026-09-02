<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-buyer saved/favorited products, backing Wishlist.vue and the heart
 * button on every ProductCard.vue / ProductDetails.vue. Replaces the
 * localStorage-only favorites list in useBuyer.js so a wishlist follows
 * the buyer across devices.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyer_wishlist_items')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('buyer_wishlist_items', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('buyer_profile_id');
            $table->uuid('product_id');
            $table->timestampsTz();

            $table->unique(['buyer_profile_id', 'product_id']);
            $table->index('buyer_profile_id');

            if ($driver === 'pgsql') {
                $table->foreign('buyer_profile_id')->references('id')->on('profiles')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_wishlist_items');
    }
};
