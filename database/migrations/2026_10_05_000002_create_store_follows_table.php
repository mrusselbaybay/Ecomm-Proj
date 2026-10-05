<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buyers following stores (Buyer\StoreFollowController). One row per
 * buyer/store pair, enforced by a unique constraint, and a profile can
 * never follow itself.
 *
 * Writes only go through Laravel, which checks the store is a visible
 * store and the follower is an active buyer. On Supabase, row level
 * security is switched on with a read-own policy only, so the anon /
 * authenticated PostgREST roles can't insert follows that skip those
 * checks (tables created by Laravel otherwise start with RLS off).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_follows')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('store_follows', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('buyer_profile_id');
            $table->uuid('seller_id');
            $table->timestampsTz();

            $table->unique(['buyer_profile_id', 'seller_id']);
            $table->index('seller_id');

            if ($driver === 'pgsql') {
                $table->foreign('buyer_profile_id')->references('id')->on('profiles')->cascadeOnDelete();
                $table->foreign('seller_id')->references('id')->on('profiles')->cascadeOnDelete();
            }
        });

        if ($driver !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE public.store_follows ADD CONSTRAINT store_follows_not_self CHECK (buyer_profile_id <> seller_id)');
        DB::statement('ALTER TABLE public.store_follows ENABLE ROW LEVEL SECURITY');

        // auth.uid() only exists on Supabase; plain Postgres just keeps
        // RLS on with no policies (Laravel's owner connection bypasses it).
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF to_regprocedure('auth.uid()') IS NOT NULL THEN
                    EXECUTE 'CREATE POLICY store_follows_select_own ON public.store_follows FOR SELECT USING (buyer_profile_id = auth.uid())';
                END IF;
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('store_follows');
    }
};
