<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Buyer\MessageController as BuyerMessageController;
use App\Http\Requests\Buyer\SendMessageRequest;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatCompatibilityController extends Controller
{
    public function quickQuestions(Request $request, BuyerMessageController $messages): JsonResponse
    {
        $data = $request->validate(['order_id' => ['required', 'uuid']]);
        $conversation = Conversation::query()
            ->where('buyer_id', $request->user()->id)
            ->where('order_id', $data['order_id'])
            ->where('type', 'direct')
            ->firstOrFail();

        $request->merge(['conversation_id' => $conversation->id]);

        return $messages->quickQuestions($request);
    }

    public function session(Request $request, string $sessionId): JsonResponse
    {
        $conversation = Conversation::query()
            ->whereKey($sessionId)
            ->where(function ($query) use ($request): void {
                $query->where('buyer_id', $request->user()->id)
                    ->orWhere('seller_id', $request->user()->id);
            })
            ->firstOrFail();
        $sessionMessages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => [
            'id' => $conversation->id,
            'order_id' => $conversation->order_id,
            'buyer_id' => $conversation->buyer_id,
            'seller_id' => $conversation->seller_id,
            'status' => $conversation->status,
            'bot_enabled' => ! ($conversation->automation_paused_until?->isFuture() ?? false),
            'messages' => $sessionMessages->map(fn (Message $message): array => [
                'id' => $message->id,
                'sender_type' => $message->source === 'manual' ? $message->sender_role : 'bot',
                'content' => $message->body,
                'is_auto_reply' => str_starts_with($message->source, 'auto_reply'),
                'rule_id' => $message->automation_rule_id,
                'created_at' => $message->created_at?->toIso8601String(),
            ]),
        ]]);
    }

    public function message(Request $request, BuyerMessageController $messages): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'uuid'],
            'text' => ['nullable', 'string', 'max:5000', 'required_without:question_id'],
            'question_id' => ['nullable', 'string', 'max:64', 'required_without:text'],
            'order_id' => ['nullable', 'uuid'],
        ]);

        $form = SendMessageRequest::createFrom($request);
        $form->setContainer(app());
        $form->setRedirector(app('redirect'));
        $form->setUserResolver(fn () => $request->user());
        $form->merge([
            'body' => $data['text'] ?? null,
            'quick_question_key' => $data['question_id'] ?? null,
            'order_id' => $data['order_id'] ?? null,
        ]);
        $form->validateResolved();

        return $messages->sendMessage($form, $data['session_id']);
    }
}
