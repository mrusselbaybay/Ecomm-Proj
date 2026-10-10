<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyer_notification_preferences') && ! Schema::hasColumn('buyer_notification_preferences', 'case_updates_email')) {
            Schema::table('buyer_notification_preferences', fn (Blueprint $table) => $table->boolean('case_updates_email')->default(true));
        }

        if (Schema::hasTable('notifications') && ! Schema::hasColumn('notifications', 'dedupe_key')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->string('dedupe_key')->nullable();
                $table->unique(['notifiable_type', 'notifiable_id', 'dedupe_key'], 'notifications_dedupe_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notifications') && Schema::hasColumn('notifications', 'dedupe_key')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->dropUnique('notifications_dedupe_unique');
                $table->dropColumn('dedupe_key');
            });
        }
        if (Schema::hasTable('buyer_notification_preferences') && Schema::hasColumn('buyer_notification_preferences', 'case_updates_email')) {
            Schema::table('buyer_notification_preferences', fn (Blueprint $table) => $table->dropColumn('case_updates_email'));
        }
    }
};
