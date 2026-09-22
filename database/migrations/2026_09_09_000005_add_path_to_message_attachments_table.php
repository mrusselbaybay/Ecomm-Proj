<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * message_attachments.url now holds a real Supabase Storage URL instead
 * of an inline base64 data: URL (see MessageController::uploadAttachment())
 * — path is the bucket-relative object key, kept alongside the URL so a
 * future cleanup pass can delete the underlying Storage object without
 * having to parse it back out of the URL string.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('message_attachments') || Schema::hasColumn('message_attachments', 'path')) {
            return;
        }

        Schema::table('message_attachments', function (Blueprint $table) {
            $table->string('path')->nullable()->after('url');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('message_attachments') && Schema::hasColumn('message_attachments', 'path')) {
            Schema::table('message_attachments', function (Blueprint $table) {
                $table->dropColumn('path');
            });
        }
    }
};
