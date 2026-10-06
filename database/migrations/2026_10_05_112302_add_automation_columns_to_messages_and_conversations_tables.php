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
        Schema::table('messages', function (Blueprint $table) {
            $table->string('source', 32)->default('manual')->index();
            $table->string('quick_question_key', 64)->nullable()->index();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->timestampTz('automation_paused_until')->nullable();
            $table->string('seller_attention_status', 32)->default('handled')->index();
            $table->timestampTz('last_auto_reply_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['automation_paused_until', 'seller_attention_status', 'last_auto_reply_at']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['source', 'quick_question_key']);
        });
    }
};
