<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Individual messages in a buyer <-> seller conversation. See the
 * conversations table migration for the cross-role context.
 *
 * `attachments` mirrors the shape reviews.images / complaints.evidence use
 * elsewhere in this schema (a json array); no upload endpoint is wired yet,
 * so it stays empty for now but the column is here so adding one later
 * needs no migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        $jsonDefault = $driver === 'pgsql' ? DB::raw("'[]'::jsonb") : '[]';

        Schema::create('messages', function (Blueprint $table) use ($driver, $jsonDefault) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('conversation_id');
            $table->uuid('sender_id');
            $table->string('sender_role'); // buyer | seller
            $table->text('body');
            $table->jsonb('attachments')->default($jsonDefault);
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->index(['conversation_id', 'created_at']);

            if ($driver === 'pgsql') {
                $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
                $table->foreign('sender_id')->references('id')->on('profiles');
            }
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE public.messages ADD CONSTRAINT messages_sender_role_check CHECK (sender_role IN ('buyer','seller'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
