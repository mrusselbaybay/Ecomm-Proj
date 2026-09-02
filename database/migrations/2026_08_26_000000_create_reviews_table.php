<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Backs the Seller Feedback & Reviews page (resources/js/seller/components/
 * Feedback.vue via useFeedback.js / SellerFeedbackController).
 *
 * `seller_id` is denormalized onto the review (rather than only reachable
 * through product_id -> products.seller_id) for the same reason
 * orders.seller_id is: "does this review belong to me" needs to be a
 * single indexed column check the seller API can filter on directly,
 * matching the pattern SellerOrderController already uses.
 *
 * `order_item_id` is nullable and unique-when-present: it ties a review to
 * the specific purchase it came from (enables a "verified purchase" badge
 * later and stops the same purchased line item from being reviewed twice),
 * but is left nullable because there is currently no buyer-facing review
 * submission flow yet — this migration only builds the seller-side
 * read/respond half of the feature.
 *
 * No sentiment/helpful-vote/report columns here on purpose: this project
 * has no real sentiment-analysis pipeline and no buyer-facing "helpful"/
 * "report" interaction to back those numbers with, so the UI doesn't
 * present anything under those names — see SellerFeedbackController.
 *
 * Guarded with hasTable(): public.reviews may already exist on the actual
 * (shared) database if another branch's migration history created it
 * first — see app/Models/Review.php's docblock. Safe to run either way.
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
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('product_id')->nullable();
            $table->uuid('seller_id');
            $table->uuid('buyer_id')->nullable();
            $table->uuid('order_item_id')->nullable();

            // Snapshotted so a review still displays correctly even if the
            // product is later renamed/deleted — same reasoning as
            // order_items.product_name.
            $table->string('product_name')->nullable();

            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->json('images')->nullable();

            $table->text('seller_response')->nullable();
            // Set once, the first time a seller responds — never touched
            // again after that, so avg-response-time stays a measure of
            // "how long until the seller first replied" even if they edit
            // the wording later.
            $table->timestampTz('responded_at')->nullable();
            // Set only on a subsequent edit of an already-published
            // response (see SellerFeedbackController::respond). Null means
            // "never edited", which the UI uses to distinguish a fresh
            // response from an edited one.
            $table->timestampTz('response_edited_at')->nullable();
            $table->uuid('responded_by')->nullable();

            $table->timestampsTz();

            $table->index('seller_id');
            $table->index('product_id');
            $table->index('buyer_id');
            $table->index('rating');
            $table->index('created_at');
            $table->unique('order_item_id');

            $table->foreign('seller_id')->references('id')->on('profiles')->cascadeOnDelete();

            if ($driver === 'pgsql') {
                $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
                $table->foreign('buyer_id')->references('id')->on('profiles')->nullOnDelete();
                $table->foreign('responded_by')->references('id')->on('profiles')->nullOnDelete();
                $table->foreign('order_item_id')->references('id')->on('order_items')->nullOnDelete();
            }
        });

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE public.reviews ADD CONSTRAINT reviews_rating_check CHECK (rating BETWEEN 1 AND 5)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
