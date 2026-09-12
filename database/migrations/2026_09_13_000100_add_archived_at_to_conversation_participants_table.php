<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Archive" was originally built as a shared Conversation.status value —
 * one side archiving a thread set the single status column, which also
 * hid/blocked it for the OTHER side. That's wrong: archiving should be a
 * personal inbox-organisation toggle, exactly like "delete" (leaveFor()/
 * left_at) already is, not something one party can do to the other.
 *
 * This adds archived_at on conversation_participants (mirroring left_at)
 * and migrates any conversation currently sitting at status='archived'
 * back to 'open', stamping archived_at for every one of its still-active
 * participants — the closest faithful equivalent of "already archived"
 * under the new per-participant model, so nothing that was hidden today
 * suddenly reappears for everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->timestampTz('archived_at')->nullable()->after('left_at');
        });

        $now = now();

        DB::table('conversations')
            ->where('status', 'archived')
            ->orderBy('id')
            ->chunkById(200, function ($conversations) use ($now) {
                $ids = $conversations->pluck('id');

                DB::table('conversation_participants')
                    ->whereIn('conversation_id', $ids)
                    ->whereNull('left_at')
                    ->update(['archived_at' => $now]);

                DB::table('conversations')->whereIn('id', $ids)->update(['status' => 'open']);
            });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
