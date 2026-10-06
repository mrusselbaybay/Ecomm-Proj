<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('chat_quick_questions', function (Blueprint $table) {
            $table->string('context_type', 20)->default('general')->after('default_response')->index();
        });

        Schema::create('chat_quick_question_statuses', function (Blueprint $table) {
            $table->string('question_key', 64);
            $table->string('order_status', 40);

            $table->primary(['question_key', 'order_status']);
            $table->index(['order_status', 'question_key']);
            $table->foreign('question_key')->references('key')->on('chat_quick_questions')->cascadeOnDelete();
        });

        Schema::table('seller_chat_settings', function (Blueprint $table) {
            $table->string('bot_mode', 20)->default('off')->after('auto_reply_enabled');
            $table->string('seller_status', 20)->default('automatic')->after('presence_mode');
        });

        Schema::create('seller_chat_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('seller_id');
            $table->string('match_type', 20);
            $table->string('question_key', 64)->nullable();
            $table->string('keyword', 100)->nullable();
            $table->string('normalized_keyword', 100)->nullable();
            $table->text('response_template');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->timestampsTz();

            $table->index(['seller_id', 'match_type', 'is_active', 'priority'], 'seller_chat_rules_match_index');
            $table->unique(['seller_id', 'question_key'], 'seller_chat_rules_question_unique');
            $table->unique(['seller_id', 'normalized_keyword'], 'seller_chat_rules_keyword_unique');
            $table->foreign('seller_id')->references('id')->on('profiles')->cascadeOnDelete();
            $table->foreign('question_key')->references('key')->on('chat_quick_questions')->cascadeOnDelete();
        });

        Schema::create('chat_automation_suggestions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->uuid('source_message_id');
            $table->uuid('rule_id')->nullable();
            $table->text('suggested_response');
            $table->string('status', 20)->default('pending');
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();

            $table->unique('source_message_id');
            $table->index(['conversation_id', 'status']);
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('source_message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->foreign('rule_id')->references('id')->on('seller_chat_rules')->nullOnDelete();
        });

        $now = now();
        $orderQuestions = [
            ['key' => 'order_status', 'question' => 'What is the status of my order?', 'default_response' => 'Your order #{{order_id}} is currently {{status}}.', 'sort_order' => 100],
            ['key' => 'cancel_order', 'question' => 'Can I cancel my order?', 'default_response' => 'Order #{{order_id}} is currently {{status}}. Please wait for my confirmation before considering it cancelled.', 'sort_order' => 110],
            ['key' => 'change_shipping_address', 'question' => 'Can I change the shipping address?', 'default_response' => 'Order #{{order_id}} is currently {{status}}. Address changes depend on whether fulfillment has started.', 'sort_order' => 120],
            ['key' => 'track_order', 'question' => 'Where is my order?', 'default_response' => 'Order #{{order_id}} is currently {{status}}. Tracking: {{tracking_link}}', 'sort_order' => 130],
            ['key' => 'return_refund', 'question' => 'I want to return or request a refund', 'default_response' => 'Order #{{order_id}} is currently {{status}}. I will review your return or refund request.', 'sort_order' => 140],
        ];

        foreach ($orderQuestions as $question) {
            DB::table('chat_quick_questions')->updateOrInsert(
                ['key' => $question['key']],
                [...$question, 'context_type' => 'order', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $statusMap = [
            'order_status' => ['New', 'Confirmed', 'Processing', 'Packed', 'Ready for Pickup', 'In Transit', 'Delivered', 'Cancelled', 'Rejected'],
            'cancel_order' => ['New', 'Confirmed'],
            'change_shipping_address' => ['New', 'Confirmed'],
            'track_order' => ['In Transit'],
            'return_refund' => ['Delivered'],
        ];

        foreach ($statusMap as $questionKey => $statuses) {
            foreach ($statuses as $status) {
                DB::table('chat_quick_question_statuses')->insert([
                    'question_key' => $questionKey,
                    'order_status' => $status,
                ]);
            }
        }

        DB::table('seller_chat_settings')->where('auto_reply_enabled', true)->update(['bot_mode' => 'auto']);
        DB::table('seller_chat_settings')->where('presence_mode', 'away')->update(['seller_status' => 'away']);

        foreach (DB::table('seller_quick_reply_responses')->orderBy('created_at')->get() as $response) {
            DB::table('seller_chat_rules')->insert([
                'id' => (string) Str::uuid(),
                'seller_id' => $response->seller_id,
                'match_type' => 'quick_question',
                'question_key' => $response->question_key,
                'keyword' => null,
                'normalized_keyword' => null,
                'response_template' => $response->response,
                'is_active' => $response->enabled,
                'priority' => 100,
                'created_at' => $response->created_at,
                'updated_at' => $response->updated_at,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_automation_suggestions');
        Schema::dropIfExists('seller_chat_rules');

        Schema::table('seller_chat_settings', function (Blueprint $table) {
            $table->dropColumn(['bot_mode', 'seller_status']);
        });

        Schema::dropIfExists('chat_quick_question_statuses');

        DB::table('chat_quick_questions')->whereIn('key', [
            'order_status',
            'cancel_order',
            'change_shipping_address',
            'track_order',
            'return_refund',
        ])->delete();

        Schema::table('chat_quick_questions', function (Blueprint $table) {
            $table->dropColumn('context_type');
        });
    }
};
