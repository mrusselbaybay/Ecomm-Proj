<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an individual message carry its own order/product context — needed
 * because a buyer <-> seller conversation is being repointed to identity
 * (buyer_id, seller_id) only (see the following migration), so the single
 * order_id/product_id columns on `conversations` can no longer represent
 * "which purchase was this inquiry about" once a thread spans several
 * orders/products from the same seller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->uuid('order_id')->nullable()->after('conversation_id');
            $table->uuid('product_id')->nullable()->after('order_id');

            $table->index('order_id');
            $table->index('product_id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public.messages ADD CONSTRAINT messages_order_id_foreign FOREIGN KEY (order_id) REFERENCES public.orders(id) ON DELETE SET NULL');
            DB::statement('ALTER TABLE public.messages ADD CONSTRAINT messages_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE SET NULL');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public.messages DROP CONSTRAINT IF EXISTS messages_order_id_foreign');
            DB::statement('ALTER TABLE public.messages DROP CONSTRAINT IF EXISTS messages_product_id_foreign');
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
            $table->dropIndex(['product_id']);
            $table->dropColumn(['order_id', 'product_id']);
        });
    }
};
