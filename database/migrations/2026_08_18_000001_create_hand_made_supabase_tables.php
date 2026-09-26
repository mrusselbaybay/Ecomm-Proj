<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tables and views that were created by hand in the Supabase SQL editor
 * and never had a migration. Mirrors the live Postgres structure so a
 * fresh MySQL database can be built with `php artisan migrate`.
 *
 * MySQL/MariaDB only: on pgsql the tables already exist, and sqlite feature
 * tests create their own throwaway copies in beforeEach.
 *
 * Columns added later by guarded migrations (hasColumn) are included here;
 * courier_applications.license_* are left to 2026_09_02_105202, which adds
 * them unguarded.
 *
 * Not ported (Postgres/Supabase-only, enforced in the app instead):
 * RLS policies, auth.users trigger, set_updated_at / log_status_change
 * triggers, and the owner-shape CHECKs on addresses/documents/driver_details
 * (MySQL rejects CHECKs on columns used by an FK cascade action).
 */
return new class extends Migration
{
    private const APPROVAL = ['pending', 'approved', 'rejected'];

    private const ACCOUNT = ['active', 'suspended', 'deactivated', 'pending'];

    private const VEHICLE = ['Motorcycle', 'Car', 'Van', 'Bicycle', 'Truck'];

    private const OWNER = ['profile', 'logistics_company'];

    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $uuid = new Expression('(UUID())');

        Schema::create('logistics_companies', function (Blueprint $t) use ($uuid) {
            $t->uuid('id')->primary()->default($uuid);
            $t->foreignUuid('owner_profile_id')->unique()->constrained('profiles')->cascadeOnDelete();
            $t->string('company_name');
            $t->string('company_email');
            $t->string('company_contact_no');
            $t->string('tin');
            $t->string('sec_registration')->nullable();
            $t->enum('status', self::APPROVAL)->default('pending');
            $t->enum('account_status', self::ACCOUNT)->default('pending')->index('idx_logistics_account_status');
            $t->string('region')->nullable();
            $t->text('description')->nullable();
            $t->decimal('monthly_salary', 12, 2)->nullable();
            $t->boolean('is_hiring')->default(false);
            $t->uuid('last_auto_assigned_rider_profile_id')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('addresses', function (Blueprint $t) use ($uuid) {
            $t->uuid('id')->primary()->default($uuid);
            $t->enum('owner_kind', self::OWNER);
            $t->foreignUuid('profile_id')->nullable()->constrained('profiles')->cascadeOnDelete();
            $t->foreignUuid('logistics_company_id')->nullable()->constrained('logistics_companies')->cascadeOnDelete();
            $t->string('region_code')->nullable();
            $t->string('region_name')->nullable();
            $t->string('province_code');
            $t->string('province_name');
            $t->string('municipality_code');
            $t->string('municipality_name');
            $t->string('barangay');
            $t->string('street');
            $t->string('house_no')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('courier_details', function (Blueprint $t) {
            $t->foreignUuid('profile_id')->primary()->constrained('profiles')->cascadeOnDelete();
            $t->enum('vehicle', self::VEHICLE);
            $t->string('plate_number');
            $t->foreignUuid('logistics_company_id')->nullable()->index('idx_courier_details_company')->constrained('logistics_companies');
            $t->string('delivery_status')->default('unavailable');
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('driver_details', function (Blueprint $t) {
            $t->foreignUuid('profile_id')->primary()->constrained('profiles')->cascadeOnDelete();
            $t->foreignUuid('logistics_company_id')->nullable()->index('idx_driver_details_company')->constrained('logistics_companies')->cascadeOnDelete();
            $t->enum('vehicle', self::VEHICLE)->nullable();
            $t->string('plate_number')->nullable();
            $t->string('license_number')->nullable();
            $t->string('delivery_status')->default('unavailable');
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('logistics_admin_details', function (Blueprint $t) {
            $t->foreignUuid('profile_id')->primary()->constrained('profiles')->cascadeOnDelete();
            $t->foreignUuid('logistics_company_id')->index('logistics_admin_details_company_idx')->constrained('logistics_companies')->cascadeOnDelete();
            $t->enum('role', ['admin', 'manager', 'operator', 'viewer'])->default('admin');
            $t->enum('status', ['active', 'suspended'])->default('active');
            $t->uuid('invited_by')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->nullable();
        });

        Schema::create('courier_applications', function (Blueprint $t) use ($uuid) {
            $t->uuid('id')->primary()->default($uuid);
            $t->foreignUuid('courier_profile_id')->index('idx_courier_applications_courier')->constrained('profiles')->cascadeOnDelete();
            $t->foreignUuid('logistics_company_id')->index('idx_courier_applications_company')->constrained('logistics_companies')->cascadeOnDelete();
            $t->enum('status', ['pending', 'accepted', 'rejected', 'withdrawn'])->default('pending');
            $t->timestampTz('applied_at')->useCurrent();
            $t->foreignUuid('reviewed_by')->nullable()->constrained('profiles');
            $t->timestampTz('reviewed_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->string('resume_original_name')->nullable();
            $t->string('resume_path')->nullable();
            $t->unsignedBigInteger('resume_size')->nullable();
            $t->text('cover_note')->nullable();
            $t->timestampTz('interview_invited_at')->nullable();
            $t->timestampTz('interview_scheduled_at')->nullable();
            $t->text('interview_notes')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Live DB still has the original full unique constraint, which
            // supersedes the partial "active" unique index — mirror that.
            $t->unique(['courier_profile_id', 'logistics_company_id'], 'courier_applications_courier_company_unique');
        });

        Schema::create('documents', function (Blueprint $t) use ($uuid) {
            $t->uuid('id')->primary()->default($uuid);
            $t->enum('owner_kind', self::OWNER);
            $t->foreignUuid('profile_id')->nullable()->index('idx_documents_profile')->constrained('profiles')->cascadeOnDelete();
            $t->foreignUuid('logistics_company_id')->nullable()->index('idx_documents_logistics_company')->constrained('logistics_companies')->cascadeOnDelete();
            $t->enum('doc_type', ['valid_id', 'business_permit', 'mayors_permit', 'dti_sec_registration', 'orcr', 'drivers_license']);
            $t->string('id_type', 50)->nullable();
            $t->string('storage_path', 500);
            $t->string('mime_type')->nullable()->index('idx_documents_mime_type');
            $t->enum('status', self::APPROVAL)->default('pending');
            $t->foreignUuid('reviewed_by')->nullable()->constrained('profiles');
            $t->timestampTz('reviewed_at')->nullable();
            $t->timestampTz('created_at')->useCurrent();
        });

        // Blueprint::enum() doesn't escape the apostrophe in "Driver's License".
        DB::statement(<<<'SQL'
            ALTER TABLE documents ADD CONSTRAINT documents_id_type_check CHECK (id_type IS NULL OR id_type IN
            ('Passport', 'Driver''s License', 'PRC ID', 'UMID', 'SSS ID', 'National ID', 'Student ID', 'Philippine Postal ID'))
        SQL);

        Schema::create('companies', function (Blueprint $t) use ($uuid) {
            $t->uuid('id')->primary()->default($uuid);
            $t->string('name');
            $t->string('role')->index('idx_companies_role');
            $t->string('region')->index('idx_companies_region');
            $t->string('industry');
            $t->string('salary_range');
            $t->string('employment_type');
            $t->string('logo_initial', 10);
            $t->text('description');
            $t->boolean('is_hiring')->default(true)->index('idx_companies_is_hiring');
            $t->foreignUuid('created_by')->nullable()->constrained('profiles')->nullOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('job_applications', function (Blueprint $t) use ($uuid) {
            $t->uuid('id')->primary()->default($uuid);
            $t->foreignUuid('user_profile_id')->index('idx_job_applications_user')->constrained('profiles')->cascadeOnDelete();
            $t->foreignUuid('company_id')->index('idx_job_applications_company')->constrained('companies')->cascadeOnDelete();
            $t->string('resume_file_name');
            $t->unsignedBigInteger('resume_size_bytes');
            $t->text('cover_note')->nullable();
            $t->enum('status', ['pending', 'employed', 'rejected'])->default('pending')->index('idx_job_applications_status');
            $t->timestampTz('submitted_at')->useCurrent()->index('idx_job_applications_submitted');
            $t->timestampTz('reviewed_at')->nullable();
            $t->foreignUuid('reviewed_by')->nullable()->constrained('profiles')->nullOnDelete();
            $t->text('rejection_reason')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->useCurrent()->useCurrentOnUpdate();

            $t->unique(['user_profile_id', 'company_id']);
        });

        Schema::create('status_audit_log', function (Blueprint $t) use ($uuid) {
            $t->uuid('id')->primary()->default($uuid);
            $t->enum('entity_type', self::OWNER);
            $t->uuid('entity_id');
            $t->enum('old_status', self::ACCOUNT)->nullable();
            $t->enum('new_status', self::ACCOUNT);
            $t->text('reason')->nullable();
            $t->foreignUuid('changed_by')->nullable()->constrained('profiles')->nullOnDelete();
            $t->timestampTz('created_at')->useCurrent();

            $t->index(['entity_id', 'entity_type'], 'idx_status_audit_entity');
        });

        Schema::create('product_moderation_logs', function (Blueprint $t) use ($uuid) {
            $t->uuid('id')->primary()->default($uuid);
            $t->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $t->string('ai_status');
            $t->decimal('ai_confidence_score', 8, 4);
            $t->json('ai_flagged_signals');
            $t->text('ai_reasoning');
            $t->string('final_status');
            $t->boolean('needs_human_review');
            $t->timestampsTz();

            $t->index(['product_id', 'created_at']);
            $t->index(['needs_human_review', 'created_at']);
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW active_logistics_companies AS
            SELECT id, company_name, company_email, company_contact_no, region,
                   status, account_status, created_at, updated_at
            FROM logistics_companies
            WHERE account_status = 'active'
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW user_application_summary AS
            SELECT p.id AS profile_id, p.email, p.first_name, p.last_name,
                   COUNT(ja.id) AS total_applications,
                   COALESCE(SUM(ja.status = 'pending'), 0) AS pending_count,
                   COALESCE(SUM(ja.status = 'employed'), 0) AS employed_count,
                   COALESCE(SUM(ja.status = 'rejected'), 0) AS rejected_count
            FROM profiles p
            LEFT JOIN job_applications ja ON ja.user_profile_id = p.id
            GROUP BY p.id, p.email, p.first_name, p.last_name
        SQL);
    }

    public function down(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS user_application_summary');
        DB::statement('DROP VIEW IF EXISTS active_logistics_companies');

        foreach ([
            'product_moderation_logs', 'status_audit_log', 'job_applications', 'companies',
            'documents', 'courier_applications', 'logistics_admin_details', 'driver_details',
            'courier_details', 'addresses', 'logistics_companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
