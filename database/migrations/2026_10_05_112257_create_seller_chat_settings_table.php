<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('seller_chat_settings', function (Blueprint $table) {
            $table->uuid('seller_id')->primary();
            $table->boolean('auto_reply_enabled')->default(false);
            $table->string('presence_mode', 20)->default('automatic');
            $table->string('generic_away_response', 1000)->default('Thanks for your message. I am currently away and will reply as soon as possible.');
            $table->unsignedSmallInteger('generic_reply_cooldown_minutes')->default(240);
            $table->timestampsTz();

            $table->foreign('seller_id')->references('id')->on('profiles')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seller_chat_settings');
    }
};
