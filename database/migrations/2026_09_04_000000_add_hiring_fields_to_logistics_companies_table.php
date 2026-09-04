<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the courier-recruitment fields a logistics company edits from its
 * portal Account Settings page and that the Flutter "Find Work" feature
 * reads:
 *
 *   - description     : free-text pitch shown to job-seeking couriers
 *   - monthly_salary  : the salary the company is offering, in PHP
 *   - is_hiring       : master switch — while false the company is hidden
 *                       from /api/courier/logistics-companies entirely
 *
 * Like the other Supabase-native tables (see
 * 2026_08_18_000000_create_supabase_baseline_tables), `logistics_companies`
 * is NOT Laravel-migrated in production — it lives in the Supabase/pgsql
 * database. This migration is therefore guarded with Schema::hasTable() /
 * hasColumn() and is a no-op against the real database and the feature
 * suite (which builds its own `logistics_companies` schema per test). It
 * is kept so the column set is recorded in one place alongside the rest
 * of the schema history.
 *
 * Run this by hand against Supabase:
 *
 *   alter table public.logistics_companies
 *     add column if not exists description    text,
 *     add column if not exists monthly_salary numeric(12,2),
 *     add column if not exists is_hiring      boolean not null default false;
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('logistics_companies')) {
            return;
        }

        Schema::table('logistics_companies', function (Blueprint $table) {
            if (! Schema::hasColumn('logistics_companies', 'description')) {
                $table->text('description')->nullable();
            }

            if (! Schema::hasColumn('logistics_companies', 'monthly_salary')) {
                $table->decimal('monthly_salary', 12, 2)->nullable();
            }

            if (! Schema::hasColumn('logistics_companies', 'is_hiring')) {
                $table->boolean('is_hiring')->default(false);
            }
        });
    }

    public function down(): void
    {
        // Never drop columns on the real database — it isn't ours. Only
        // reversible on a throwaway (non-pgsql) test database.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            return;
        }

        if (! Schema::hasTable('logistics_companies')) {
            return;
        }

        Schema::table('logistics_companies', function (Blueprint $table) {
            foreach (['description', 'monthly_salary', 'is_hiring'] as $column) {
                if (Schema::hasColumn('logistics_companies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
