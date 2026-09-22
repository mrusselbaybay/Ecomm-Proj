<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer-initiated return / refund requests against a delivered order item,
 * backing ReturnRequestModal.vue and OrderDetails.vue's per-item
 * "returnRequest" block. Previously a console.warn stub in useBuyer.js
 * (submitReturnRequest) with no table.
 *
 * Cross-role: the seller and admin sides will read/approve these rows on
 * their own branches; this migration and the Eloquent model are the shared
 * pieces, the buyer endpoints in routes/buyer.php are not.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_return_requests')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        $jsonDefault = $driver === 'pgsql' ? DB::raw("'[]'::jsonb") : '[]';

        Schema::create('order_return_requests', function (Blueprint $table) use ($driver, $jsonDefault) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('order_id');
            $table->uuid('order_item_id')->nullable();
            $table->uuid('buyer_profile_id');
            $table->uuid('seller_id');

            $table->string('request_type'); // return_and_refund | refund_only
            $table->string('reason');       // damaged | wrong_item | incomplete | not_as_described | quality_issue | other
            $table->text('details');
            $table->integer('quantity')->default(1);
            $table->decimal('estimated_amount', 12, 2)->default(0);
            $table->jsonb('evidence')->default($jsonDefault);

            $table->string('status')->default('pending'); // pending | approved | rejected | cancelled | completed
            $table->text('resolution_note')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index('order_id');
            $table->index('buyer_profile_id');
            $table->index('seller_id');
            $table->index('status');

            if ($driver === 'pgsql') {
                $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
                $table->foreign('order_item_id')->references('id')->on('order_items')->nullOnDelete();
                $table->foreign('buyer_profile_id')->references('id')->on('profiles');
                $table->foreign('seller_id')->references('id')->on('profiles');
                $table->foreign('reviewed_by')->references('id')->on('profiles')->nullOnDelete();
            }
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE public.order_return_requests ADD CONSTRAINT order_return_requests_type_check CHECK (request_type IN ('return_and_refund','refund_only'))");
            DB::statement("ALTER TABLE public.order_return_requests ADD CONSTRAINT order_return_requests_status_check CHECK (status IN ('pending','approved','rejected','cancelled','completed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_return_requests');
    }
};
