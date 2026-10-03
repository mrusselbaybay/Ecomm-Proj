<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The role a buyer/seller session signed in as. Kept per token so a
 * mobile (always buyer) session and a web seller session of the same
 * account don't overwrite each other's role. Null = use profiles.role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('personal_access_tokens', 'active_role')) {
                $table->string('active_role', 20)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('active_role');
        });
    }
};
