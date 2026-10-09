<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const OLD_RESPONSE = 'Thanks for asking. Please check the selected product variant for its current stock, and I will confirm as soon as I am available.';

    private const NEW_RESPONSE = '{{product_name}}{{variant_name}} currently has {{stock}} item(s) in stock.';

    public function up(): void
    {
        DB::table('chat_quick_questions')
            ->where('key', 'stock_availability')
            ->where('default_response', self::OLD_RESPONSE)
            ->update(['default_response' => self::NEW_RESPONSE, 'updated_at' => now()]);

        DB::table('seller_quick_reply_responses')
            ->where('question_key', 'stock_availability')
            ->where('response', self::OLD_RESPONSE)
            ->update(['response' => self::NEW_RESPONSE, 'updated_at' => now()]);

        DB::table('seller_chat_rules')
            ->where('question_key', 'stock_availability')
            ->where('response_template', self::OLD_RESPONSE)
            ->update(['response_template' => self::NEW_RESPONSE, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('chat_quick_questions')->where('key', 'stock_availability')->where('default_response', self::NEW_RESPONSE)->update(['default_response' => self::OLD_RESPONSE, 'updated_at' => now()]);
        DB::table('seller_quick_reply_responses')->where('question_key', 'stock_availability')->where('response', self::NEW_RESPONSE)->update(['response' => self::OLD_RESPONSE, 'updated_at' => now()]);
        DB::table('seller_chat_rules')->where('question_key', 'stock_availability')->where('response_template', self::NEW_RESPONSE)->update(['response_template' => self::OLD_RESPONSE, 'updated_at' => now()]);
    }
};
