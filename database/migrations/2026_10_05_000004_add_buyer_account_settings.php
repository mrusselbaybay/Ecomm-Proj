<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer account settings support:
 *
 * - profiles.avatar_path: a path inside the public `avatars` Supabase
 *   Storage bucket (the column the seller app already uses for its store
 *   logo). Added only where it doesn't exist yet.
 * - buyer_notification_preferences: one row per buyer for the optional
 *   emails they agree to. Essential account and security emails aren't
 *   listed here because they can't be turned off. RLS is on with no
 *   policies, so only Laravel (owner connection) reads and writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profiles') && ! Schema::hasColumn('profiles', 'avatar_path')) {
            Schema::table('profiles', function (Blueprint $table) {
                $table->string('avatar_path', 512)->nullable();
            });
        }

        if (Schema::hasTable('buyer_notification_preferences')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('buyer_notification_preferences', function (Blueprint $table) use ($driver) {
            $table->uuid('buyer_profile_id')->primary();
            $table->boolean('order_updates_email')->default(true);
            $table->boolean('promotions_email')->default(false);
            $table->timestampsTz();

            if ($driver === 'pgsql') {
                $table->foreign('buyer_profile_id')->references('id')->on('profiles')->cascadeOnDelete();
            }
        });

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE public.buyer_notification_preferences ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_notification_preferences');
        // profiles.avatar_path is left in place: it may predate this
        // migration (the seller app uses it).
    }
};
