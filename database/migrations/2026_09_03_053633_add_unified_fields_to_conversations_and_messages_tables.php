<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('type')->default('product')->after('id');
            $table->uuid('created_by')->nullable()->after('type');
            $table->string('context_key', 64)->nullable()->unique()->after('created_by');
            $table->index(['status', 'last_message_at']);
        });

        DB::table('conversations')->whereNotNull('order_id')->update(['type' => 'order']);
        DB::table('conversations')->whereNull('created_by')->update(['created_by' => DB::raw('buyer_id')]);
        DB::table('conversations')
            ->get(['id', 'type', 'buyer_id', 'seller_id', 'order_id', 'product_id'])
            ->each(function (object $conversation): void {
                $participantIds = collect([$conversation->buyer_id, $conversation->seller_id])
                    ->filter()
                    ->sort()
                    ->implode(':');
                $contextId = $conversation->order_id ?? $conversation->product_id ?? $conversation->id;

                DB::table('conversations')->where('id', $conversation->id)->update([
                    'context_key' => hash('sha256', "{$conversation->type}:{$contextId}:{$participantIds}"),
                ]);
            });

        Schema::table('messages', function (Blueprint $table) {
            $table->string('message_type')->default('text')->after('sender_role');
            $table->uuid('reply_to_message_id')->nullable()->after('body');
            $table->timestampTz('edited_at')->nullable()->after('read_at');
            $table->softDeletesTz();
            $table->index(['sender_id', 'created_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public.conversations DROP CONSTRAINT IF EXISTS conversations_status_check');
            DB::statement("ALTER TABLE public.conversations ADD CONSTRAINT conversations_status_check CHECK (status IN ('open','active','resolved','archived','closed','blocked','under_review'))");
            DB::statement('ALTER TABLE public.messages DROP CONSTRAINT IF EXISTS messages_sender_role_check');
            DB::statement("ALTER TABLE public.messages ADD CONSTRAINT messages_sender_role_check CHECK (sender_role IN ('buyer','seller','logistics','driver','courier','admin','system'))");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public.conversations DROP CONSTRAINT IF EXISTS conversations_status_check');
            DB::statement("ALTER TABLE public.conversations ADD CONSTRAINT conversations_status_check CHECK (status IN ('open','resolved','archived'))");
            DB::statement('ALTER TABLE public.messages DROP CONSTRAINT IF EXISTS messages_sender_role_check');
            DB::statement("ALTER TABLE public.messages ADD CONSTRAINT messages_sender_role_check CHECK (sender_role IN ('buyer','seller'))");
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['sender_id', 'created_at']);
            $table->dropColumn(['message_type', 'reply_to_message_id', 'edited_at', 'deleted_at']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['status', 'last_message_at']);
            $table->dropUnique(['context_key']);
            $table->dropColumn(['type', 'created_by', 'context_key']);
        });
    }
};
