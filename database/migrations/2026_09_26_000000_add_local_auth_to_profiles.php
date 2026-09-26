<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves authentication off Supabase Auth: profiles become the login
 * accounts (bcrypt password or Google identity) and Sanctum tokens are
 * issued against them, so personal_access_tokens needs uuid tokenable ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('profiles', 'password')) {
                $table->string('password')->nullable();
            }
            if (! Schema::hasColumn('profiles', 'auth_provider')) {
                // 'email' or 'google' — the frontend's user.app_metadata.provider.
                $table->string('auth_provider', 20)->default('email');
            }
            if (! Schema::hasColumn('profiles', 'google_id')) {
                $table->string('google_id')->nullable()->unique();
            }
            if (! Schema::hasColumn('profiles', 'email_verified_at')) {
                $table->timestampTz('email_verified_at')->nullable();
            }
        });

        if (Schema::hasTable('personal_access_tokens')
            && Schema::getColumnType('personal_access_tokens', 'tokenable_id') !== 'char') {
            // Profiles use uuid keys; morphs() created a bigint column.
            DB::table('personal_access_tokens')->delete();

            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropMorphs('tokenable');
            });
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->uuidMorphs('tokenable');
            });
        }
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn(['password', 'auth_provider', 'google_id', 'email_verified_at']);
        });
    }
};
