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
        Schema::create('seller_quick_reply_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('seller_id');
            $table->string('question_key', 64);
            $table->text('response');
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();

            $table->unique(['seller_id', 'question_key'], 'seller_quick_reply_unique');
            $table->foreign('seller_id')->references('id')->on('profiles')->cascadeOnDelete();
            $table->foreign('question_key')->references('key')->on('chat_quick_questions')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seller_quick_reply_responses');
    }
};
