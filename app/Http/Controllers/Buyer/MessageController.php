<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\SendMessageRequest;
use App\Http\Requests\Buyer\StartConversationRequest;
use App\Http\Requests\Buyer\UpdateConversationStatusRequest;
use App\Http\Requests\Messaging\UploadMessageAttachmentRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
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
    private const CONVERSATIONS_PAGE_SIZE = 20;

    private const MAX_PER_PAGE = 50;

    private const MESSAGES_PAGE_SIZE = 10;

    public function __construct(
        private DirectConversationService $directConversationService,
        private ConversationPolicy $conversationPolicy,
        private MessageAttachmentService $messageAttachmentService,
    ) {}

    /**
     * GET /api/buyer/messages/conversations
     *
     * Paginated (page/per_page) and never eager-loads messages — a buyer's
     * inbox listing only needs the denormalised last_message_* columns for
     * its preview line, not every message of every thread. Use
     * messages() to load a conversation's actual messages, page by page.
     *
     * status=archived switches to the archived-only view (see setStatus());
     * any other value (or none) is the default inbox, which excludes
     * archived threads rather than mixing them in — unlike seller's
     * tabbed view, the buyer popup has no tabs, so "default" and
     * "archived" are the only two states.
     */
    public function conversations(Request $request): JsonResponse
    {
        $buyer = $request->user();
        $wantsArchived = $request->string('status')->toString() === 'archived';

        $query = Conversation::query()
            ->with(['seller.sellerDetail', 'courier', 'product', 'participantRecords'])
            ->where('buyer_id', $buyer->id)
            ->where('type', '!=', 'support')
            ->whereHas('participantRecords', function ($q) use ($buyer, $wantsArchived) {
                $q->where('user_id', $buyer->id)->whereNull('left_at');
                $wantsArchived ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at');
            })
            ->orderByRaw('last_message_at desc nulls last')
            ->orderByDesc('created_at');

        $perPage = min(
            max((int) ($request->integer('per_page') ?: self::CONVERSATIONS_PAGE_SIZE), 1),
            self::MAX_PER_PAGE,
        );
        $paginated = $query->paginate($perPage)->withQueryString();

        $activeParticipant = fn ($q) => $q->where('user_id', $buyer->id)->whereNull('left_at');

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (Conversation $c) => $this->transformConversation($c))->all(),
            'meta' => [
                'currentPage' => $paginated->currentPage(),
                'lastPage' => $paginated->lastPage(),
                'perPage' => $paginated->perPage(),
                'total' => $paginated->total(),
                'unread_total' => (int) Conversation::where('buyer_id', $buyer->id)
                    ->whereHas('participantRecords', fn ($q) => $activeParticipant($q)->whereNull('archived_at'))
                    ->sum('buyer_unread_count'),
                'archived_total' => (int) Conversation::where('buyer_id', $buyer->id)->where('type', '!=', 'support')
                    ->whereHas('participantRecords', fn ($q) => $activeParticipant($q)->whereNotNull('archived_at'))
                    ->count(),
            ],
        ]);
    }

    public function startConversation(StartConversationRequest $request): JsonResponse
    {
        $buyer = $request->user();
        $data = $request->validated();

        $conversation = DB::transaction(function () use ($buyer, $data) {
            $result = $this->directConversationService->findOrCreateBuyerSeller(
                $buyer,
                $data['seller_id'],
                $data['order_number'] ?? null,
                $data['product_id'] ?? null,
                $data['subject'] ?? null,
            );

            $this->appendMessage(
                $result['conversation'],
                $buyer,
                'buyer',
                $data['body'],
                orderId: $result['order']?->id,
                productId: $result['product']?->id,
            );

            return $result['conversation'];
        });

        return response()->json([
            'data' => $this->transformConversation(
                $conversation->fresh(['seller.sellerDetail', 'product', 'participantRecords', 'messages.order.items.product:id,images', 'messages.product']),
                withMessages: true,
            ),
        ], 201);
    }

    /**
     * GET /api/buyer/messages/conversations/{id}
     *
     * Conversation metadata only — no messages array. The thread's
     * messages are loaded separately via messages() below, page by page.
     */
    public function showConversation(Request $request, string $id): JsonResponse
    {
        // Eager-load everything transformConversation() needs up front,
        // instead of loading nothing here and re-fetching it all via
        // fresh() after markConversationRead()'s writes below — those
        // writes only touch messages.read_at, conversations.buyer_unread_
        // count (which Eloquent's update() already syncs onto this same
        // $conversation instance) and conversation_participants.last_read_
        // at, none of which transformConversation() reads from a relation
        // that needs re-fetching. Measured against production: this cut
        // the request from 10 sequential DB round-trips (~1s, each ~95-
        // 100ms of pooler/network overhead regardless of query complexity)
        // to 5.
        $conversation = $this->findForBuyer($request, $id, ['seller.sellerDetail', 'courier', 'product', 'participantRecords']);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $this->markConversationRead($conversation, $request->user()->id);

        return response()->json([
            'data' => $this->transformConversation($conversation),
        ]);
    }

    /**
     * GET /api/buyer/messages/conversations/{id}/products
     *
     * Backs the "Inquire about a certain product" picker in a buyer<->
     * seller conversation — this buyer's own orders with THIS seller,
     * newest first, 4 per page (mirrors Seller\MessageController::parcels()).
     * Each entry carries order_id/product_id ready to attach to a message
     * (see appendMessage()'s order_id/product_id params).
     */
    public function products(Request $request, string $id): JsonResponse
    {
        $buyer = $request->user();
        $conversation = $this->findForBuyer($request, $id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $paginated = Order::query()
            ->where('buyer_profile_id', $buyer->id)
            ->where('seller_id', $conversation->seller_id)
            ->with('items.product:id,images')
            ->orderByDesc('placed_at')
            ->paginate(4);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (Order $order) => $this->transformOrderPreview($order))->all(),
            'meta' => [
                'currentPage' => $paginated->currentPage(),
                'lastPage' => $paginated->lastPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    private function transformOrderPreview(Order $order): array
    {
        $items = $order->items;
        $firstItem = $items->first();
        $itemCount = $items->count();

        return [
            'orderId' => $order->id,
            'orderNumber' => $order->order_number,
            'productId' => $firstItem?->product_id,
            'previewName' => $itemCount > 1 ? "{$itemCount} items" : $firstItem?->product_name,
            'previewImage' => ($firstItem?->product?->images ?? [])[0]['url'] ?? null,
            'quantity' => $firstItem?->quantity,
            'itemCount' => $itemCount,
            'total' => (float) $order->total,
            'status' => $order->status,
        ];
    }

    /**
     * GET /api/buyer/messages/conversations/{id}/messages
     *
     * Cursor pagination by message id, mirroring the seller side
     * (App\Http\Controllers\Seller\MessageController::messages):
     *   - no cursor    -> the latest `limit` messages, returned oldest->newest
     *   - before=<id>  -> the `limit` messages immediately older than <id>
     *     (the popup's "scroll to top" load-more)
     *   - after=<id>   -> messages newer than <id> (the poll path, so an
     *     open thread only pulls in what's actually new instead of
     *     re-fetching the whole history every poll tick)
     * `meta.hasMore`/`meta.nextCursor` always describe the *older*
     * direction regardless of which cursor was used to fetch.
     */
    public function messages(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findForBuyer($request, $id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $limit = min(max((int) ($request->integer('limit') ?: self::MESSAGES_PAGE_SIZE), 1), 100);

        $beforeCursor = $this->resolveCursor($conversation->id, $request->string('before')->toString());
        $afterCursor = $this->resolveCursor($conversation->id, $request->string('after')->toString());

        if ($afterCursor) {
            $rows = Message::where('conversation_id', $conversation->id)
                ->with(['order.items.product:id,images', 'product'])
                ->where(fn ($q) => $this->tupleGreaterThan($q, $afterCursor))
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($limit)
                ->get();

            return response()->json([
                'data' => $rows->map(fn (Message $m) => $this->transformMessage($m))->all(),
                'meta' => ['hasMore' => false, 'nextCursor' => null],
            ]);
        }

        $query = Message::where('conversation_id', $conversation->id)->with(['order.items.product:id,images', 'product']);

        if ($beforeCursor) {
            $query->where(fn ($q) => $this->tupleLessThan($q, $beforeCursor));
        }

        // Pull newest-first so "latest N" / "N older than cursor" both work,
        // then flip to chronological for the client.
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->reverse()->values();

        $oldest = $rows->first();
        $hasMore = $oldest
            ? Message::where('conversation_id', $conversation->id)
                ->where(fn ($q) => $this->tupleLessThan($q, $oldest))
                ->exists()
            : false;

        return response()->json([
            'data' => $rows->map(fn (Message $m) => $this->transformMessage($m))->all(),
            'meta' => [
                'hasMore' => $hasMore,
                'nextCursor' => $oldest?->id,
            ],
        ]);
    }

    public function sendMessage(SendMessageRequest $request, string $id): JsonResponse
    {
        $buyer = $request->user();
        $conversation = $this->findForBuyer($request, $id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        if (! $this->conversationPolicy->sendMessage($buyer, $conversation)) {
            return response()->json(['message' => 'This conversation is not open for new messages.'], 422);
        }

        $body = trim((string) $request->validated('body', ''));
        $attachmentIds = $request->validated('attachment_ids', []);
        $orderId = $request->validated('order_id');
        $productId = $request->validated('product_id');

        // Never trust a client-sent order/product id at face value (same
        // rule as attachment_ids) — this backs the "Inquire about a
        // certain product" card, so both must actually belong to this
        // buyer<->seller pair.
        if ($orderId && ! Order::where('id', $orderId)->where('buyer_profile_id', $buyer->id)->where('seller_id', $conversation->seller_id)->exists()) {
            throw ValidationException::withMessages(['order_id' => 'That order does not belong to you.']);
        }

        if ($productId && ! Product::where('id', $productId)->where('seller_id', $conversation->seller_id)->exists()) {
            throw ValidationException::withMessages(['product_id' => 'That product does not belong to this seller.']);
        }

        $message = DB::transaction(function () use ($attachmentIds, $body, $conversation, $request, $orderId, $productId) {
            return $this->appendMessage($conversation, $request->user(), 'buyer', $body, $attachmentIds, $orderId, $productId);
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

    /**
     * PUT /api/buyer/messages/conversations/{id}/status
     *
     * `archived`/un-archiving (back to 'open' from an archived state) are
     * per-user (Conversation::archiveFor()/unarchiveFor() — see their
     * docblocks): they hide the thread from this buyer's own inbox without
     * touching the seller's copy or blocking anyone from writing into it,
     * the same way leaveFor()/deleteConversation() already never affects
     * the other side. A genuine 'resolved' <-> 'open' transition is still
     * the shared Conversation.status (Conversation::DIRECT_MESSAGE_TRANSITIONS)
     * — mirrors Seller\MessageController::setStatus().
     */
    public function setStatus(UpdateConversationStatusRequest $request, string $id): JsonResponse
    {
        // Eager-loaded up front (not just whatever findForBuyer() needs for
        // scoping) so the response below can reuse this same instance —
        // archiveFor()/unarchiveFor() keep participantRecords in sync
        // in-memory, so a second fresh() re-fetch is unnecessary round-trip
        // work for what's meant to feel instant.
        $conversation = $this->findForBuyer($request, $id, ['seller.sellerDetail', 'courier', 'product', 'participantRecords']);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $status = $request->validated('status');
        $userId = $request->user()->id;

        if ($status === 'archived') {
            $conversation->archiveFor($userId);
        } elseif ($status === 'open' && $conversation->isArchivedFor($userId)) {
            $conversation->unarchiveFor($userId);
        } elseif (! $conversation->canTransitionTo($status)) {
            return response()->json([
                'message' => "A conversation cannot move from {$conversation->status} to {$status}.",
            ], 422);
        } else {
            $conversation->forceFill(['status' => $status])->save();
        }

        return response()->json([
            'data' => $this->transformConversation($conversation),
        ]);
    }

    /**
     * DELETE /api/buyer/messages/conversations/{id}
     *
     * Removes the conversation from this buyer's own inbox only (see
     * Conversation::leaveFor()) — the seller's copy and the message
     * history are untouched, and it reappears for the buyer automatically
     * if either side messages the other again.
     */
    public function deleteConversation(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findForBuyer($request, $id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        if (! $this->conversationPolicy->delete($request->user(), $conversation)) {
            return response()->json(['message' => 'This conversation cannot be deleted.'], 422);
        }

        $conversation->leaveFor($request->user()->id);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = (int) Conversation::where('buyer_id', $request->user()->id)->sum('buyer_unread_count');

        return response()->json(['data' => ['count' => $count]]);
    }

    private function resolveCursor(string $conversationId, string $messageId): ?Message
    {
        if ($messageId === '') {
            return null;
        }

        return Message::where('conversation_id', $conversationId)->whereKey($messageId)->first();
    }

    private function tupleLessThan($query, Message $cursor): void
    {
        $query->where('created_at', '<', $cursor->created_at)
            ->orWhere(function ($q) use ($cursor) {
                $q->where('created_at', $cursor->created_at)->where('id', '<', $cursor->id);
            });
    }

    private function tupleGreaterThan($query, Message $cursor): void
    {
        $query->where('created_at', '>', $cursor->created_at)
            ->orWhere(function ($q) use ($cursor) {
                $q->where('created_at', $cursor->created_at)->where('id', '>', $cursor->id);
            });
    }

    /**
     * @param  array<int, string>  $with
     */
    private function findForBuyer(Request $request, string $id, array $with = []): ?Conversation
    {
        return Conversation::with($with)
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
        ?string $orderId = null,
        ?string $productId = null,
    ): Message {
        $stagedAttachments = $this->messageAttachmentService->findOwnedUnlinked($sender, $attachmentIds);

        if ($stagedAttachments->count() !== count($attachmentIds)) {
            throw ValidationException::withMessages([
                'attachment_ids' => 'One or more attachments are unavailable.',
            ]);
        }

        $isPureInquiryCard = $body === '' && $stagedAttachments->isEmpty() && ($orderId || $productId);

        $message = $conversation->messages()->create([
            'sender_id' => $sender->id,
            'sender_role' => $role,
            'message_type' => match (true) {
                $isPureInquiryCard => 'inquiry',
                $body === '' => 'attachment',
                default => 'text',
            },
            'body' => $body,
            'attachments' => $stagedAttachments->map->toStoredArray()->all(),
            'order_id' => $orderId,
            'product_id' => $productId,
        ]);

        $this->messageAttachmentService->linkToMessage($stagedAttachments, $message);

        $preview = match (true) {
            $body !== '' => mb_substr($body, 0, 160),
            $isPureInquiryCard => 'Sent a product inquiry',
            $stagedAttachments->count() === 1 => 'Sent an attachment',
            default => 'Sent attachments',
        };

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
            'last_message_preview' => $preview,
            'last_message_sender_role' => $role,
        ]);

        // Every message through this controller is sent by the buyer
        // themselves ($role is always literally 'buyer' — see this
        // method's call sites), so the counterparty whose unread count
        // needs bumping is whoever the OTHER participant is: the seller on
        // every ordinary buyer<->seller thread, or the courier on a
        // 'delivery' thread (DeliveryConversationService::findOrCreate() —
        // the "Message" button on the driver app's delivery-detail screen).
        $counterpartyUnreadColumn = $conversation->type === 'delivery' ? 'courier_unread_count' : 'seller_unread_count';
        $conversation->{$counterpartyUnreadColumn} = $conversation->{$counterpartyUnreadColumn} + 1;

        $conversation->save();
        $conversation->reviveLeftParticipants();

        return $message;
    }

    private function markConversationRead(Conversation $conversation, string $readerId): void
    {
        // 'seller' on every ordinary thread; a 'delivery' thread's
        // counterparty sends as 'driver' or 'courier' instead — whichever
        // this specific rider's role actually is, so match either.
        $counterpartyRoles = $conversation->type === 'delivery' ? ['driver', 'courier'] : ['seller'];

        $conversation->messages()
            ->whereIn('sender_role', $counterpartyRoles)
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
    /**
     * The "seller"-prefixed JSON keys here are a holdover name from when
     * every buyer conversation had a real seller on the other end (Chat.vue
     * still reads them under that name, purely as a display name/avatar —
     * never for anything seller-specific like a storefront link) — they now
     * really mean "this conversation's counterparty", which for a
     * 'delivery' thread (DeliveryConversationService::findOrCreate() — the
     * "Message" button on the driver app's delivery-detail screen) is the
     * courier who started it rather than a seller. Not renamed: internal
     * field names, never shown as literal text in the UI.
     */
    private function transformConversation(Conversation $c, bool $withMessages = false): array
    {
        $isDelivery = $c->type === 'delivery';
        $counterparty = $isDelivery ? $c->courier : $c->seller;
        $counterpartyName = $isDelivery
            ? ($counterparty?->full_name ?: 'Courier')
            : ($c->seller?->sellerDetail?->business_name ?? $c->seller?->full_name ?? 'BuyTheWay Seller');

        $out = [
            'id' => $c->id,
            'type' => $c->type,
            'seller' => $counterpartyName,
            'sellerId' => $isDelivery ? $c->courier_profile_id : $c->seller_id,
            // Reuses the Profile relation already eager-loaded for this
            // conversation (no extra query) — the `avatars` bucket is
            // public, so this is a stable URL, unlike message attachments'
            // signed links.
            'sellerAvatarUrl' => $counterparty?->avatar_url,
            // Lets the buyer's inbox badge a 'delivery' row as "Courier"
            // instead of showing every thread as if it were a seller.
            'sellerRole' => $isDelivery ? 'courier' : 'seller',
            'status' => $c->status,
            // Per-participant, not the shared `status` column — see
            // Conversation::archiveFor()/isArchivedFor(). Every conversation
            // in this controller already belongs to the authenticated
            // buyer, so the buyer IS the viewer here.
            'archived' => $c->isArchivedFor($c->buyer_id),
            // Activity-based presence (Profile::isOnline()), not a session
            // flag — reflects whether the counterparty has touched any
            // authenticated endpoint recently, refreshed on every
            // conversations-list poll so it doesn't need a dedicated request.
            'sellerOnline' => (bool) $counterparty?->isOnline(),
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
            // Which purchase (if any) this specific message/inquiry was
            // about — lets the thread show an inline inquiry card per
            // message instead of one static card for the whole
            // conversation, now that one thread can span several orders.
            // Carries enough (item count/total/a preview image+name) for
            // the "order placed" system message to render as a single
            // self-contained card instead of a bare "Order #X" chip plus a
            // separate redundant text bubble repeating the same numbers.
            'orderContext' => $m->order?->messagePreview(),
            'productContext' => $m->product ? [
                'id' => $m->product->id,
                'name' => $m->product->name,
                'price' => (float) $m->product->price,
                'image' => ($m->product->images ?? [])[0]['url'] ?? null,
                // Only resolvable when a message carries both order_id and
                // product_id (the "Inquire about a certain product" card).
                'quantity' => ($m->order_id && $m->product_id)
                    ? OrderItem::where('order_id', $m->order_id)->where('product_id', $m->product_id)->value('quantity')
                    : null,
            ] : null,
            'at' => optional($m->created_at)->toIso8601String(),
            'readAt' => optional($m->read_at)->toIso8601String(),
        ];
    }
}
