<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_chat_settings', function (Blueprint $table): void {
            $table->string('generic_online_response', 1000)
                ->default("Hi! 👋 Thanks for messaging [Shop Name] We'll reply shortly.");
        });
    }

    public function down(): void
    {
        Schema::table('seller_chat_settings', function (Blueprint $table): void {
            $table->dropColumn('generic_online_response');
        });
    }
};
