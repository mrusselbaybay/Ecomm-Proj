<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\SendMessageRequest;
use App\Http\Requests\Buyer\StartConversationRequest;
use App\Http\Requests\Messaging\UploadMessageAttachmentRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Profile;
use App\Policies\ConversationPolicy;
use App\Services\DirectConversationService;
use App\Services\MessageAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buyer side of buyer <-> seller messaging (conversations / messages).
 *
 * Every query is scoped to conversations where buyer_id = the
 * authenticated buyer, so a buyer can never read or post into another
 * buyer's (or a seller-only) thread by guessing an id.
 *
 * The seller side of these same tables is built on feature/seller against
 * the contract in resources/js/seller/composables/useMessaging.js.
 */
class MessageController extends Controller
{
    private const MESSAGE_PAGE = 50;

    public function __construct(
        private DirectConversationService $directConversationService,
        private ConversationPolicy $conversationPolicy,
        private MessageAttachmentService $messageAttachmentService,
    ) {}

    public function conversations(Request $request): JsonResponse
    {
        $buyer = $request->user();

        $conversations = Conversation::query()
            ->with(['seller.sellerDetail', 'product'])
            ->where('buyer_id', $buyer->id)
            ->where('type', '!=', 'support')
            ->whereHas('participantRecords', fn ($query) => $query
                ->where('user_id', $buyer->id)
                ->whereNull('left_at'))
            ->orderByRaw('last_message_at desc nulls last')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $conversations->map(fn (Conversation $c) => $this->transformConversation($c, withMessages: true)),
            'meta' => [
                'unread_total' => (int) $conversations->sum('buyer_unread_count'),
            ],
        ]);
    }

    public function startConversation(StartConversationRequest $request): JsonResponse
    {
        $buyer = $request->user();
        $data = $request->validated();

        $conversation = DB::transaction(function () use ($buyer, $data) {
            $conversation = $this->directConversationService->findOrCreateBuyerSeller(
                $buyer,
                $data['seller_id'],
                $data['order_number'] ?? null,
                $data['product_id'] ?? null,
                $data['subject'] ?? null,
            );

            $this->appendMessage($conversation, $buyer, 'buyer', $data['body']);

            return $conversation;
        });

        return response()->json([
            'data' => $this->transformConversation(
                $conversation->fresh(['seller.sellerDetail', 'product', 'messages']),
                withMessages: true,
            ),
        ], 201);
    }

    public function showConversation(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findForBuyer($request, $id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $this->markConversationRead($conversation, $request->user()->id);

        return response()->json([
            'data' => $this->transformConversation(
                $conversation->fresh(['seller.sellerDetail', 'product', 'messages']),
                withMessages: true,
            ),
        ]);
    }

    public function messages(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findForBuyer($request, $id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $messages = $conversation->messages()
            ->orderByDesc('created_at')
            ->limit(self::MESSAGE_PAGE)
            ->get()
            ->sortBy('created_at')
            ->values();

        return response()->json([
            'data' => $messages->map(fn (Message $m) => $this->transformMessage($m)),
        ]);
    }

    public function sendMessage(SendMessageRequest $request, string $id): JsonResponse
    {
        $conversation = $this->findForBuyer($request, $id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        if (! $this->conversationPolicy->sendMessage($request->user(), $conversation)) {
            return response()->json(['message' => 'This conversation is not open for new messages.'], 422);
        }

        $body = trim((string) $request->validated('body', ''));
        $attachmentIds = $request->validated('attachment_ids', []);

        $message = DB::transaction(function () use ($attachmentIds, $body, $conversation, $request) {
            return $this->appendMessage($conversation, $request->user(), 'buyer', $body, $attachmentIds);
        });

        return response()->json(['data' => $this->transformMessage($message)], 201);
    }

    public function uploadAttachment(UploadMessageAttachmentRequest $request): JsonResponse
    {
        $attachment = $this->messageAttachmentService->stage($request->user(), $request->file('file'));

        return response()->json(['data' => $attachment->toContractArray()], 201);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findForBuyer($request, $id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $this->markConversationRead($conversation, $request->user()->id);

        return response()->json(['data' => ['unread' => 0]]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = (int) Conversation::where('buyer_id', $request->user()->id)->sum('buyer_unread_count');

        return response()->json(['data' => ['count' => $count]]);
    }

    private function findForBuyer(Request $request, string $id): ?Conversation
    {
        return Conversation::query()
            ->where('buyer_id', $request->user()->id)
            ->where('type', '!=', 'support')
            ->whereHas('participantRecords', fn ($query) => $query
                ->where('user_id', $request->user()->id)
                ->whereNull('left_at'))
            ->whereKey($id)
            ->first();
    }

    /**
     * @param  list<string>  $attachmentIds
     */
    private function appendMessage(
        Conversation $conversation,
        Profile $sender,
        string $role,
        string $body,
        array $attachmentIds = [],
    ): Message {
        $stagedAttachments = $this->messageAttachmentService->findOwnedUnlinked($sender, $attachmentIds);

        if ($stagedAttachments->count() !== count($attachmentIds)) {
            throw ValidationException::withMessages([
                'attachment_ids' => 'One or more attachments are unavailable.',
            ]);
        }

        $message = $conversation->messages()->create([
            'sender_id' => $sender->id,
            'sender_role' => $role,
            'message_type' => $body === '' ? 'attachment' : 'text',
            'body' => $body,
            'attachments' => $stagedAttachments->map->toStoredArray()->all(),
        ]);

        $this->messageAttachmentService->linkToMessage($stagedAttachments, $message);

        $preview = $body !== ''
            ? mb_substr($body, 0, 160)
            : ($stagedAttachments->count() === 1 ? 'Sent an attachment' : 'Sent attachments');

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
            'last_message_preview' => $preview,
            'last_message_sender_role' => $role,
        ]);

        if ($role === 'buyer') {
            $conversation->seller_unread_count = $conversation->seller_unread_count + 1;
        } else {
            $conversation->buyer_unread_count = $conversation->buyer_unread_count + 1;
        }

        $conversation->save();

        return $message;
    }

    private function markConversationRead(Conversation $conversation, string $readerId): void
    {
        $conversation->messages()
            ->where('sender_role', 'seller')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $conversation->update(['buyer_unread_count' => 0]);
        $conversation->participantRecords()
            ->where('user_id', $readerId)
            ->update(['last_read_at' => now()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformConversation(Conversation $c, bool $withMessages = false): array
    {
        $sellerName = $c->seller?->sellerDetail?->business_name
            ?? $c->seller?->full_name
            ?? 'NEXMART Seller';

        $out = [
            'id' => $c->id,
            'seller' => $sellerName,
            'sellerId' => $c->seller_id,
            'status' => $c->status,
            // No presence system yet — always reported offline rather than
            // faked. memberSince is the seller account's real join year.
            'online' => false,
            'memberSince' => optional($c->seller?->created_at)->year,
            'unread' => (int) $c->buyer_unread_count,
            'updatedAt' => optional($c->last_message_at)->toIso8601String(),
            'lastMessagePreview' => $c->last_message_preview,
            'product' => $c->product ? [
                'id' => $c->product->id,
                'name' => $c->product->name,
                'price' => (float) $c->product->price,
                'oldPrice' => $c->product->compare_price ? (float) $c->product->compare_price : null,
            ] : null,
        ];

        if ($withMessages) {
            $out['messages'] = $c->messages
                ->sortBy('created_at')
                ->values()
                ->map(fn (Message $m) => $this->transformMessage($m))
                ->all();
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformMessage(Message $m): array
    {
        return [
            'id' => $m->id,
            'from' => $m->sender_role,
            'text' => $m->body,
            'attachments' => collect($m->attachments ?? [])->map(fn (array $attachment) => [
                'id' => $attachment['id'] ?? null,
                'name' => $attachment['name'] ?? 'attachment',
                'url' => MessageAttachment::contractUrlFor($attachment),
                'mime' => $attachment['mime'] ?? null,
                'size' => $attachment['size'] ?? null,
            ])->all(),
            'at' => optional($m->created_at)->toIso8601String(),
            'readAt' => optional($m->read_at)->toIso8601String(),
        ];
    }
}
