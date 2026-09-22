<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staging ledger for message attachments. The seller messaging API
 * (Seller\MessageController) is a two-step upload: POST /messages/attachments
 * stores a file here (message_id null), then POST .../messages references
 * the returned ids in `attachment_ids` and the controller links them
 * (sets message_id) and copies the {id,name,url,mime,size} payload into
 * messages.attachments for rendering.
 *
 * Seller-only for now — the buyer Chat.vue has no attachment upload — so
 * unlike conversations/messages this table isn't shared across branches.
 *
 * `url` currently holds a base64 data: URL, matching how products.images
 * and reviews.images store binary in this schema. Swapping to a Storage
 * bucket later only changes what the controller writes here, not the shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('message_attachments')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('message_attachments', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('seller_id');
            $table->uuid('message_id')->nullable();

            $table->string('name');
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->text('url');

            $table->timestampsTz();

            $table->index('seller_id');
            $table->index('message_id');

            if ($driver === 'pgsql') {
                $table->foreign('seller_id')->references('id')->on('profiles')->cascadeOnDelete();
                $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
