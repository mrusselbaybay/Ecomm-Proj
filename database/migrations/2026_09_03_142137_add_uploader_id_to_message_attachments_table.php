<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('message_attachments', 'uploader_id')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::table('message_attachments', function (Blueprint $table) use ($driver) {
            $table->uuid('uploader_id')->nullable()->after('seller_id');
            $table->index('uploader_id');

            if ($driver === 'pgsql') {
                $table->foreign('uploader_id')->references('id')->on('profiles')->cascadeOnDelete();
            }
        });

        DB::table('message_attachments')
            ->whereNull('uploader_id')
            ->update(['uploader_id' => DB::raw('seller_id')]);

        Schema::table('message_attachments', function (Blueprint $table) {
            $table->uuid('seller_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('message_attachments', 'uploader_id')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        DB::table('message_attachments')
            ->whereNull('seller_id')
            ->whereNotNull('uploader_id')
            ->update(['seller_id' => DB::raw('uploader_id')]);

        Schema::table('message_attachments', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->dropForeign(['uploader_id']);
            }

            $table->dropIndex(['uploader_id']);
            $table->dropColumn('uploader_id');
            $table->uuid('seller_id')->nullable(false)->change();
        });
    }
};
