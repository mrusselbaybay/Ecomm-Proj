<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\SendMessageRequest;
use App\Http\Requests\Buyer\StartConversationRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

    public function conversations(Request $request): JsonResponse
    {
        $buyer = $request->user();

        $conversations = Conversation::query()
            ->with(['seller.sellerDetail', 'product'])
            ->where('buyer_id', $buyer->id)
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

        $seller = Profile::where('id', $data['seller_id'])->where('role', 'seller')->first();

        if (! $seller) {
            throw ValidationException::withMessages(['seller_id' => 'That seller is not available.']);
        }

        $orderId = null;

        if (! empty($data['order_number'])) {
            $order = Order::query()
                ->where('order_number', ltrim($data['order_number'], '#'))
                ->where('buyer_profile_id', $buyer->id)
                ->where('seller_id', $seller->id)
                ->first();

            if (! $order) {
                throw ValidationException::withMessages(['order_number' => 'That order was not found on your account.']);
            }

            $orderId = $order->id;
        }

        $productId = $data['product_id'] ?? null;

        if ($productId) {
            $productOk = Product::whereKey($productId)->where('seller_id', $seller->id)->exists();

            if (! $productOk) {
                throw ValidationException::withMessages(['product_id' => 'That product does not belong to this seller.']);
            }
        }

        $conversation = DB::transaction(function () use ($buyer, $seller, $orderId, $productId, $data) {
            $conversation = Conversation::query()
                ->where('buyer_id', $buyer->id)
                ->where('seller_id', $seller->id)
                ->when($orderId, fn ($q) => $q->where('order_id', $orderId), fn ($q) => $q->whereNull('order_id'))
                ->first();

            if (! $conversation) {
                $conversation = Conversation::create([
                    'buyer_id' => $buyer->id,
                    'seller_id' => $seller->id,
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'subject' => $data['subject'] ?? null,
                    'status' => 'open',
                ]);
            }

            $this->appendMessage($conversation, $buyer->id, 'buyer', $data['body']);

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

        $this->markConversationRead($conversation);

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

        $message = DB::transaction(function () use ($conversation, $request) {
            return $this->appendMessage($conversation, $request->user()->id, 'buyer', $request->validated('body'));
        });

        return response()->json(['data' => $this->transformMessage($message)], 201);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findForBuyer($request, $id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $this->markConversationRead($conversation);

        return response()->json(['data' => ['unread' => 0]]);
    }

    public function setStatus(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findForBuyer($request, $id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(Conversation::STATUSES)],
        ]);

        $conversation->update(['status' => $data['status']]);

        return response()->json([
            'data' => $this->transformConversation($conversation->fresh(['seller.sellerDetail', 'product'])),
        ]);
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
            ->whereKey($id)
            ->first();
    }

    private function appendMessage(Conversation $conversation, string $senderId, string $role, string $body): Message
    {
        $message = $conversation->messages()->create([
            'sender_id' => $senderId,
            'sender_role' => $role,
            'body' => $body,
        ]);

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
            'last_message_preview' => mb_substr($body, 0, 160),
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

    private function markConversationRead(Conversation $conversation): void
    {
        $conversation->messages()
            ->where('sender_role', 'seller')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $conversation->update(['buyer_unread_count' => 0]);
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
            'at' => optional($m->created_at)->toIso8601String(),
            'readAt' => optional($m->read_at)->toIso8601String(),
        ];
    }
}
