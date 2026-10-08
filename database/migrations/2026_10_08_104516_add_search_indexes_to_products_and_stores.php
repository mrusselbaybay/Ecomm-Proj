<?php

use App\Support\ProductSearch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Indexes for buyer search (App\Support\ProductSearch). Additive only: no
 * column, table or data changes, so the seller app sharing this database
 * is unaffected.
 *
 *   products_search_document_idx           GIN over ProductSearch::DOCUMENT_SQL
 *                                          (full-text words; built in)
 *   products_name_trgm_idx                 GIN trigram over lower(name)
 *   seller_details_business_name_trgm_idx  GIN trigram over lower(business_name)
 *
 * The trigram indexes need the pg_trgm extension. It is created in the
 * "extensions" schema when that schema exists (Supabase's convention),
 * otherwise in the default schema. If the database role can't create it,
 * the migration still succeeds: search falls back to full-text words plus
 * an unindexed partial-name match, without typo tolerance, until someone
 * with the rights runs `create extension pg_trgm with schema extensions;`
 * and re-runs this migration.
 *
 * PostgreSQL only — the products table doesn't exist on the sqlite test
 * database. down() drops the indexes but leaves pg_trgm installed, since
 * other code may rely on it by then.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->createTrigramExtension();
        ProductSearch::forgetTrigramSchema();

        $document = str_replace('products.', '', ProductSearch::DOCUMENT_SQL);
        DB::statement("create index if not exists products_search_document_idx on products using gin (({$document}))");

        if ($schema = ProductSearch::trigramSchema()) {
            DB::statement("create index if not exists products_name_trgm_idx on products using gin (lower(name) {$schema}.gin_trgm_ops)");
            DB::statement("create index if not exists seller_details_business_name_trgm_idx on seller_details using gin (lower(business_name) {$schema}.gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('drop index if exists seller_details_business_name_trgm_idx');
        DB::statement('drop index if exists products_name_trgm_idx');
        DB::statement('drop index if exists products_search_document_idx');
    }

    private function createTrigramExtension(): void
    {
        $installed = DB::selectOne("select 1 as present from pg_extension where extname = 'pg_trgm'");
        $available = DB::selectOne("select 1 as present from pg_available_extensions where name = 'pg_trgm'");

        if ($installed || ! $available) {
            return;
        }

        $hasExtensionsSchema = DB::selectOne("select 1 as present from pg_namespace where nspname = 'extensions'");

        // Nested in the migration's transaction this is a savepoint, so a
        // permission error doesn't abort the rest of the migration.
        try {
            DB::transaction(fn () => DB::statement($hasExtensionsSchema
                ? 'create extension if not exists pg_trgm with schema extensions'
                : 'create extension if not exists pg_trgm'));
        } catch (Throwable $e) {
            Log::warning('pg_trgm could not be installed; search runs without typo tolerance.', ['error' => $e->getMessage()]);
        }
    }
};
