<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A buyer <-> seller message thread. Backs Chat.vue / useBuyerChat.js
 * (previously seeded placeholder data with no backend).
 *
 * Cross-role: shaped to also satisfy the seller-side messaging API
 * contract already documented in the seller reference's
 * resources/js/seller/composables/useMessaging.js. The seller branch adds
 * its own /api/seller/messages/* controllers against these same two
 * tables; only the buyer endpoints live in routes/buyer.php.
 *
 * A thread is keyed by (buyer_id, seller_id, order_id) so a buyer messaging
 * the same seller about a different order gets a distinct thread, while a
 * general (order_id null) thread stays single.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversations')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('conversations', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('buyer_id');
            $table->uuid('seller_id');
            $table->uuid('order_id')->nullable();
            $table->uuid('product_id')->nullable();

            $table->string('subject')->nullable();
            $table->string('status')->default('open'); // open | resolved | archived

            $table->timestampTz('last_message_at')->nullable();
            $table->text('last_message_preview')->nullable();
            $table->string('last_message_sender_role')->nullable();

            $table->unsignedInteger('buyer_unread_count')->default(0);
            $table->unsignedInteger('seller_unread_count')->default(0);

            $table->timestampsTz();

            $table->index('buyer_id');
            $table->index('seller_id');
            $table->index('order_id');
            $table->index(['seller_id', 'status']);

            if ($driver === 'pgsql') {
                $table->foreign('buyer_id')->references('id')->on('profiles')->cascadeOnDelete();
                $table->foreign('seller_id')->references('id')->on('profiles')->cascadeOnDelete();
                $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
                $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            }
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE public.conversations ADD CONSTRAINT conversations_status_check CHECK (status IN ('open','resolved','archived'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
