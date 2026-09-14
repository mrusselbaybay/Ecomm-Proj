<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the messaging thread header's online/offline indicator
 * (Profile::isOnline()) — touched by AuthenticateSupabaseUser on every
 * authenticated request (throttled via cache, not written on every hit),
 * so "online" reflects real activity anywhere in the app, not just an
 * open chat window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->timestampTz('last_active_at')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('last_active_at');
        });
    }
};
