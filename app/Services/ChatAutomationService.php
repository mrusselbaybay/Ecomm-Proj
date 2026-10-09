<?php

namespace App\Services;

use App\Models\ChatAutomationSuggestion;
use App\Models\ChatQuickQuestion;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\SellerChatRule;
use App\Models\SellerChatSetting;
use App\Models\SellerQuickReplyResponse;

class ChatAutomationService
{
    public const DEFAULT_WELCOME = 'Welcome to [Shop Name]! How may we tend to your needs?';

    public const DEFAULT_ONLINE_RESPONSE = "Hi! 👋 Thanks for messaging [Shop Name] We'll reply shortly.";

    public const DEFAULT_AWAY_RESPONSE = 'Thanks for your message. I am currently away and will reply as soon as possible.';

    public function __construct(private ChatTemplateRenderer $templateRenderer) {}

    public function respondIfEligible(Conversation $conversation, Message $buyerMessage, ?string $questionKey, bool $contactSeller = false): ?Message
    {
        if ($conversation->type !== 'direct' || ! $conversation->seller_id) {
            return null;
        }

        if ($this->automatedReplyAlreadyExists($buyerMessage)) {
            return null;
        }

        $setting = SellerChatSetting::query()->find($conversation->seller_id);
        $botMode = match (true) {
            ! $setting?->auto_reply_enabled => 'off',
            blank($setting->bot_mode), $setting->bot_mode === 'off' => 'auto',
            default => $setting->bot_mode,
        };

        $conversation->loadMissing(['buyer', 'seller.sellerDetail']);
        $sellerIsOnline = $this->sellerIsOnline($setting, $conversation);

        if ($contactSeller) {
            $response = $sellerIsOnline
                ? ($setting?->generic_online_response ?: self::DEFAULT_ONLINE_RESPONSE)
                : ($setting?->generic_away_response ?: self::DEFAULT_AWAY_RESPONSE);
            $response = str_replace('[Shop Name]', $this->shopName($conversation), $response);

            return $this->sendAutomatedMessage($conversation, $buyerMessage, $response, 'auto_reply_contact', null, 'needs_reply', null);
        }

        if (! $setting || $botMode === 'off' || $conversation->automation_paused_until?->isFuture()) {
            $this->markNeedsReply($conversation);

            return null;
        }

        if (! Message::query()->where('conversation_id', $conversation->id)->where('sender_role', 'buyer')->where('id', '<>', $buyerMessage->id)->exists()
            && ! Message::query()->where('conversation_id', $conversation->id)->where('source', 'auto_reply_welcome')->exists()) {
            $welcome = str_replace('[Shop Name]', $this->shopName($conversation), self::DEFAULT_WELCOME);

            return $this->sendAutomatedMessage($conversation, $buyerMessage, $welcome, 'auto_reply_welcome', null, 'needs_reply', null);
        }

        $order = $this->resolveOrder($conversation, $buyerMessage);
        $match = $this->matchedResponse($conversation, $buyerMessage, $questionKey, $order);

        if ($match !== null) {
            $response = $this->templateRenderer->render($match['template'], $conversation, $order, $buyerMessage);

            if ($sellerIsOnline && $botMode === 'suggest') {
                ChatAutomationSuggestion::query()->updateOrCreate(
                    ['source_message_id' => $buyerMessage->id],
                    [
                        'conversation_id' => $conversation->id,
                        'rule_id' => $match['rule']?->id,
                        'suggested_response' => $response,
                        'status' => 'pending',
                        'used_at' => null,
                    ],
                );
                $this->markNeedsReply($conversation);

                return null;
            }

            return $this->sendAutomatedMessage(
                $conversation,
                $buyerMessage,
                $response,
                'auto_reply_specific',
                $questionKey,
                'auto_replied',
                $match['rule']?->id,
            );
        }

        if ($sellerIsOnline) {
            $this->markNeedsReply($conversation);

            return null;
        }

        return $this->sendAutomatedMessage(
            $conversation,
            $buyerMessage,
            str_replace('[Shop Name]', $this->shopName($conversation), $setting->generic_away_response ?: self::DEFAULT_AWAY_RESPONSE),
            'auto_reply_generic',
            null,
            'needs_reply',
            null,
        );
    }

    private function shopName(Conversation $conversation): string
    {
        return $conversation->seller?->sellerDetail?->business_name ?: $conversation->seller?->full_name ?: 'Shop';
    }

