<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chat_quick_questions', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->string('question');
            $table->text('default_response');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();
        });

        $now = now();

        DB::table('chat_quick_questions')->insert([
            ['key' => 'stock_availability', 'question' => 'Is this in stock?', 'default_response' => 'Thanks for asking. Please check the selected product variant for its current stock, and I will confirm as soon as I am available.', 'sort_order' => 10, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'shipping_time', 'question' => 'When will you ship?', 'default_response' => 'Thanks for your message. I will confirm the expected shipping time as soon as I am available.', 'sort_order' => 20, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'discounts', 'question' => 'Do you offer discounts?', 'default_response' => 'Thanks for asking. Please check the product page for active vouchers and promotions, and I will reply when I am available.', 'sort_order' => 30, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_quick_questions');
    }
};
