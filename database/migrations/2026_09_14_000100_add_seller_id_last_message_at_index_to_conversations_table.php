<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller\MessageController::conversations() filters on `seller_id` and
 * sorts by `last_message_at desc` for the default ("All") tab — the most
 * common view, since it has no status filter applied. Only `seller_id`
 * (single-column) and `[seller_id, status]` existed, neither of which
 * covers the sort column, so Postgres had to sort the filtered rows in
 * memory instead of walking an index in order.
 *
 * Mirrors the buyer side's `[buyer_id, last_message_at]`
 * (2026_09_13_000000_add_messaging_perf_indexes) and the logistics side's
 * `[logistics_company_id, last_message_at]`
 * (2026_09_07_132712_add_shipment_context_to_conversations_table) — the
 * seller side was the one role missing its equivalent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['seller_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['seller_id', 'last_message_at']);
        });
    }
};
