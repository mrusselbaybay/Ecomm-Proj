<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The original schema logs account_status changes via Postgres triggers
 * (trg_status_log_profiles / trg_status_log_logistics) that call auth.uid().
 * Since this Laravel app authenticates admins through its own guard (not
 * Supabase's PostgREST/auth.uid() session context), those triggers would
 * insert audit rows with a null changed_by. We disable the DB triggers and
 * write status_audit_log rows explicitly from the controllers instead,
 * where we know exactly which admin performed the action.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_status_log_profiles ON public.profiles');
            DB::statement('DROP TRIGGER IF EXISTS trg_status_log_logistics ON public.logistics_companies');
        } else {
            // SQLite and other databases use simpler syntax
            DB::statement('DROP TRIGGER IF EXISTS trg_status_log_profiles');
            DB::statement('DROP TRIGGER IF EXISTS trg_status_log_logistics');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_status_log_profiles
                  AFTER UPDATE OF account_status ON public.profiles
                  FOR EACH ROW EXECUTE FUNCTION public.log_status_change();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_status_log_logistics
                  AFTER UPDATE OF account_status ON public.logistics_companies
                  FOR EACH ROW EXECUTE FUNCTION public.log_status_change();
            SQL);
        }
    }
};