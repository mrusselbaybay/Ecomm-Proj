<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\UploadMessageAttachmentRequest;
use App\Http\Requests\Seller\ReportBuyerRequest;
use App\Http\Requests\Seller\SendSellerMessageRequest;
use App\Http\Requests\Seller\StartLogisticsConversationRequest;
use App\Http\Requests\Seller\UpdateConversationStatusRequest;
use App\Models\Complaint;
use App\Models\Conversation;
use App\Models\CourierApplication;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Order;
use App\Models\ParcelAssignment;
use App\Models\Product;
use App\Models\Profile;
use App\Policies\ConversationPolicy;
use App\Services\MessageAttachmentService;
use App\Services\ShipmentConversationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Backs resources/js/seller/components/Messages.vue via
 * resources/js/seller/composables/useMessaging.js — implementing the API
 * contract documented at the top of that composable, which until now had
 * no backend (every call 404'd and the UI showed a "not deployed" state).
 *
 * Same conventions as SellerOrderController / SellerFeedbackController:
 * every query is scoped by seller_id, and a conversation belonging to
 * another seller resolves as a plain 404 (never a 403 that would leak its
 * existence). The conversations/messages tables are shared with the buyer
 * side (Buyer\MessageController writes the other end); this controller
 * only ever acts as the seller participant.
 *
 * There is no realtime infrastructure in this project, so "new message"
 * delivery is the frontend polling GET .../messages?after=<id> — see the
 * composable's pollNewMessages().
 */
class MessageController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 50;

    private const MESSAGES_PAGE_SIZE = 10;

    public function __construct(
        private ConversationPolicy $conversationPolicy,
        private MessageAttachmentService $messageAttachmentService,
        private ShipmentConversationService $shipmentConversationService,
    ) {}

    public function startLogisticsConversation(StartLogisticsConversationRequest $request): JsonResponse
    {
        $seller = $request->user();
        $conversation = $this->shipmentConversationService->findOrCreate(
            $seller,
            $request->validated('parcel_assignment_id'),
        );

        $body = trim((string) $request->validated('body', ''));

        if ($body !== '' && $conversation->messages()->doesntExist()) {
            $this->storeMessage($conversation, $seller, $body, []);
        }

        return response()->json([
            'data' => $this->transformConversationDetail(
                $conversation->fresh(['buyer', 'order', 'product', 'logisticsCompany.owner', 'parcelAssignment.rider', 'participantRecords']),
            ),
        ], 201);
    }

    public function logisticsContacts(Request $request): JsonResponse
    {
        $assignments = ParcelAssignment::query()
            ->with(['order', 'logisticsCompany', 'rider'])
            ->whereNotNull('rider_profile_id')
            ->whereHas('order', fn (Builder $query) => $query->where('seller_id', $request->user()->id))
            ->whereHas('logisticsCompany', fn (Builder $query) => $query
                ->whereIn('region', ['Luzon', 'Visayas', 'Mindanao'])
                ->where('status', 'approved')
                ->where('account_status', 'active'))
            ->latest('assigned_at')
            ->get()
            ->filter(fn (ParcelAssignment $assignment) => CourierApplication::query()
                ->where('logistics_company_id', $assignment->logistics_company_id)
                ->where('courier_profile_id', $assignment->rider_profile_id)
                ->where('status', CourierApplication::STATUS_ACCEPTED)
                ->exists())
            ->values();

        return response()->json(['data' => $assignments->map(fn (ParcelAssignment $assignment) => [
            'parcel_assignment_id' => $assignment->id,
            'order_number' => $assignment->order?->order_number,
            'company' => $assignment->logisticsCompany?->company_name,
            'region' => $assignment->logisticsCompany?->region,
            'rider' => $assignment->rider?->full_name,
        ])]);
    }

    /**
     * GET /api/seller/messages/conversations/{id}/parcels
     *
     * Backs the "Inquire about a certain product" picker in a
     * seller<->logistics (type 'shipment') conversation — the seller's own
     * orders shipped through THIS conversation's logistics company,
     * excluding delivered ones, 4 per page (see Messages.vue's picker
     * grid). Each entry carries order_id/product_id ready to attach to a
     * message (see storeMessage()'s order_id/product_id params).
     */
    public function parcels(Request $request, string $id): JsonResponse
    {
        $seller = $request->user();
        $conversation = $this->findForSeller($request, $id);

        if (! $conversation || $conversation->type !== 'shipment' || ! $conversation->logistics_company_id) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $paginated = Order::query()
            ->where('seller_id', $seller->id)
            ->where('status', '!=', 'Delivered')
            ->whereHas('parcelAssignments', fn (Builder $q) => $q
                ->where('logistics_company_id', $conversation->logistics_company_id))
            ->with('items.product:id,images')
            ->orderByDesc('placed_at')
            ->paginate(4);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (Order $order) => $this->transformParcel($order))->all(),
            'meta' => [
                'currentPage' => $paginated->currentPage(),
                'lastPage' => $paginated->lastPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    private function transformParcel(Order $order): array
    {
        $items = $order->items;
        $firstItem = $items->first();
        $itemCount = $items->count();

        return [
            'orderId' => $order->id,
            'orderNumber' => $order->order_number,
            // Null until the seller/logistics actually dispatches it — a
            // parcel still "New"/"Confirmed"/etc. simply has no tracking
            // number yet.
            'trackingNumber' => $order->tracking_number,
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
     * GET /api/seller/messages/conversations
     *
     * Query: search?, status? (all|unread|needs_response|resolved|archived),
     * page?, per_page?.
     *
     * `statusCounts` is computed off the same search-filtered base (minus
     * the status filter) so the tab counts and the list never disagree —
     * the pattern SellerFeedbackController::index() uses.
     *
     * All five counts come out of ONE aggregate query (conditional COUNTs)
     * instead of five separate round-trips, and that same result supplies
     * the pagination `total` so Eloquent's paginate() doesn't run its own
     * extra COUNT query re-executing the (possibly ilike-searched) base
     * query a sixth time. Switching status tabs or typing a search term
     * both hit this endpoint on every change, so trimming ~6 sequential
     * DB round-trips down to 2 is what makes that feel instant instead of
     * sluggish.
     */
    public function conversations(Request $request): JsonResponse
    {
        $seller = $request->user();

        $base = $this->searchScopedQuery($seller->id, $request);

        // Reuses the same aggregate-COUNT helper the tab badges use
        // elsewhere on this controller (statusCounts()) rather than a
        // second, slightly different inline aggregate — one source of
        // truth for what each tab count means.
        $statusCounts = $this->statusCounts(clone $base);

        $status = $request->string('status')->toString();
        $total = match ($status) {
            'unread' => $statusCounts['unread'],
            'needs_response' => $statusCounts['needsResponse'],
            'resolved' => $statusCounts['resolved'],
            'archived' => $statusCounts['archived'],
            default => $statusCounts['all'],
        };

        $perPage = min(
            (int) ($request->integer('per_page') ?: self::DEFAULT_PER_PAGE),
            self::MAX_PER_PAGE,
        );
        $page = max((int) ($request->integer('page') ?: 1), 1);

        $rows = $this->applyStatusFilter(clone $base, $status)
            ->select('conversations.*')
            ->with(['buyer', 'order', 'product'])
            ->orderByRaw('conversations.last_message_at desc nulls last')
            ->orderByDesc('conversations.created_at')
            ->forPage($page, $perPage)
            ->get();

        $paginated = new LengthAwarePaginator($rows, $total, $perPage, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return response()->json([
            'data' => $rows->map(fn (Conversation $c) => $this->transformConversation($c))->all(),
            'meta' => [
                'currentPage' => $paginated->currentPage(),
                'lastPage' => $paginated->lastPage(),
                'perPage' => $paginated->perPage(),
                'total' => $paginated->total(),
                'statusCounts' => $statusCounts,
            ],
        ]);
    }

    /**
     * GET /api/seller/messages/export
     *
     * Same search/status filters as conversations() (minus pagination) —
     * one row per conversation, not per message, matching the "Export
     * Messages" label on the button that calls this (a full transcript
     * export would be a different, heavier feature this doesn't claim
     * to be). Same CSV pattern as SellerFeedbackController::export().
     */
    public function export(Request $request): Response
    {
        $seller = $request->user();

        $conversations = $this->applyStatusFilter(
            $this->searchScopedQuery($seller->id, $request),
            $request->string('status')->toString(),
        )
            ->select('conversations.*')
            ->with(['buyer', 'order'])
            ->orderByRaw('conversations.last_message_at desc nulls last')
            ->orderByDesc('conversations.created_at')
            ->get();

        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['Buyer', 'Order #', 'Status', 'Unread', 'Last Message', 'Last Message From', 'Last Activity'], ',', '"', '\\');

        foreach ($conversations as $c) {
            fputcsv($handle, [
                $c->buyer?->full_name ?: 'Buyer',
                $c->order?->order_number,
                ucfirst($c->status),
                $c->seller_unread_count,
                $c->last_message_preview,
                $c->last_message_sender_role ? ucfirst($c->last_message_sender_role) : '',
                optional($c->last_message_at ?? $c->updated_at)->format('Y-m-d H:i'),
            ], ',', '"', '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $filename = 'messages-export-'.now()->format('Y-m-d').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * GET /api/seller/messages/conversations/{id}
     */
    public function showConversation(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findForSeller($request, $id, ['buyer', 'courier', 'order', 'product', 'logisticsCompany.owner', 'parcelAssignment.rider', 'participantRecords']);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        return response()->json(['data' => $this->transformConversationDetail($conversation)]);
    }

    /**
     * GET /api/seller/messages/conversations/{id}/messages
     *
     * Cursor pagination by message id:
     *   - no cursor    -> the latest `limit` messages, returned oldest->newest
     *   - before=<id>  -> the `limit` messages immediately older than <id>
     *   - after=<id>   -> messages newer than <id> (the poll path)
     *
     * `meta.hasMore` / `meta.nextCursor` always describe the *older*
     * direction (scrolling up into history), regardless of which cursor
     * was used — matching the contract.
     */
    public function messages(Request $request, string $id): JsonResponse
    {
        $sellerId = $request->user()->id;
        $limit = min(max((int) ($request->integer('limit') ?: self::MESSAGES_PAGE_SIZE), 1), 100);
        $afterId = $request->string('after')->toString();

        // Realtime-poll hot path (?after=<id>): fires on every incoming
        // message while a thread is open, so the ownership check is folded
        // into this same cursor-lookup query instead of a separate
        // findForSeller() round trip first — cuts this path from 3
        // sequential DB round trips to 2. On this project's remote
        // Supabase pooler each round trip costs ~150-200ms regardless of
        // query complexity, so this is the dominant cost of polling a
        // thread, not row count. A cursor that doesn't resolve (wrong
        // owner, or the message was deleted) returns no messages rather
        // than falling back to "latest N" — safe by default.
        if ($afterId !== '') {
            $afterCursor = Message::where('conversation_id', $id)
                ->whereHas('conversation', fn (Builder $q) => $q
                    ->where('seller_id', $sellerId)
                    ->where('type', '!=', 'support')
                    ->whereHas('participantRecords', fn (Builder $p) => $p->where('user_id', $sellerId)->whereNull('left_at')))
                ->whereKey($afterId)
                ->first();

            if (! $afterCursor) {
                return response()->json(['data' => [], 'meta' => ['hasMore' => false, 'nextCursor' => null]]);
            }

            $rows = Message::where('conversation_id', $id)
                ->with(['order.items'])
                ->where(fn (Builder $q) => $this->tupleGreaterThan($q, $afterCursor))
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($limit)
                ->get();
            $this->hydrateProductContext($rows);

            return response()->json([
                'data' => $rows->map(fn (Message $m) => $this->transformMessage($m))->all(),
                'meta' => ['hasMore' => false, 'nextCursor' => null],
            ]);
        }

        // The common "just opened this thread" case (no cursor at all):
        // fold ownership into this one query (whereHas, like the ?after=
        // branch above) instead of a separate findForSeller() round trip,
        // and fetch one extra row to detect hasMore instead of a second
        // exists() round trip — collapses what used to be 3 sequential DB
        // round trips into 1. (The parallel showConversation() call this
        // always runs alongside already returns a proper 404 for an
        // invalid/foreign id, so this endpoint returning an empty list for
        // that case instead of its own 404 costs nothing in practice.)
        $beforeId = $request->string('before')->toString();

        $query = Message::where('conversation_id', $id)
            ->whereHas('conversation', fn (Builder $q) => $q
                ->where('seller_id', $sellerId)
                ->where('type', '!=', 'support')
                ->whereHas('participantRecords', fn (Builder $p) => $p->where('user_id', $sellerId)->whereNull('left_at')))
            ->with(['order.items']);

        if ($beforeId !== '') {
            $beforeCursor = Message::where('conversation_id', $id)->whereKey($beforeId)->first();

            if ($beforeCursor) {
                $query->where(fn (Builder $q) => $this->tupleLessThan($q, $beforeCursor));
            }
        }

        // Pull newest-first so "latest N" / "N older than cursor" both
        // work, then flip to chronological for the client. Fetching
        // limit+1 and trimming is what lets hasMore be read off this same
        // result set instead of a separate exists() query.
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->reverse()->values();
        $oldest = $rows->first();
        $this->hydrateProductContext($rows);

        return response()->json([
            'data' => $rows->map(fn (Message $m) => $this->transformMessage($m))->all(),
            'meta' => [
                'hasMore' => $hasMore,
                'nextCursor' => $oldest?->id,
            ],
        ]);
    }

    /**
     * transformMessage() needs each message's own `product` (productContext)
     * and, via Order::messagePreview(), each order item's `product`
     * (orderContext's thumbnail) — two separate BelongsTo targets on the
     * same `products` table. Eager-loading them as two nested relations
     * (`order.items.product` + `product`) fires two more round trips than
     * necessary, and the two often reference the *same* product (the
     * "Inquire about a certain product" card sets both product_id and
     * order_id together). This collects every product id either path
     * needs across the whole page, fetches them in ONE query, then injects
     * the result back via setRelation() so `$message->product` and
     * `$item->product` both resolve from memory — transformMessage() and
     * Order::messagePreview() need no changes, they just stop lazy-loading.
     *
     * @param  \Illuminate\Support\Collection<int, Message>  $messages
     */
    private function hydrateProductContext($messages): void
    {
        $productIds = collect();

        foreach ($messages as $message) {
            if ($message->product_id) {
                $productIds->push($message->product_id);
            }

            foreach ($message->order?->items ?? [] as $item) {
                if ($item->product_id) {
                    $productIds->push($item->product_id);
                }
            }
        }

        $productIds = $productIds->unique()->values();

        $products = $productIds->isEmpty()
            ? collect()
            : Product::query()->whereIn('id', $productIds)->get(['id', 'images', 'name', 'price'])->keyBy('id');

        foreach ($messages as $message) {
            $message->setRelation('product', $message->product_id ? $products->get($message->product_id) : null);

            foreach ($message->order?->items ?? [] as $item) {
                $item->setRelation('product', $item->product_id ? $products->get($item->product_id) : null);
            }
        }
    }

    /**
     * POST /api/seller/messages/conversations/{id}/messages
     */
    public function sendMessage(SendSellerMessageRequest $request, string $id): JsonResponse
    {
        $seller = $request->user();
        $conversation = $this->findForSeller($request, $id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        if (! $this->conversationPolicy->sendMessage($seller, $conversation)) {
            return response()->json(['message' => 'This conversation is not open for new messages.'], 422);
        }

        $body = trim((string) $request->validated('body', ''));
        $attachmentIds = $request->validated('attachment_ids', []);
        $orderId = $request->validated('order_id');
        $productId = $request->validated('product_id');

        $message = DB::transaction(function () use ($conversation, $seller, $body, $attachmentIds, $orderId, $productId) {
            return $this->storeMessage($conversation, $seller, $body, $attachmentIds, $orderId, $productId);
        });

        return response()->json(['data' => $this->transformMessage($message)], 201);
    }

    /** @param list<string> $attachmentIds */
    private function storeMessage(
        Conversation $conversation,
        Profile $seller,
        string $body,
        array $attachmentIds,
        ?string $orderId = null,
        ?string $productId = null,
    ): Message {
        $staged = $this->messageAttachmentService->findOwnedUnlinked($seller, $attachmentIds);

        if ($staged->count() !== count($attachmentIds)) {
            throw ValidationException::withMessages([
                'attachment_ids' => 'One or more attachments are unavailable.',
            ]);
        }

        // Never trust a client-sent order/product id at face value — same
        // "re-check it's actually theirs" rule as attachment_ids above,
        // since this is what backs the "inquire about a parcel" card sent
        // to logistics (see parcels()) as well as any other per-message
        // purchase context a seller attaches going forward.
        if ($orderId && ! Order::where('id', $orderId)->where('seller_id', $seller->id)->exists()) {
            throw ValidationException::withMessages(['order_id' => 'That order does not belong to you.']);
        }

        if ($productId && ! Product::where('id', $productId)->where('seller_id', $seller->id)->exists()) {
            throw ValidationException::withMessages(['product_id' => 'That product does not belong to you.']);
        }

        $isPureInquiryCard = $body === '' && $staged->isEmpty() && ($orderId || $productId);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $seller->id,
            'sender_role' => 'seller',
            'message_type' => match (true) {
                $isPureInquiryCard => 'inquiry',
                $body === '' => 'attachment',
                default => 'text',
            },
            'body' => $body,
            'attachments' => $staged->map->toStoredArray()->all(),
            'order_id' => $orderId,
            'product_id' => $productId,
        ]);

        $this->messageAttachmentService->linkToMessage($staged, $message);

        // The seller replying means they've seen everything in the
        // thread — clear their unread and stamp buyer messages read.
        Message::where('conversation_id', $conversation->id)
            ->where('sender_role', '!=', 'seller')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
            'last_message_preview' => match (true) {
                $body !== '' => Str::limit($body, 140),
                $isPureInquiryCard => 'Sent a parcel inquiry',
                $staged->count() === 1 => 'Sent an attachment',
                default => 'Sent attachments',
            },
            'last_message_sender_role' => 'seller',
            'seller_unread_count' => 0,
        ])->save();

        $conversation->increment(match ($conversation->type) {
            'shipment' => 'logistics_unread_count',
            'delivery' => 'courier_unread_count',
            default => 'buyer_unread_count',
        });

        $conversation->reviveLeftParticipants();

        return $message;
    }

    /**
     * PUT /api/seller/messages/conversations/{id}/read
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findForSeller($request, $id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        DB::transaction(function () use ($conversation) {
            Message::where('conversation_id', $conversation->id)
                ->where('sender_role', '!=', 'seller')
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            $conversation->forceFill(['seller_unread_count' => 0])->save();
            $conversation->participantRecords()
                ->where('user_id', $conversation->seller_id)
                ->update(['last_read_at' => now()]);
        });

        return response()->json(['data' => ['unreadCount' => 0]]);
    }

    /**
     * DELETE /api/seller/messages/conversations/{id}
     *
     * Removes the conversation from this seller's own inbox only (see
     * Conversation::leaveFor()) — the other side's copy and the message
     * history are untouched, and it reappears automatically if either side
     * messages the other again.
     */
    public function deleteConversation(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findForSeller($request, $id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        if (! $this->conversationPolicy->delete($request->user(), $conversation)) {
            return response()->json(['message' => 'This conversation cannot be deleted.'], 422);
        }

        $conversation->leaveFor($request->user()->id);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * PUT /api/seller/messages/conversations/{id}/status
     *
     * `archived`/un-archiving are per-user (Conversation::archiveFor()/
     * unarchiveFor()) — hides the thread from this seller's own inbox
     * without touching the buyer's (or logistics') copy or blocking anyone
     * from writing into it. A genuine 'resolved' <-> 'open' transition is
     * still the shared Conversation.status.
     */
    public function setStatus(UpdateConversationStatusRequest $request, string $id): JsonResponse
    {
        $conversation = $this->findForSeller($request, $id, ['buyer', 'courier', 'order', 'product', 'logisticsCompany.owner', 'parcelAssignment.rider', 'participantRecords']);

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

        // archiveFor()/unarchiveFor() keep participantRecords in sync
        // in-memory, so $conversation is already current — no need for a
        // second fresh() re-fetch of the exact same relations just loaded
        // two lines up.
        return response()->json([
            'data' => $this->transformConversationDetail($conversation),
        ]);
    }

    /**
     * GET /api/seller/messages/unread-count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = (int) Conversation::where('seller_id', $request->user()->id)
            ->where('type', '!=', 'support')
            ->whereHas('participantRecords', fn (Builder $query) => $query
                ->where('user_id', $request->user()->id)
                ->whereNull('left_at'))
            ->sum('seller_unread_count');

        return response()->json(['data' => ['count' => $count]]);
    }

    /**
     * POST /api/seller/messages/attachments  (multipart, field "file")
     *
     * Stores the file on the private message-attachment disk and returns
     * its id for the follow-up send.
     */
    public function uploadAttachment(UploadMessageAttachmentRequest $request): JsonResponse
    {
        $attachment = $this->messageAttachmentService->stage($request->user(), $request->file('file'));

        return response()->json(['data' => $attachment->toContractArray()], 201);
    }

    /**
     * POST /api/seller/messages/conversations/{id}/report
     *
     * Persists the report in the existing admin complaint workflow.
     */
    public function report(ReportBuyerRequest $request, string $id): JsonResponse
    {
        $seller = $request->user();
        $conversation = $this->findForSeller($request, $id, ['buyer']);

        // Only a genuine buyer<->seller thread has a buyer to report —
        // 'shipment' (logistics) and 'delivery' (courier) counterparties
        // go through their own moderation channels, not this one.
        if (! $conversation || in_array($conversation->type, ['shipment', 'delivery'], true)) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $complaint = Complaint::create([
            'complainant_id' => $seller->id,
            'respondent_id' => $conversation->buyer_id,
            'order_id' => $conversation->order_id,
            'type' => 'message_report',
            'subject' => 'Buyer messaging report',
            'description' => $request->validated('reason'),
            'evidence' => [['conversation_id' => $conversation->id]],
            'status' => 'pending',
            'priority' => 'normal',
        ]);

        return response()->json([
            'data' => ['reported' => true, 'complaint_id' => $complaint->id],
        ], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Joins (rather than whereHas()'s EXISTS subquery) the seller's own
     * participant row so its per-user `archived_at` (see
     * Conversation::archiveFor()) is available as a real, filterable/
     * aggregable column for applyStatusFilter()'s 'archived' branch and
     * statusCounts()'s aggregate — an EXISTS subquery can't expose a column
     * back to the outer query the way a join can.
     */
    private function searchScopedQuery(string $sellerId, Request $request): Builder
    {
        $query = Conversation::query()
            ->join('conversation_participants', function (JoinClause $join) use ($sellerId) {
                $join->on('conversation_participants.conversation_id', '=', 'conversations.id')
                    ->where('conversation_participants.user_id', $sellerId)
                    ->whereNull('conversation_participants.left_at');
            })
            ->where('conversations.seller_id', $sellerId)
            ->where('conversations.type', '!=', 'support');

        if ($search = $request->string('search')->toString()) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('conversations.subject', 'ilike', "%{$search}%")
                    ->orWhere('conversations.last_message_preview', 'ilike', "%{$search}%")
                    ->orWhereHas('buyer', function (Builder $bq) use ($search) {
                        $bq->where(DB::raw("(first_name || ' ' || last_name)"), 'ilike', "%{$search}%");
                    })
                    ->orWhereHas('logisticsCompany', fn (Builder $logisticsQuery) => $logisticsQuery
                        ->where('company_name', 'ilike', "%{$search}%"))
                    ->orWhereHas('order', function (Builder $oq) use ($search) {
                        $oq->where('order_number', 'ilike', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    /**
     * The 5 tab badges as ONE aggregate query (conditional SUMs) instead of
     * 5 separate COUNT(*) round-trips against the same filtered base — the
     * list load already costs a paginated query, so this was doubling the
     * request's DB round-trips for what's just header decoration.
     *
     * @return array{all: int, unread: int, needsResponse: int, resolved: int, archived: int}
     */
    private function statusCounts(Builder $base): array
    {
        $row = $base->selectRaw(<<<'SQL'
            COUNT(*) AS all_count,
            SUM(CASE WHEN conversations.seller_unread_count > 0 THEN 1 ELSE 0 END) AS unread_count,
            SUM(CASE WHEN conversations.status = 'open' AND conversations.last_message_sender_role != 'seller' THEN 1 ELSE 0 END) AS needs_response_count,
            SUM(CASE WHEN conversations.status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
            SUM(CASE WHEN conversation_participants.archived_at IS NOT NULL THEN 1 ELSE 0 END) AS archived_count
        SQL)->first();

        return [
            'all' => (int) $row->all_count,
            'unread' => (int) $row->unread_count,
            'needsResponse' => (int) $row->needs_response_count,
            'resolved' => (int) $row->resolved_count,
            'archived' => (int) $row->archived_count,
        ];
    }

    private function applyStatusFilter(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            'unread' => $query->where('conversations.seller_unread_count', '>', 0),
            'needs_response' => $query->where('conversations.status', 'open')->where('conversations.last_message_sender_role', '!=', 'seller'),
            'resolved' => $query->where('conversations.status', 'resolved'),
            'archived' => $query->whereNotNull('conversation_participants.archived_at'),
            default => $query,
        };
    }

    /**
     * @param  array<int, string>  $with
     */
    private function findForSeller(Request $request, string $id, array $with = []): ?Conversation
    {
        return Conversation::with($with)
            ->where('seller_id', $request->user()->id)
            ->where('type', '!=', 'support')
            ->whereHas('participantRecords', fn (Builder $query) => $query
                ->where('user_id', $request->user()->id)
                ->whereNull('left_at'))
            ->whereKey($id)
            ->first();
    }

    private function tupleLessThan(Builder $query, Message $cursor): void
    {
        $query->where('created_at', '<', $cursor->created_at)
            ->orWhere(function (Builder $q) use ($cursor) {
                $q->where('created_at', $cursor->created_at)->where('id', '<', $cursor->id);
            });
    }

    private function tupleGreaterThan(Builder $query, Message $cursor): void
    {
        $query->where('created_at', '>', $cursor->created_at)
            ->orWhere(function (Builder $q) use ($cursor) {
                $q->where('created_at', $cursor->created_at)->where('id', '>', $cursor->id);
            });
    }

    /**
     * The counterparty on a seller's conversation: the logistics company's
     * owner on a 'shipment' thread, the courier who started it on a
     * 'delivery' thread (DeliveryConversationService::findOrCreate() — the
     * "Message" button on the driver app's delivery-detail screen), or the
     * buyer on every other (buyer<->seller) thread type.
     */
    private function counterpartyProfile(Conversation $c): ?Profile
    {
        return match ($c->type) {
            'shipment' => $c->logisticsCompany?->owner,
            'delivery' => $c->courier,
            default => $c->buyer,
        };
    }

    private function counterpartyRole(Conversation $c): string
    {
        return match ($c->type) {
            'shipment' => 'logistics',
            'delivery' => 'courier',
            default => 'buyer',
        };
    }

    private function transformConversation(Conversation $c): array
    {
        $counterpartyProfile = $this->counterpartyProfile($c);
        $counterpartyName = match ($c->type) {
            'shipment' => $c->logisticsCompany?->company_name ?: 'Logistics',
            'delivery' => $counterpartyProfile?->full_name ?: 'Courier',
            default => $counterpartyProfile?->full_name ?: 'Buyer',
        };

        return [
            'id' => $c->id,
            'type' => $c->type,
            'status' => $c->status,
            // Per-participant, not the shared `status` column — see
            // Conversation::archiveFor()/isArchivedFor(). Every conversation
            // here already belongs to the authenticated seller.
            'archived' => $c->isArchivedFor($c->seller_id),
            'buyer' => [
                'id' => $c->type === 'shipment' ? $c->logisticsCompany?->owner_profile_id : $counterpartyProfile?->id,
                'name' => $counterpartyName,
                'initials' => $this->initialsFor($counterpartyName),
                // Reuses the Profile relation already eager-loaded for this
                // conversation (no extra query) — the `avatars` bucket is
                // public, so this is a stable URL, unlike message
                // attachments' signed links.
                'avatarUrl' => $c->type === 'shipment'
                    ? $c->logisticsCompany?->owner?->avatar_url
                    : $counterpartyProfile?->avatar_url,
                'role' => $this->counterpartyRole($c),
                // Activity-based presence (Profile::isOnline()) — refreshed
                // on every conversations poll, no dedicated request needed.
                'online' => (bool) $counterpartyProfile?->isOnline(),
            ],
            'shipment' => $c->parcelAssignment ? [
                'id' => $c->parcelAssignment->id,
                'region' => $c->logisticsCompany?->region,
                'company' => $c->logisticsCompany?->company_name,
                'rider' => $c->parcelAssignment->rider?->full_name,
            ] : null,
            'order' => $c->order ? [
                'id' => $c->order->order_number,
                'orderNumber' => $c->order->order_number,
                'status' => $c->order->status,
            ] : null,
            'product' => $c->product ? [
                'id' => $c->product->id,
                'name' => $c->product->name,
                'image' => $this->productImage($c->product),
                'variant' => null,
            ] : null,
            // Built from the denormalised last_message_* columns the
            // conversations table maintains — no extra query, and it
            // sidesteps latestOfMany()'s MAX(uuid) (see Conversation model).
            'lastMessage' => $c->last_message_at ? [
                'body' => $c->last_message_preview,
                'senderRole' => $c->last_message_sender_role,
                'createdAt' => optional($c->last_message_at)->toIso8601String(),
            ] : null,
            'unreadCount' => (int) $c->seller_unread_count,
            'needsResponse' => $c->status === 'open' && $c->last_message_sender_role !== 'seller',
            'updatedAt' => optional($c->last_message_at ?? $c->updated_at)->toIso8601String(),
        ];
    }

    private function transformConversationDetail(Conversation $c): array
    {
        $base = $this->transformConversation($c);

        $base['order'] = $c->order ? [
            'id' => $c->order->order_number,
            'orderNumber' => $c->order->order_number,
            'status' => $c->order->status,
            'total' => (float) $c->order->total,
            'deliveryStatus' => in_array($c->order->status, ['In Transit', 'Delivered'], true)
                ? $c->order->status
                : null,
            // Real order_status_history rows (same source Deliveries/Courier
            // Handover already use) — not a fabricated "Live Tracking" feed.
            'timeline' => $c->order->statusHistory->map(fn ($h) => [
                'status' => $h->status,
                'note' => $h->note,
                'at' => optional($h->created_at)->toIso8601String(),
            ])->all(),
        ] : null;

        $base['product'] = $c->product ? [
            'id' => $c->product->id,
            'name' => $c->product->name,
            'image' => $this->productImage($c->product),
            'variant' => null,
            'quantity' => null,
        ] : null;

        return $base;
    }

    private function transformMessage(Message $m): array
    {
        return [
            'id' => $m->id,
            'conversationId' => $m->conversation_id,
            'senderRole' => $m->sender_role,
            'body' => $m->body,
            // Re-signed fresh from `path` on every read, however old
            // the message — messages.attachments is a snapshot copied
            // once at send time (see sendMessage()), and message-
            // attachments is a private bucket, so trusting that
            // snapshot's own `url` would mean an old conversation's
            // images silently stop loading once that signature expires.
            'attachments' => collect($m->attachments ?? [])->map(fn ($a) => [
                'id' => $a['id'] ?? null,
                'name' => $a['name'] ?? 'attachment',
                'url' => MessageAttachment::contractUrlFor($a),
                'mime' => $a['mime'] ?? null,
                'size' => $a['size'] ?? null,
            ])->all(),
            // Which purchase (if any) this specific message/inquiry was
            // about — either the buyer's own inquiry context, or the
            // auto-generated "order placed" system message (see
            // DirectConversationService::startForOrder()) — shown here as
            // an inline card per message, since one thread can now span
            // several orders/products from the same buyer.
            'orderContext' => $m->order?->messagePreview(),
            'productContext' => $m->product ? [
                'id' => $m->product->id,
                'name' => $m->product->name,
                'price' => (float) $m->product->price,
                'image' => ($m->product->images ?? [])[0]['url'] ?? null,
                // The quantity of THIS product within THIS order — only
                // resolvable when a message carries both (the "Inquiring
                // About" parcel-inquiry card); null for a bare product
                // reference with no order attached. Read from the already
                // eager-loaded order.items instead of a fresh per-message
                // query — `order.items.product` is always loaded alongside
                // this message (see messages()).
                'quantity' => ($m->order_id && $m->product_id)
                    ? $m->order?->items?->firstWhere('product_id', $m->product_id)?->quantity
                    : null,
            ] : null,
            // Read receipts only make sense for the seller's own messages;
            // buyer messages carry no status (per the contract).
            'status' => $m->sender_role === 'seller' ? ($m->read_at ? 'read' : 'sent') : null,
            'createdAt' => optional($m->created_at)->toIso8601String(),
            'readAt' => optional($m->read_at)->toIso8601String(),
        ];
    }

    private function productImage($product): ?string
    {
        return ($product->images ?? [])[0]['url'] ?? null;
    }

    private function initialsFor(?string $name): string
    {
        if (! $name) {
            return '?';
        }

        $parts = preg_split('/\s+/', trim($name));
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last) ?: '?';
    }
}
