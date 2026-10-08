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
            $table->uuid('automation_rule_id')->nullable()->after('quick_question_key');
            $table->index('automation_rule_id');
            $table->foreign('automation_rule_id')
                ->references('id')
                ->on('seller_chat_rules')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('automation_rule_id');
        });
    }
};
