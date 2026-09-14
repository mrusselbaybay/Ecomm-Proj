<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rider (driver/courier) side's own denormalised unread counter for a
 * 'roster' conversation — mirrors buyer_unread_count / seller_unread_count /
 * logistics_unread_count. Needed so Driver\MessageController can track the
 * mobile app's own unread state without touching logistics_unread_count,
 * which already belongs to the logistics company's side of the same thread.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedInteger('courier_unread_count')->default(0)->after('logistics_unread_count');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('courier_unread_count');
        });
    }
};
