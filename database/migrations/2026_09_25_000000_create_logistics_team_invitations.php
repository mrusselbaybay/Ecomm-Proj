<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns logistics_admin_details (the Supabase-managed "staff of a company"
 * table the portal and RLS already honor via is_logistics_staff_of()) into
 * a real team roster with a role and a suspend flag, and adds the
 * invitations that feed it. The company owner stays implicit through
 * logistics_companies.owner_profile_id and never has a row here.
 *
 * profile_id remains the primary key, so a profile belongs to at most one
 * company — multi-org membership is intentionally not supported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_admin_details', function (Blueprint $table) {
            if (! Schema::hasColumn('logistics_admin_details', 'role')) {
                // Existing rows were "appointed staff" with full access.
                $table->string('role', 20)->default('admin');
            }
            if (! Schema::hasColumn('logistics_admin_details', 'status')) {
                $table->string('status', 20)->default('active');
            }
            if (! Schema::hasColumn('logistics_admin_details', 'invited_by')) {
                $table->uuid('invited_by')->nullable();
            }
            if (! Schema::hasColumn('logistics_admin_details', 'updated_at')) {
                $table->timestampTz('updated_at')->nullable();
            }
        });

        DB::statement("ALTER TABLE logistics_admin_details ADD CONSTRAINT logistics_admin_details_role_check CHECK (role IN ('admin','manager','operator','viewer'))");
        DB::statement("ALTER TABLE logistics_admin_details ADD CONSTRAINT logistics_admin_details_status_check CHECK (status IN ('active','suspended'))");
        DB::statement('CREATE INDEX IF NOT EXISTS logistics_admin_details_company_idx ON logistics_admin_details (logistics_company_id)');

        Schema::create('logistics_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('logistics_company_id');
            $table->string('email');
            $table->string('role', 20);
            // sha256 of the emailed token — the raw token is never stored.
            $table->string('token_hash', 64)->unique();
            $table->string('status', 20)->default('pending');
            $table->uuid('invited_by')->nullable();
            $table->uuid('accepted_by')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('renewal_requested_at')->nullable();
            $table->timestampsTz();

            $table->foreign('logistics_company_id')->references('id')->on('logistics_companies')->cascadeOnDelete();
            $table->index(['logistics_company_id', 'status']);
        });

        DB::statement("ALTER TABLE logistics_invitations ADD CONSTRAINT logistics_invitations_role_check CHECK (role IN ('admin','manager','operator','viewer'))");
        DB::statement("ALTER TABLE logistics_invitations ADD CONSTRAINT logistics_invitations_status_check CHECK (status IN ('pending','accepted','revoked'))");
        // At most one live invitation per email per company; re-inviting updates it.
        DB::statement("CREATE UNIQUE INDEX logistics_invitations_pending_unique ON logistics_invitations (logistics_company_id, lower(email)) WHERE status = 'pending'");
        // Server-only table: enabling RLS with no policies hides it from the anon/authenticated Supabase clients.
        DB::statement('ALTER TABLE logistics_invitations ENABLE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_invitations');

        DB::statement('ALTER TABLE logistics_admin_details DROP CONSTRAINT IF EXISTS logistics_admin_details_role_check');
        DB::statement('ALTER TABLE logistics_admin_details DROP CONSTRAINT IF EXISTS logistics_admin_details_status_check');
        DB::statement('DROP INDEX IF EXISTS logistics_admin_details_company_idx');

        Schema::table('logistics_admin_details', function (Blueprint $table) {
            $table->dropColumn(['role', 'status', 'invited_by', 'updated_at']);
        });
    }
};
