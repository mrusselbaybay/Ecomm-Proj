<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seller inbox — one row per event a seller should see (a new order
 * landed, an order's status changed, a return was requested, ...).
 * Powers the header bell + unread indicator.
 *
 * Written by App\Services\SellerNotifier, always AFTER the related DB
 * transaction has committed. `dedupe_key` lets a repeated request (e.g. a
 * double-submitted status change) be a no-op instead of a duplicate
 * notification.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seller_notifications')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('seller_notifications', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('seller_id');
            $table->string('type', 40);           // order_placed | order_status_changed | ...
            $table->string('title');
            $table->text('body')->nullable();
            $table->jsonb('data')->nullable();     // { orderNumber, orderId, total, buyer, ... }
            $table->uuid('order_id')->nullable();
            $table->string('dedupe_key')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->index(['seller_id', 'read_at']);
            $table->index(['seller_id', 'created_at']);
            $table->unique(['seller_id', 'dedupe_key']);

            if ($driver === 'pgsql') {
                $table->foreign('seller_id')->references('id')->on('profiles')->cascadeOnDelete();
                $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_notifications');
    }
};
