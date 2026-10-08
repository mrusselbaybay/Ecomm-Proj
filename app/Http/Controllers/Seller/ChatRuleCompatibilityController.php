<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\SellerChatRule;
use App\Models\SellerChatSetting;
use App\Rules\SupportedChatTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChatRuleCompatibilityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $rules = SellerChatRule::query()
            ->where('seller_id', $seller->id)
            ->orderBy('priority')
            ->get()
            ->map(fn (SellerChatRule $rule): array => $this->payload($rule));

        return response()->json(['data' => $rules]);
    }

    public function store(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $data = $this->validateRule($request, $seller);
        $matchType = isset($data['question_id']) ? 'quick_question' : 'keyword';

        $rule = SellerChatRule::query()->create([
            'seller_id' => $seller->id,
            'match_type' => $matchType,
            'question_key' => $data['question_id'] ?? null,
            'keyword' => $data['keyword'] ?? null,
            'normalized_keyword' => isset($data['keyword']) ? mb_strtolower(trim($data['keyword'])) : null,
            'response_template' => $data['response_template'],
            'is_active' => $data['is_active'] ?? true,
            'priority' => $data['priority'] ?? 100,
        ]);

        return response()->json(['data' => $this->payload($rule)], 201);
    }

    public function update(Request $request, SellerChatRule $rule): JsonResponse
    {
        $seller = $this->seller($request);
        abort_unless($rule->seller_id === $seller->id, 404);
        $data = $this->validateRule($request, $seller, $rule);

        $rule->update([
            'response_template' => $data['response_template'],
            'is_active' => $data['is_active'] ?? $rule->is_active,
            'priority' => $data['priority'] ?? $rule->priority,
            'keyword' => $data['keyword'] ?? $rule->keyword,
            'normalized_keyword' => isset($data['keyword'])
                ? mb_strtolower(trim($data['keyword']))
                : $rule->normalized_keyword,
        ]);

        return response()->json(['data' => $this->payload($rule->fresh())]);
    }

    public function destroy(Request $request, SellerChatRule $rule): JsonResponse
    {
        $seller = $this->seller($request);
        abort_unless($rule->seller_id === $seller->id, 404);
        $rule->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function settings(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $data = $request->validate([
            'availability' => ['required', Rule::in(['online', 'away', 'offline'])],
            'bot_mode' => ['required', Rule::in(['off', 'suggest', 'auto'])],
        ]);

        $setting = SellerChatSetting::query()->updateOrCreate(
            ['seller_id' => $seller->id],
            [
                'seller_status' => $data['availability'],
                'presence_mode' => $data['availability'] === 'away' ? 'away' : 'automatic',
                'bot_mode' => $data['bot_mode'],
                'auto_reply_enabled' => $data['bot_mode'] !== 'off',
            ],
        );

        return response()->json(['data' => [
            'availability' => $setting->seller_status,
            'bot_mode' => $setting->bot_mode,
        ]]);
    }

    /** @return array<string, mixed> */
    private function validateRule(Request $request, Profile $seller, ?SellerChatRule $rule = null): array
    {
        return $request->validate([
            'question_id' => [
                $rule ? 'sometimes' : 'nullable',
                'string',
                'max:64',
                Rule::exists('chat_quick_questions', 'key'),
                Rule::unique('seller_chat_rules', 'question_key')
                    ->where('seller_id', $seller->id)
                    ->ignore($rule?->id),
                'required_without:keyword',
            ],
            'keyword' => [
                $rule ? 'sometimes' : 'nullable',
                'string',
                'min:2',
                'max:100',
                'required_without:question_id',
            ],
            'response_template' => ['required', 'string', 'max:1000', app(SupportedChatTemplate::class)],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);
    }

    private function seller(Request $request): Profile
    {
        $resolver = $request->getUserResolver();
        $seller = $resolver();
        abort_unless($seller instanceof Profile && $seller->role === 'seller', 403);

        return $seller;
    }

    /** @return array<string, mixed> */
    private function payload(SellerChatRule $rule): array
    {
        return [
            'id' => $rule->id,
            'question_id' => $rule->question_key,
            'keyword' => $rule->keyword,
            'response_template' => $rule->response_template,
            'is_active' => $rule->is_active,
            'priority' => $rule->priority,
        ];
    }
}
