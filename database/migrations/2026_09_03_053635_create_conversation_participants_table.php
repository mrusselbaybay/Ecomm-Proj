<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversation_participants')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('conversation_participants', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('conversation_id');
            $table->uuid('user_id');
            $table->timestampTz('joined_at')->useCurrent();
            $table->timestampTz('last_read_at')->nullable();
            $table->timestampTz('left_at')->nullable();
            $table->timestampsTz();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'last_read_at']);

            if ($driver === 'pgsql') {
                $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('profiles')->cascadeOnDelete();
            }
        });

        $now = now();
        $participants = DB::table('conversations')
            ->get(['id', 'buyer_id', 'seller_id'])
            ->flatMap(function (object $conversation) use ($now): array {
                return collect([$conversation->buyer_id, $conversation->seller_id])
                    ->filter()
                    ->unique()
                    ->map(fn (string $userId): array => [
                        'id' => (string) Str::uuid(),
                        'conversation_id' => $conversation->id,
                        'user_id' => $userId,
                        'joined_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->all();
            })
            ->all();

        if ($participants !== []) {
            DB::table('conversation_participants')->insert($participants);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_participants');
    }
};
