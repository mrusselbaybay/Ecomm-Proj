<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two composite indexes the buyer/seller/logistics messaging endpoints were
 * missing, found while diagnosing messaging query performance:
 *
 * - conversations(buyer_id, last_message_at): the buyer inbox
 *   (Buyer\MessageController::conversations()) filters on buyer_id and
 *   sorts by last_message_at — previously only the single-column buyer_id
 *   index existed, so that sort fell back to an in-memory sort/filesort.
 *   The seller side already got the equivalent as [status, last_message_at]
 *   (2026_09_03_053633_...).
 *
 * - messages(conversation_id, sender_role, read_at): every "mark this
 *   thread's messages from the other party as read" update (buyer/seller/
 *   logistics MessageControllers) filters on all three columns, but could
 *   previously only use [conversation_id, created_at] as a partial prefix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['buyer_id', 'last_message_at']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->index(['conversation_id', 'sender_role', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['buyer_id', 'last_message_at']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'sender_role', 'read_at']);
        });
    }
};
