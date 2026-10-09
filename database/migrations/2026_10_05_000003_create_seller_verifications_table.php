<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seller verification: an admin's explicit "verified seller" grant, kept
 * apart from account approval (profiles.status / account_status, which
 * only means the seller may trade). A seller is verified while their row
 * has status 'verified'; revoking keeps the row as an audit trail of who
 * granted and who revoked it, and when.
 *
 * It lives in its own table rather than on profiles / seller_details
 * because sellers can update their own rows in those tables directly
 * through Supabase. Here RLS is on with a read-only policy (the seller
 * themselves, or an admin), and table privileges are revoked from the
 * anon / authenticated roles, so the only writer is Laravel's owner
 * connection behind the admin-only endpoint.
 *
 * No backfill: existing sellers start unverified. Account approval and
 * reviewed registration documents are a different decision, so they are
 * not treated as evidence of verification.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seller_verifications')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('seller_verifications', function (Blueprint $table) use ($driver) {
            $table->uuid('seller_id')->primary();
            $table->string('status', 20);
            $table->uuid('verified_by')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestampsTz();

            $table->index('status');

            if ($driver === 'pgsql') {
                $table->foreign('seller_id')->references('id')->on('profiles')->cascadeOnDelete();
                $table->foreign('verified_by')->references('id')->on('profiles')->nullOnDelete();
                $table->foreign('revoked_by')->references('id')->on('profiles')->nullOnDelete();
            }
        });

        if ($driver !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE public.seller_verifications ADD CONSTRAINT seller_verifications_status_check CHECK (status IN ('verified', 'revoked'))");
        DB::statement("ALTER TABLE public.seller_verifications ADD CONSTRAINT seller_verifications_verified_fields CHECK (status <> 'verified' OR verified_at IS NOT NULL)");
        DB::statement('ALTER TABLE public.seller_verifications ENABLE ROW LEVEL SECURITY');

        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'anon') THEN
                    EXECUTE 'REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON public.seller_verifications FROM anon';
                END IF;

                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
                    EXECUTE 'REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON public.seller_verifications FROM authenticated';
                END IF;

                IF to_regprocedure('auth.uid()') IS NOT NULL AND to_regprocedure('public.is_admin()') IS NOT NULL THEN
                    EXECUTE 'CREATE POLICY seller_verifications_select_own_or_admin ON public.seller_verifications FOR SELECT USING (seller_id = auth.uid() OR public.is_admin())';
                END IF;
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_verifications');
    }
};