    /** @return array{template: string, rule: SellerChatRule|null}|null */
    private function matchedResponse(
        Conversation $conversation,
        Message $buyerMessage,
        ?string $questionKey,
        ?Order $order,
    ): ?array {
        if ($questionKey) {
            $question = ChatQuickQuestion::query()->whereKey($questionKey)->where('enabled', true)->first();

            if (! $question || ! $this->questionIsApplicable($question, $order)) {
                return null;
            }

            $rule = SellerChatRule::query()
                ->where('seller_id', $conversation->seller_id)
                ->where('match_type', 'quick_question')
                ->where('question_key', $questionKey)
                ->first();

            if ($rule) {
                return $rule->is_active ? ['template' => $rule->response_template, 'rule' => $rule] : null;
            }

            $legacy = SellerQuickReplyResponse::query()
                ->where('seller_id', $conversation->seller_id)
                ->where('question_key', $questionKey)
                ->first();

            if ($legacy && ! $legacy->enabled) {
                return null;
            }

            return ['template' => $legacy?->response ?? $question->default_response, 'rule' => null];
        }

        $message = mb_strtolower(trim($buyerMessage->body));
        if ($message === '') {
            return null;
        }

        $rule = SellerChatRule::query()
            ->where('seller_id', $conversation->seller_id)
            ->where('match_type', 'keyword')
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderByRaw('LENGTH(normalized_keyword) DESC')
            ->get()
            ->first(fn (SellerChatRule $candidate): bool => $this->containsKeyword($message, $candidate->normalized_keyword ?? ''));

        return $rule ? ['template' => $rule->response_template, 'rule' => $rule] : null;
    }

    private function resolveOrder(Conversation $conversation, Message $buyerMessage): ?Order
    {
        if ($buyerMessage->order_id) {
            return Order::query()
                ->whereKey($buyerMessage->order_id)
                ->where('buyer_profile_id', $conversation->buyer_id)
                ->where('seller_id', $conversation->seller_id)
                ->first();
        }

        return $conversation->order_id
            ? Order::query()->whereKey($conversation->order_id)->first()
            : null;
    }

    private function questionIsApplicable(ChatQuickQuestion $question, ?Order $order): bool
    {
        if ($question->context_type !== 'order') {
            return true;
        }

        return $order !== null;
    }

    private function containsKeyword(string $message, string $keyword): bool
    {
        if ($keyword === '') {
            return false;
        }

        return preg_match('/(?<![\pL\pN])'.preg_quote($keyword, '/').'(?![\pL\pN])/iu', $message) === 1;
    }

    private function sellerIsOnline(?SellerChatSetting $setting, Conversation $conversation): bool
    {
        $status = $setting?->seller_status ?: $setting?->presence_mode;

        return match ($status) {
            'online' => true,
            'away' => false,
            default => (bool) $conversation->seller?->isOnline(),
        };
    }

    private function sendAutomatedMessage(
        Conversation $conversation,
        Message $buyerMessage,
        string $response,
        string $source,
        ?string $questionKey,
        string $attentionStatus,
        ?string $ruleId,
    ): Message {
        $autoReply = $conversation->messages()->create([
            'sender_id' => $conversation->seller_id,
            'sender_role' => 'seller',
            'message_type' => 'text',
            'body' => $response,
            'reply_to_message_id' => $buyerMessage->id,
            'attachments' => [],
            'source' => $source,
            'quick_question_key' => $questionKey,
            'automation_rule_id' => $ruleId,
            'order_id' => $buyerMessage->order_id,
            'product_id' => $buyerMessage->product_id,
            'variant_id' => $buyerMessage->variant_id,
        ]);

        $conversation->forceFill([
            'last_message_at' => $autoReply->created_at,
            'last_message_preview' => mb_substr($response, 0, 160),
            'last_message_sender_role' => 'seller',
            'buyer_unread_count' => $conversation->buyer_unread_count + 1,
            'seller_attention_status' => $attentionStatus,
            'last_auto_reply_at' => $autoReply->created_at,
        ])->save();
        $conversation->reviveLeftParticipants();

        return $autoReply;
    }

    private function automatedReplyAlreadyExists(Message $buyerMessage): bool
    {
        return Message::query()
            ->where('reply_to_message_id', $buyerMessage->id)
            ->whereIn('source', ['auto_reply_specific', 'auto_reply_generic'])
            ->exists();
    }

    private function markNeedsReply(Conversation $conversation): void
    {
        $conversation->forceFill(['seller_attention_status' => 'needs_reply'])->save();
    }
}
