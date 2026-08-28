<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * public.reviews already exists on the actual (shared) database — this
 * branch just never had a migration file for it (see app/Models/Review.php's
 * docblock: useBuyer.js's submitReview() was a local-only stub pointing at
 * a table this branch's migrations didn't know about). Guarded with
 * hasTable() so this is safe to run against a database where the table
 * was already created via another branch's migration history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reviews')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('reviews', function (Blueprint $table) use ($driver) {
            $table->uuid('id')->primary();
            $table->uuid('product_id')->nullable();
            $table->uuid('seller_id');
            $table->uuid('buyer_id')->nullable();
            $table->uuid('order_item_id')->nullable()->unique();
            $table->string('product_name')->nullable();
            $table->smallInteger('rating');
            $table->text('comment')->nullable();
            $table->json('images')->nullable();
            $table->text('seller_response')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->timestampTz('response_edited_at')->nullable();
            $table->uuid('responded_by')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            if ($driver === 'pgsql') {
                $table->foreign('seller_id')->references('id')->on('profiles');
                $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
                $table->foreign('buyer_id')->references('id')->on('profiles')->nullOnDelete();
                $table->foreign('responded_by')->references('id')->on('profiles')->nullOnDelete();
                $table->foreign('order_item_id')->references('id')->on('order_items')->nullOnDelete();
            }
        });

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_rating_check CHECK (rating >= 1 AND rating <= 5)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};