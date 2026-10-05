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
use App\Services\SupabaseStorageService;
use App\Support\Avatar;
use App\Support\ProductImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Buyer side of buyer <-> seller messaging (conversations / messages).
 *
 * Every query is scoped to conversations where buyer_id = the
 * authenticated buyer, so a buyer can never read or post into another
 * buyer's (or a seller-only) thread by guessing an id.
 *
 * - The inbox carries only what a list row needs (no message bodies).
 * - Messages page by cursor: `before` for older history, `after` for
 *   the poll that picks up new replies, ordered by (created_at, id).
 * - Buyers can attach photos. They go to the private message-attachments
 *   bucket (the seller app's bucket) and are stored as the same
 *   {id, name, path, mime, size} snapshot on messages.attachments, so the
 *   seller app shows them too; URLs are short-lived signed links made on
 *   every read, only for someone allowed to read the thread.
 * - Read status is real: the seller app sets read_at on a buyer's message
 *   when the seller opens the thread. There is no delivery or presence
 *   tracking, so neither is reported.
 *
 * The seller side of these same tables is built on feature/seller against
 * the contract in resources/js/seller/composables/useMessaging.js.
 */
class MessageController extends Controller
{
    private const MESSAGE_PAGE = 30;

    private const ATTACHMENTS_BUCKET = 'message-attachments';

    public function __construct(private SupabaseStorageService $storage) {}

    public function conversations(Request $request): JsonResponse
    {
        $buyer = $request->user();

        $conversations = Conversation::query()
            ->with(['seller.sellerDetail', 'product', 'order'])
            ->where('buyer_id', $buyer->id)
            ->orderByRaw('last_message_at desc nulls last')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $conversations->map(fn (Conversation $c) => $this->transformConversation($c)),
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

        $attachments = $this->storeImages($buyer->id, $request->file('images', []));

        if ($attachments === null) {
            return $this->uploadFailed();
        }

        $body = trim((string) ($data['body'] ?? ''));

        try {
            $conversation = DB::transaction(function () use ($buyer, $seller, $orderId, $productId, $data, $body, $attachments) {
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
                } elseif ($productId && $conversation->product_id !== $productId) {
                    // A new question about a different product: the thread's
                    // reference follows the product being asked about now.
                    $conversation->product_id = $productId;
                }

                $this->appendMessage($conversation, $buyer->id, 'buyer', $body, $attachments);

                return $conversation;
            });
        } catch (\Throwable $e) {
            $this->deleteAttachments($attachments);

            throw $e;
        }

        return response()->json(['data' => $this->conversationDetail($conversation->fresh())], 201);
    }

    /**
     * GET /api/buyer/messages/conversations/{id}
     *
     * Opening a thread is what marks the seller's messages read.
     */
    public function showConversation(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findForBuyer($request, $id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $this->markConversationRead($conversation);

        return response()->json(['data' => $this->conversationDetail($conversation->fresh())]);
    }

    /**
     * GET /api/buyer/messages/conversations/{id}/messages?before=<id>|after=<id>
     *
     * `before`: the page of older messages ending just before that one.
     * `after`: everything newer than that one (the poll). Neither: the
     * latest page. Does not mark anything read.
     */
    public function messages(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findForBuyer($request, $id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $before = $this->resolveCursor($conversation, $request->string('before')->toString());
        $after = $this->resolveCursor($conversation, $request->string('after')->toString());

        if ($after) {
            $messages = $conversation->messages()
                ->where(fn (Builder $q) => $this->newerThan($q, $after))
                ->reorder()
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit(200)
                ->get();

            return response()->json([
                'data' => $this->transformMessages($messages),
                'meta' => ['hasMore' => false],
            ]);
        }

        [$messages, $hasMore] = $this->latestPage($conversation->messages(), $before);

        return response()->json([
            'data' => $this->transformMessages($messages),
            'meta' => ['hasMore' => $hasMore],
        ]);
    }

    /**
     * POST /api/buyer/messages/conversations/{id}/messages
     * (JSON or multipart: body?, images[]?, client_id?, product_id?)
     */
    public function sendMessage(SendMessageRequest $request, string $id): JsonResponse
    {
        $conversation = $this->findForBuyer($request, $id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $buyer = $request->user();
        $body = trim((string) $request->validated('body', ''));
        $productId = $request->validated('product_id');

        if ($productId && ! Product::whereKey($productId)->where('seller_id', $conversation->seller_id)->exists()) {
            throw ValidationException::withMessages(['product_id' => 'That product does not belong to this seller.']);
        }

        $attachments = $this->storeImages($buyer->id, $request->file('images', []));

        if ($attachments === null) {
            return $this->uploadFailed();
        }

        try {
            $message = DB::transaction(function () use ($conversation, $buyer, $body, $attachments, $productId) {
                // Asking about another product: the reference follows it.
                if ($productId && $conversation->product_id !== $productId) {
                    $conversation->product_id = $productId;
                }

                return $this->appendMessage($conversation, $buyer->id, 'buyer', $body, $attachments);
            });
        } catch (\Throwable $e) {
            $this->deleteAttachments($attachments);

            throw $e;
        }

        return response()->json([
            'data' => array_merge(
                $this->transformMessages(collect([$message]))[0],
                ['clientId' => $request->validated('client_id')],
            ),
        ], 201);
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
            'data' => $this->transformConversation($conversation->fresh(['seller.sellerDetail', 'product', 'order'])),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = (int) Conversation::where('buyer_id', $request->user()->id)->sum('buyer_unread_count');

        return response()->json(['data' => ['count' => $count]]);
    }

    private function findForBuyer(Request $request, string $id): ?Conversation
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return Conversation::query()
            ->where('buyer_id', $request->user()->id)
            ->whereKey($id)
            ->first();
    }

    /**
     * @param  list<array{id: string, name: string, path: string, mime: string, size: int}>  $attachments
     */
    private function appendMessage(Conversation $conversation, string $senderId, string $role, string $body, array $attachments = []): Message
    {
        $message = $conversation->messages()->create([
            'sender_id' => $senderId,
            'sender_role' => $role,
            'body' => $body,
            'attachments' => $attachments,
        ]);

        $preview = $body !== ''
            ? mb_substr($body, 0, 160)
            : (count($attachments) === 1 ? 'Photo' : count($attachments).' photos');

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

    private function markConversationRead(Conversation $conversation): void
    {
        $conversation->messages()
            ->where('sender_role', 'seller')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $conversation->update(['buyer_unread_count' => 0]);
    }

    /**
     * @return array{id: string, name: string, path: string, mime: string, size: int}
     */
    /**
     * Uploads every photo or none: on a storage failure the ones already
     * uploaded are removed and null is returned.
     *
     * @param  array<int, UploadedFile>  $images
     * @return array<int, array<string, mixed>>|null
     */
    private function storeImages(string $buyerId, array $images): ?array
    {
        $attachments = [];

        try {
            foreach ($images as $image) {
                $attachments[] = $this->storeAttachment($buyerId, $image);
            }
        } catch (\RuntimeException $e) {
            report($e);
            $this->deleteAttachments($attachments);

            return null;
        }

        return $attachments;
    }

    private function uploadFailed(): JsonResponse
    {
        return response()->json(['message' => 'Your photo couldn\'t be uploaded. Please try again.'], 502);
    }

    private function storeAttachment(string $buyerId, UploadedFile $file): array
    {
        $mime = (string) $file->getMimeType();
        $path = $buyerId.'/'.Str::uuid().'.'.$this->storage->extensionForMime($mime);

        $this->storage->ensureBucket(self::ATTACHMENTS_BUCKET, public: false);
        $this->storage->upload(self::ATTACHMENTS_BUCKET, $path, (string) file_get_contents($file->getRealPath()), $mime);

        return [
            'id' => (string) Str::uuid(),
            'name' => mb_substr($file->getClientOriginalName() ?: 'photo', 0, 120),
            'path' => $path,
            'mime' => $mime,
            'size' => (int) $file->getSize(),
        ];
    }

    /**
     * @param  list<array{path: string}>  $attachments
     */
    private function deleteAttachments(array $attachments): void
    {
        $paths = array_values(array_filter(array_column($attachments, 'path')));

        if ($paths) {
            $this->storage->delete(self::ATTACHMENTS_BUCKET, $paths);
        }
    }

    private function resolveCursor(Conversation $conversation, string $messageId): ?Message
    {
        if (! Str::isUuid($messageId)) {
            return null;
        }

        return $conversation->messages()->whereKey($messageId)->first();
    }

    private function olderThan(Builder $query, Message $cursor): void
    {
        $query->where('created_at', '<', $cursor->created_at)
            ->orWhere(fn (Builder $q) => $q->where('created_at', $cursor->created_at)->where('id', '<', $cursor->id));
    }

    private function newerThan(Builder $query, Message $cursor): void
    {
        $query->where('created_at', '>', $cursor->created_at)
            ->orWhere(fn (Builder $q) => $q->where('created_at', $cursor->created_at)->where('id', '>', $cursor->id));
    }

    /**
     * The newest page (optionally ending before $before), oldest first.
     *
     * @param  HasMany<Message, Conversation>  $relation
     * @return array{0: Collection<int, Message>, 1: bool}
     */
    private function latestPage(HasMany $relation, ?Message $before): array
    {
        $rows = $relation
            ->when($before, fn ($q) => $q->where(fn (Builder $w) => $this->olderThan($w, $before)))
            // The relation sorts oldest first; this page wants newest first.
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MESSAGE_PAGE + 1)
            ->get();

        $hasMore = $rows->count() > self::MESSAGE_PAGE;

        return [$rows->take(self::MESSAGE_PAGE)->reverse()->values(), $hasMore];
    }

    /**
     * Inbox row: what the list shows, no message bodies.
     *
     * @return array<string, mixed>
     */
    private function transformConversation(Conversation $c): array
    {
        $sellerName = $c->seller?->sellerDetail?->business_name
            ?? $c->seller?->full_name
            ?? 'BuyTheWay Seller';

        return [
            'id' => $c->id,
            'seller' => $sellerName,
            'sellerId' => $c->seller_id,
            'sellerLogo' => Avatar::url($c->seller?->avatar_path ?? null),
            'sellerCategory' => $c->seller?->sellerDetail?->line_of_business,
            'status' => $c->status,
            'unread' => (int) $c->buyer_unread_count,
            'updatedAt' => optional($c->last_message_at ?? $c->created_at)->toIso8601String(),
            'lastMessagePreview' => $c->last_message_preview,
            'lastMessageFromMe' => $c->last_message_sender_role === 'buyer',
            'product' => $c->product ? [
                'id' => $c->product->id,
                'name' => $c->product->name,
            ] : null,
            'order' => $c->order ? ['number' => $c->order->order_number] : null,
        ];
    }

    /**
     * Thread view: the row, its product / order references and the latest
     * page of messages.
     *
     * @return array<string, mixed>
     */
    private function conversationDetail(Conversation $c): array
    {
        $c->loadMissing(['seller.sellerDetail', 'product', 'order.items']);

        [$messages, $hasMore] = $this->latestPage($c->messages(), null);

        return array_merge($this->transformConversation($c), [
            'product' => $this->productReference($c),
            'order' => $this->orderReference($c),
            'messages' => $this->transformMessages($messages),
            'hasMore' => $hasMore,
        ]);
    }

    /**
     * The product as it is today (current price), not a historical price.
     *
     * @return array<string, mixed>|null
     */
    private function productReference(Conversation $c): ?array
    {
        $product = $c->product;

        if (! $product || $product->seller_id !== $c->seller_id) {
            return null;
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'price' => (float) $product->price,
            'oldPrice' => $product->compare_price && (float) $product->compare_price > (float) $product->price
                ? (float) $product->compare_price
                : null,
            'image' => ProductImage::urls($product->images)[0] ?? null,
            'available' => $product->status === 'active',
        ];
    }

    /**
     * The order with the prices the buyer actually paid. Only when it is
     * this buyer's order with this seller.
     *
     * @return array<string, mixed>|null
     */
    private function orderReference(Conversation $c): ?array
    {
        $order = $c->order;

        if (! $order || $order->buyer_profile_id !== $c->buyer_id || $order->seller_id !== $c->seller_id) {
            return null;
        }

        return [
            'id' => $order->id,
            'number' => $order->order_number,
            'status' => $order->status,
            'placedAt' => optional($order->placed_at)->toIso8601String(),
            'total' => (float) $order->total,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->product_name,
                'variant' => $item->variant,
                'quantity' => (int) $item->quantity,
                'unitPrice' => (float) $item->unit_price,
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return list<array<string, mixed>>
     */
    private function transformMessages(Collection $messages): array
    {
        $paths = $messages
            ->flatMap(fn (Message $m) => collect($m->attachments ?? [])->pluck('path'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $signed = $paths ? $this->storage->createSignedUrls(self::ATTACHMENTS_BUCKET, $paths) : [];

        return $messages->map(fn (Message $m) => [
            'id' => $m->id,
            'from' => $m->sender_role,
            'text' => $m->body,
            'at' => optional($m->created_at)->toIso8601String(),
            'attachments' => collect($m->attachments ?? [])
                ->filter(fn ($a) => is_array($a))
                ->map(fn (array $a) => [
                    'id' => $a['id'] ?? null,
                    'name' => $a['name'] ?? 'attachment',
                    'mime' => $a['mime'] ?? null,
                    'size' => $a['size'] ?? null,
                    'url' => isset($a['path']) ? ($signed[$a['path']] ?? null) : null,
                ])
                ->values()
                ->all(),
            // Real read receipts for the buyer's own messages (the seller
            // app sets read_at when the seller opens the thread). No
            // "delivered" state exists, so none is claimed.
            'status' => $m->sender_role === 'buyer' ? ($m->read_at ? 'read' : 'sent') : null,
            'readAt' => optional($m->read_at)->toIso8601String(),
        ])->values()->all();
    }
}
