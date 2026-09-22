<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backs "Report inappropriate reviews" on the Seller Feedback & Reviews
 * page (spec section 7). One row per report a seller files against a
 * review left on one of their own products.
 *
 * This is deliberately a lightweight moderation queue, not a full
 * workflow: there is no admin branch in this repo to build the review
 * side against yet, so the seller-facing half is all that ships here —
 * the seller can flag a review and see that it's "under review", and a
 * Log::warning is emitted so the report isn't invisible until an admin
 * tool exists. `status` already carries the vocabulary an admin action
 * would set (reviewed / dismissed / action_taken) so wiring that up
 * later needs no schema change.
 *
 * `seller_id` is denormalized (rather than only reachable via
 * review_id -> reviews.seller_id) for the same reason it is on reviews
 * / orders: "is this my report" has to be a single indexed column the
 * seller API filters on directly. unique(review_id, seller_id) stops a
 * doubled click or an impatient re-submit from stacking duplicate
 * reports for the same review.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('review_reports')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('review_reports', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('review_id');
            $table->uuid('seller_id');

            // offensive_language | spam | personal_information |
            // off_topic | false_information | other
            $table->string('reason', 40);
            $table->text('details')->nullable();

            // pending | reviewed | dismissed | action_taken
            $table->string('status', 20)->default('pending');
            $table->timestampTz('reviewed_at')->nullable();

            $table->timestampsTz();

            $table->unique(['review_id', 'seller_id']);
            $table->index('seller_id');
            $table->index('status');

            if ($driver === 'pgsql') {
                $table->foreign('review_id')->references('id')->on('reviews')->cascadeOnDelete();
                $table->foreign('seller_id')->references('id')->on('profiles')->cascadeOnDelete();
            }
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE public.review_reports ADD CONSTRAINT review_reports_reason_check CHECK (reason IN ('offensive_language', 'spam', 'personal_information', 'off_topic', 'false_information', 'other'))");
            DB::statement("ALTER TABLE public.review_reports ADD CONSTRAINT review_reports_status_check CHECK (status IN ('pending', 'reviewed', 'dismissed', 'action_taken'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('review_reports');
    }
};
