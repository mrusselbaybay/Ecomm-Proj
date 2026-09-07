<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\SendMessageRequest;
use App\Models\Conversation;
use App\Models\LogisticsCompany;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Policies\ConversationPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    public function __construct(private ConversationPolicy $conversationPolicy) {}

    public function conversations(Request $request): JsonResponse
    {
        $company = $this->companyFor($request);
        $conversations = $this->scopedQuery($request, $company)
            ->with(['seller.sellerDetail', 'order', 'parcelAssignment.rider'])
            ->orderByRaw('last_message_at desc nulls last')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $conversations->map(fn (Conversation $conversation) => $this->transformConversation($conversation)),
            'meta' => ['unread_total' => (int) $conversations->sum('logistics_unread_count')],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findConversation($request, $id);

        abort_unless($conversation, 404);

        $this->markConversationRead($conversation, $request->user()->id);

        return response()->json(['data' => $this->transformConversation($conversation)]);
    }

    public function messages(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findConversation($request, $id);
        abort_unless($conversation, 404);

        return response()->json([
            'data' => $conversation->messages()->latest()->limit(50)->get()->reverse()->values()
                ->map(fn (Message $message) => $this->transformMessage($message)),
        ]);
    }

    public function send(SendMessageRequest $request, string $id): JsonResponse
    {
        $conversation = $this->findConversation($request, $id);
        abort_unless($conversation, 404);

        if (! $this->conversationPolicy->sendMessage($request->user(), $conversation)) {
            return response()->json(['message' => 'This conversation is not open for new messages.'], 422);
        }

        $body = trim($request->validated('body'));
        $message = DB::transaction(function () use ($body, $conversation, $request): Message {
            $message = $conversation->messages()->create([
                'sender_id' => $request->user()->id,
                'sender_role' => 'logistics',
                'message_type' => 'text',
                'body' => $body,
                'attachments' => [],
            ]);

            $conversation->forceFill([
                'last_message_at' => $message->created_at,
                'last_message_preview' => mb_substr($body, 0, 160),
                'last_message_sender_role' => 'logistics',
                'logistics_unread_count' => 0,
                'seller_unread_count' => $conversation->seller_unread_count + 1,
            ])->save();

            $conversation->messages()->where('sender_role', 'seller')->whereNull('read_at')->update(['read_at' => now()]);

            return $message;
        });

        return response()->json(['data' => $this->transformMessage($message)], 201);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findConversation($request, $id);
        abort_unless($conversation, 404);
        $this->markConversationRead($conversation, $request->user()->id);

        return response()->json(['data' => ['unread' => 0]]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $company = $this->companyFor($request);
        $count = (int) $this->scopedQuery($request, $company)->sum('logistics_unread_count');

        return response()->json(['data' => ['count' => $count]]);
    }

    private function companyFor(Request $request): LogisticsCompany
    {
        return LogisticsCompany::query()
            ->where('owner_profile_id', $request->user()->id)
            ->where('status', 'approved')
            ->where('account_status', 'active')
            ->firstOrFail();
    }

    private function scopedQuery(Request $request, LogisticsCompany $company): Builder
    {
        return Conversation::query()
            ->where('type', 'shipment')
            ->where('logistics_company_id', $company->id)
            ->whereHas('participantRecords', fn (Builder $query) => $query
                ->where('user_id', $request->user()->id)->whereNull('left_at'));
    }

    private function findConversation(Request $request, string $id): ?Conversation
    {
        $company = $this->companyFor($request);

        return $this->scopedQuery($request, $company)
            ->with(['seller.sellerDetail', 'order', 'parcelAssignment.rider', 'logisticsCompany'])
            ->whereKey($id)
            ->first();
    }

    private function markConversationRead(Conversation $conversation, string $readerId): void
    {
        DB::transaction(function () use ($conversation, $readerId): void {
            $conversation->messages()->where('sender_role', 'seller')->whereNull('read_at')->update(['read_at' => now()]);
            $conversation->update(['logistics_unread_count' => 0]);
            $conversation->participantRecords()->where('user_id', $readerId)->update(['last_read_at' => now()]);
        });
    }

    private function transformConversation(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'status' => $conversation->status,
            'seller' => [
                'id' => $conversation->seller_id,
                'name' => $conversation->seller?->sellerDetail?->business_name ?: $conversation->seller?->full_name,
            ],
            'order' => [
                'id' => $conversation->order?->id,
                'number' => $conversation->order?->order_number,
                'status' => $conversation->order?->status,
            ],
            'shipment' => [
                'id' => $conversation->parcel_assignment_id,
                'region' => $conversation->logisticsCompany?->region,
                'rider' => $conversation->parcelAssignment?->rider?->full_name,
            ],
            'last_message' => $conversation->last_message_preview,
            'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
            'unread' => (int) $conversation->logistics_unread_count,
        ];
    }

    private function transformMessage(Message $message): array
    {
        return [
            'id' => $message->id,
            'from' => $message->sender_role,
            'text' => $message->body,
            'attachments' => collect($message->attachments ?? [])->map(fn (array $attachment) => [
                ...$attachment,
                'url' => MessageAttachment::contractUrlFor($attachment),
            ])->all(),
            'at' => optional($message->created_at)->toIso8601String(),
            'read_at' => optional($message->read_at)->toIso8601String(),
        ];
    }
}
