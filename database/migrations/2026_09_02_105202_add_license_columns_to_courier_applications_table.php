<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Mirrors the existing resume_* columns — a driver's license upload is
     * now required alongside the resume when a courier applies to a
     * logistics company (see CourierApplicationController::store).
     */
    public function up(): void
    {
        // courier_applications has no local CREATE migration of its own —
        // like the tables in 2026_08_18_000000_create_supabase_baseline_
        // tables.php, it only exists for real in Supabase's Postgres,
        // created there directly rather than through this migrations
        // folder. Individual feature tests stand up their own throwaway
        // copy of it in sqlite (see e.g. LogisticsParcelAssignmentApiTest's
        // beforeEach). Guarding here — the same Schema::hasTable() pattern
        // that baseline file's own docblock calls for — means this
        // migration is a safe no-op wherever the table isn't present yet
        // (a fresh sqlite test run before that per-test Schema::create
        // fires) instead of failing the whole migration batch, while still
        // adding the columns for real everywhere the table already exists.
        if (! Schema::hasTable('courier_applications')) {
            return;
        }

        Schema::table('courier_applications', function (Blueprint $table) {
            $table->string('license_original_name')->nullable()->after('resume_size');
            $table->string('license_path')->nullable()->after('license_original_name');
            $table->unsignedBigInteger('license_size')->nullable()->after('license_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('courier_applications')) {
            return;
        }

        Schema::table('courier_applications', function (Blueprint $table) {
            $table->dropColumn(['license_original_name', 'license_path', 'license_size']);
        });
    }
};
