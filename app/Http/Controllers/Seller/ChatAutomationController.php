<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\StoreChatKeywordRuleRequest;
use App\Http\Requests\Seller\UpdateChatAutomationSettingsRequest;
use App\Http\Requests\Seller\UpdateChatKeywordRuleRequest;
use App\Http\Requests\Seller\UpdateConversationAutomationRequest;
use App\Http\Requests\Seller\UpdateQuickReplyResponseRequest;
use App\Models\ChatQuickQuestion;
use App\Models\Conversation;
use App\Models\SellerChatRule;
use App\Models\SellerChatSetting;
use App\Models\SellerQuickReplyResponse;
use App\Services\ChatTemplateRenderer;
use App\Services\ChatAutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatAutomationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $sellerId = $request->user()->id;
        $setting = SellerChatSetting::query()->find($sellerId);
        $responses = SellerQuickReplyResponse::query()
            ->where('seller_id', $sellerId)
            ->get()
            ->keyBy('question_key');
        $questionRules = SellerChatRule::query()
            ->where('seller_id', $sellerId)
            ->where('match_type', 'quick_question')
            ->get()
            ->keyBy('question_key');

        return response()->json(['data' => [
            'settings' => $this->settingsPayload($setting),
            'questions' => ChatQuickQuestion::query()
                ->where('enabled', true)
                ->orderBy('sort_order')
                ->get()
                ->map(function (ChatQuickQuestion $question) use ($responses, $questionRules): array {
                    $response = $responses->get($question->key);
                    $rule = $questionRules->get($question->key);

                    return [
                        'key' => $question->key,
                        'question' => $question->question,
                        'contextType' => $question->context_type,
                        'response' => $rule?->response_template ?? $response?->response ?? $question->default_response,
                        'enabled' => $rule?->is_active ?? $response?->enabled ?? true,
                    ];
                })
                ->values(),
            'keywordRules' => SellerChatRule::query()
                ->where('seller_id', $sellerId)
                ->where('match_type', 'keyword')
                ->orderBy('priority')
                ->orderBy('keyword')
                ->get()
                ->map(fn (SellerChatRule $rule): array => $this->rulePayload($rule))
                ->values(),
            'placeholders' => ChatTemplateRenderer::PLACEHOLDERS,
        ]]);
    }

    public function update(UpdateChatAutomationSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['bot_mode'])) {
            $data['auto_reply_enabled'] = $data['bot_mode'] !== 'off';
        } elseif (array_key_exists('auto_reply_enabled', $data)) {
            $data['bot_mode'] = $data['auto_reply_enabled'] ? 'auto' : 'off';
        }

        if (isset($data['seller_status'])) {
            $data['presence_mode'] = $data['seller_status'] === 'away' ? 'away' : 'automatic';
        } elseif (isset($data['presence_mode'])) {
            $data['seller_status'] = $data['presence_mode'];
        }

        $setting = SellerChatSetting::query()->updateOrCreate(
            ['seller_id' => $request->user()->id],
            $data,
        );

        return response()->json(['data' => $this->settingsPayload($setting)]);
    }

    public function updateResponse(
        UpdateQuickReplyResponseRequest $request,
        ChatQuickQuestion $question,
    ): JsonResponse {
        abort_unless($question->enabled, 404);

        $response = DB::transaction(function () use ($request, $question): SellerQuickReplyResponse {
            $response = SellerQuickReplyResponse::query()->updateOrCreate(
                ['seller_id' => $request->user()->id, 'question_key' => $question->key],
                $request->validated(),
            );

            SellerChatRule::query()->updateOrCreate(
                ['seller_id' => $request->user()->id, 'question_key' => $question->key],
                [
                    'match_type' => 'quick_question',
                    'keyword' => null,
                    'normalized_keyword' => null,
                    'response_template' => $response->response,
                    'is_active' => $response->enabled,
                    'priority' => $question->sort_order ?: 100,
                ],
            );

            return $response;
        });

        return response()->json(['data' => [
            'key' => $question->key,
            'question' => $question->question,
            'response' => $response->response,
            'enabled' => $response->enabled,
        ]]);
    }

    public function storeKeywordRule(StoreChatKeywordRuleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $rule = SellerChatRule::query()->create([
            'seller_id' => $request->user()->id,
            'match_type' => 'keyword',
            'keyword' => $data['keyword'],
            'normalized_keyword' => $data['keyword'],
            'response_template' => $data['response_template'],
            'is_active' => $data['is_active'],
            'priority' => $data['priority'],
        ]);

        return response()->json(['data' => $this->rulePayload($rule)], 201);
    }

    public function updateKeywordRule(UpdateChatKeywordRuleRequest $request, SellerChatRule $rule): JsonResponse
    {
        abort_unless($rule->match_type === 'keyword', 404);
        $data = $request->validated();
        $rule->update([
            'keyword' => $data['keyword'],
            'normalized_keyword' => $data['keyword'],
            'response_template' => $data['response_template'],
            'is_active' => $data['is_active'],
            'priority' => $data['priority'],
        ]);

        return response()->json(['data' => $this->rulePayload($rule)]);
    }

    public function destroyKeywordRule(Request $request, SellerChatRule $rule): JsonResponse
    {
        abort_unless(
            $rule->seller_id === $request->user()->id && $rule->match_type === 'keyword',
            404,
        );

        $rule->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function updateConversation(
        UpdateConversationAutomationRequest $request,
        string $id,
    ): JsonResponse {
        $conversation = Conversation::query()
            ->where('type', 'direct')
            ->where('seller_id', $request->user()->id)
            ->whereKey($id)
            ->firstOrFail();

        $conversation->forceFill([
            'automation_paused_until' => $request->boolean('paused') ? now()->addDay() : null,
        ])->save();

        return response()->json(['data' => [
            'paused' => $conversation->automation_paused_until?->isFuture() ?? false,
            'pausedUntil' => $conversation->automation_paused_until?->toIso8601String(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function settingsPayload(?SellerChatSetting $setting): array
    {
        return [
            'autoReplyEnabled' => $setting?->auto_reply_enabled ?? false,
            'botMode' => $setting?->bot_mode ?? 'off',
            'presenceMode' => $setting?->presence_mode ?? 'automatic',
            'sellerStatus' => $setting?->seller_status ?? 'automatic',
            'genericAwayResponse' => $setting?->generic_away_response
                ?? 'Thanks for your message. I am currently away and will reply as soon as possible.',
            'genericOnlineResponse' => $setting?->generic_online_response
                ?? ChatAutomationService::DEFAULT_ONLINE_RESPONSE,
            'genericReplyCooldownMinutes' => $setting?->generic_reply_cooldown_minutes ?? 240,
        ];
    }

    /** @return array<string, mixed> */
    private function rulePayload(SellerChatRule $rule): array
    {
        return [
            'id' => $rule->id,
            'keyword' => $rule->keyword,
            'responseTemplate' => $rule->response_template,
            'isActive' => $rule->is_active,
            'priority' => $rule->priority,
        ];
    }
}
