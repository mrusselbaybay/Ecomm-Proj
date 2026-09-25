<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\SendMessageRequest;
use App\Http\Requests\Logistics\UpdateConversationStatusRequest;
use App\Http\Requests\Messaging\UploadMessageAttachmentRequest;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\CourierApplication;
use App\Models\LogisticsCompany;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Product;
use App\Policies\ConversationPolicy;
use App\Services\MessageAttachmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    private const CONVERSATIONS_PAGE_SIZE = 20;

    private const MAX_PER_PAGE = 50;

    private const MESSAGES_PAGE_SIZE = 10;

    public function __construct(
        private ConversationPolicy $conversationPolicy,
        private MessageAttachmentService $messageAttachmentService,
    ) {}

    /**
     * Paginated (page/per_page). Mirrors Seller\MessageController::conversations():
     * `search` (seller name / order number / preview text) and `status`
     * (all|unread|needs_response|resolved|archived) narrow the same
     * search-scoped base query `statusCounts` is computed from, so the tab
     * counts and the list never disagree.
     */
    public function conversations(Request $request): JsonResponse
    {
        $company = $this->companyFor($request);
        $this->ensureRosterConversations($company, $request->user()->id);
        $base = $this->searchScopedQuery($request, $company);

        $statusCounts = $this->statusCounts(clone $base);
        // Computed in the same aggregate query as the rest of statusCounts()
        // (see its SUM(conversations.logistics_unread_count)) instead of a
        // second scopedQuery()->sum() round-trip.
        $unreadTotal = $statusCounts['unreadTotal'];
        unset($statusCounts['unreadTotal']);

        $query = $this->applyStatusFilter(clone $base, $request->string('status')->toString())
            ->select('conversations.*')
            ->with(['seller.sellerDetail', 'order', 'parcelAssignment.rider', 'courier.courierDetail', 'participantRecords'])
            ->orderByRaw('conversations.last_message_at desc nulls last')
            ->orderByDesc('conversations.created_at');

        $perPage = min(
            max((int) ($request->integer('per_page') ?: self::CONVERSATIONS_PAGE_SIZE), 1),
            self::MAX_PER_PAGE,
        );
        $paginated = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (Conversation $c) => $this->transformConversation($c, $request->user()->id))->all(),
            'meta' => [
                'currentPage' => $paginated->currentPage(),
                'lastPage' => $paginated->lastPage(),
                'perPage' => $paginated->perPage(),
                'total' => $paginated->total(),
                'unread_total' => $unreadTotal,
                'statusCounts' => $statusCounts,
            ],
        ]);
    }

    /**
     * PUT /api/logistics/messages/conversations/{id}/status
     *
     * `archived`/un-archiving are per-user (Conversation::archiveFor()/
     * unarchiveFor()) — hides the thread from this logistics account's own
     * inbox without touching the seller's copy or blocking anyone from
     * writing into it. A genuine 'resolved' <-> 'open' transition is still
     * the shared Conversation.status — mirrors Seller\MessageController::setStatus().
     */
    public function setStatus(UpdateConversationStatusRequest $request, string $id): JsonResponse
    {
        $conversation = $this->findConversation($request, $id);
        abort_unless($conversation, 404);

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
        // in-memory, and findConversation() already eager-loaded everything
        // transformConversation() reads — no need for a second fresh()
        // re-fetch (which also used to omit logisticsCompany, relying on a
        // silent lazy-load lower down).
        return response()->json(['data' => $this->transformConversation($conversation, $userId)]);
    }

    /**
     * POST /api/logistics/messages/attachments
     */
    public function uploadAttachment(UploadMessageAttachmentRequest $request): JsonResponse
    {
        $attachment = $this->messageAttachmentService->stage($request->user(), $request->file('file'));

        return response()->json(['data' => $attachment->toContractArray()], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findConversation($request, $id);

        abort_unless($conversation, 404);

        // markConversationRead() below is a transaction across 3 UPDATE
        // statements on 3 tables — real writes, but nothing the response
        // itself needs to wait on: the frontend already patches its own
        // `unread = 0` the instant a thread is opened (see openConversation()
        // in Messages.vue). Reflecting that in-memory here (not persisted —
        // just what transformConversation() below reads) and running the
        // actual writes after the response has been sent takes them off
        // the blocking path entirely.
        $conversation->logistics_unread_count = 0;
        $userId = $request->user()->id;

        $response = response()->json(['data' => $this->transformConversation($conversation, $userId)]);

        // app()->terminating() (not dispatch()->afterResponse(), which
        // routes a closure through the queue dispatcher and serializes it
        // — on unserialize that re-fetches $conversation and its relations
        // from scratch, turning 3 writes into 6+ queries) — a terminating
        // callback runs in the same process after the response is sent,
        // keeping the exact in-memory $conversation with everything
        // already loaded.
        app()->terminating(fn () => $this->markConversationRead($conversation, $userId));

        return $response;
    }

    /**
     * Cursor pagination by message id, mirroring Buyer/Seller\MessageController::messages():
     *   - no cursor    -> the latest `limit` messages, returned oldest->newest
     *   - before=<id>  -> the `limit` messages immediately older than <id>
     *   - after=<id>   -> messages newer than <id> (the poll path — pulls in
     *     only what's actually new instead of re-fetching the whole thread)
     */
    public function messages(Request $request, string $id): JsonResponse
    {
        $limit = min(max((int) ($request->integer('limit') ?: self::MESSAGES_PAGE_SIZE), 1), 100);
        $afterId = $request->string('after')->toString();

        // Realtime-poll hot path (?after=<id>): fires on every incoming
        // message while a thread is open, so the ownership check is folded
        // into this same cursor-lookup query instead of a separate
        // findConversation() round trip first — cuts this path from 3
        // sequential DB round trips to 2 (companyFor() is cache-backed, so
        // it rarely adds a round trip of its own). On this project's
        // remote Supabase pooler each round trip costs ~150-200ms
        // regardless of query complexity, so this is the dominant cost of
        // polling a thread, not row count. A cursor that doesn't resolve
        // (wrong owner, or the message was deleted) returns no messages
        // rather than falling back to "latest N" — safe by default.
        if ($afterId !== '') {
            $company = $this->companyFor($request);
            $userId = $request->user()->id;

            $afterCursor = Message::where('conversation_id', $id)
                ->whereHas('conversation', fn ($q) => $q
                    ->whereIn('type', ['shipment', 'roster'])
                    ->where('logistics_company_id', $company->id)
                    ->whereHas('participantRecords', fn ($p) => $p->where('user_id', $userId)->whereNull('left_at')))
                ->whereKey($afterId)
                ->first();

            if (! $afterCursor) {
                return response()->json(['data' => [], 'meta' => ['hasMore' => false, 'nextCursor' => null]]);
            }

            $rows = Message::where('conversation_id', $id)
                ->with(['order.items'])
                ->where(fn ($q) => $this->tupleGreaterThan($q, $afterCursor))
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
        // branch above) instead of a separate findConversation() round
        // trip, and fetch one extra row to detect hasMore instead of a
        // second exists() round trip — collapses what used to be 3
        // sequential DB round trips into 1 (companyFor() is cache-backed
        // so it rarely adds one of its own). The parallel show() call this
        // always runs alongside already returns a proper 404 for an
        // invalid/foreign id, so this endpoint returning an empty list for
        // that case instead of its own 404 costs nothing in practice.
        $company = $this->companyFor($request);
        $userId = $request->user()->id;
        $beforeId = $request->string('before')->toString();

        $query = Message::where('conversation_id', $id)
            ->whereHas('conversation', fn ($q) => $q
                ->whereIn('type', ['shipment', 'roster'])
                ->where('logistics_company_id', $company->id)
                ->whereHas('participantRecords', fn ($p) => $p->where('user_id', $userId)->whereNull('left_at')))
            ->with(['order.items']);

        if ($beforeId !== '') {
            $beforeCursor = Message::where('conversation_id', $id)->whereKey($beforeId)->first();

            if ($beforeCursor) {
                $query->where(fn ($q) => $this->tupleLessThan($q, $beforeCursor));
            }
        }

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
     * "Inquiring About" parcel-inquiry card sets both product_id and
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

    public function send(SendMessageRequest $request, string $id): JsonResponse
    {
        $conversation = $this->findConversation($request, $id);
        abort_unless($conversation, 404);

        if (! $this->conversationPolicy->sendMessage($request->user(), $conversation)) {
            return response()->json(['message' => 'This conversation is not open for new messages.'], 422);
        }

        $body = trim((string) $request->validated('body', ''));
        $attachmentIds = $request->validated('attachment_ids', []);

        $message = DB::transaction(function () use ($body, $attachmentIds, $conversation, $request): Message {
            $staged = $this->messageAttachmentService->findOwnedUnlinked($request->user(), $attachmentIds);

            if ($staged->count() !== count($attachmentIds)) {
                throw ValidationException::withMessages([
                    'attachment_ids' => 'One or more attachments are unavailable.',
                ]);
            }

            $message = $conversation->messages()->create([
                'sender_id' => $request->user()->id,
                'sender_role' => 'logistics',
                'message_type' => $body === '' ? 'attachment' : 'text',
                'body' => $body,
                'attachments' => $staged->map->toStoredArray()->all(),
            ]);

            $this->messageAttachmentService->linkToMessage($staged, $message);

            $preview = $body !== ''
                ? mb_substr($body, 0, 160)
                : ($staged->count() === 1 ? 'Sent an attachment' : 'Sent attachments');

            $conversation->forceFill([
                'last_message_at' => $message->created_at,
                'last_message_preview' => $preview,
                'last_message_sender_role' => 'logistics',
                'logistics_unread_count' => 0,
                'seller_unread_count' => $conversation->seller_unread_count + 1,
            ])->save();

            $conversation->messages()->where('sender_role', 'seller')->whereNull('read_at')->update(['read_at' => now()]);
            $conversation->reviveLeftParticipants();

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

    /**
     * DELETE /api/logistics/messages/conversations/{id}
     *
     * Removes the conversation from this rider company's own inbox only
     * (see Conversation::leaveFor()) — the seller's copy and the message
     * history are untouched, and it reappears automatically if either side
     * messages the other again.
     */
    public function deleteConversation(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findConversation($request, $id);
        abort_unless($conversation, 404);

        if (! $this->conversationPolicy->delete($request->user(), $conversation)) {
            return response()->json(['message' => 'This conversation cannot be deleted.'], 422);
        }

        $conversation->leaveFor($request->user()->id);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $company = $this->companyFor($request);
        $this->ensureRosterConversations($company, $request->user()->id);
        $count = (int) $this->scopedQuery($request, $company)->sum('logistics_unread_count');

        return response()->json(['data' => ['count' => $count]]);
    }

    /**
     * Every currently-employed courier (an 'accepted' CourierApplication —
     * see the STATUS_ACCEPTED comment on that model, the source of truth
     * for employment; courier_details.logistics_company_id is never kept in
     * sync) automatically gets a 'roster' conversation with this company's
     * owner, so the inbox lists them even before either side has sent a
     * message — mirrors ShipmentConversationService::findOrCreate()'s
     * firstOrCreate pattern, just for the whole roster at once instead of
     * one parcel assignment.
     *
     * Only couriers can read this yet — there is no courier-side inbox —
     * so this only ever needs to run for the logistics owner's own view,
     * and only has to reconcile new hires, never remove anyone (leaving a
     * stale thread around after a courier is let go preserves history the
     * same way every other conversation type does).
     *
     * Cached per company for a few minutes so the frequent inbox poll
     * doesn't re-run the diff query on every tick — a brand new hire simply
     * takes up to that long to appear automatically.
     */
    private function ensureRosterConversations(LogisticsCompany $company, string $ownerId): void
    {
        $cacheKey = "logistics_roster_ensured:{$company->id}";
        if (Cache::has($cacheKey)) {
            return;
        }

        $employedCourierIds = CourierApplication::query()
            ->where('logistics_company_id', $company->id)
            ->where('status', CourierApplication::STATUS_ACCEPTED)
            ->pluck('courier_profile_id');

        if ($employedCourierIds->isNotEmpty()) {
            $existingCourierIds = Conversation::query()
                ->where('type', 'roster')
                ->where('logistics_company_id', $company->id)
                ->pluck('courier_profile_id');

            foreach ($employedCourierIds->diff($existingCourierIds) as $courierId) {
                DB::transaction(function () use ($company, $courierId, $ownerId): void {
                    $conversation = Conversation::query()->firstOrCreate(
                        ['context_key' => Conversation::makeContextKey('roster', $courierId, [$ownerId, $courierId])],
                        [
                            'type' => 'roster',
                            'created_by' => $ownerId,
                            'logistics_company_id' => $company->id,
                            'courier_profile_id' => $courierId,
                            'status' => 'open',
                        ],
                    );

                    foreach ([$ownerId, $courierId] as $participantId) {
                        ConversationParticipant::query()->firstOrCreate(
                            ['conversation_id' => $conversation->id, 'user_id' => $participantId],
                            ['joined_at' => now()],
                        );
                    }
                });
            }
        }

        Cache::put($cacheKey, true, now()->addMinutes(3));
    }

    /**
     * Every messaging endpoint on this controller resolves the requester's
     * company before it can do anything else — on top of the auth
     * middleware's own already-remote lookup, that's a second full
     * round-trip to the (also remote) database per request. A logistics
     * company's approval/active status changes rarely, so a short cache is
     * a safe trade for cutting that in half.
     *
     * Caches the plain attribute array, not the model instance — caching a
     * raw Eloquent model relies on generic PHP serialize()/unserialize(),
     * which doesn't reliably survive a round-trip through a cache store
     * (surfaces as __PHP_Incomplete_Class on read). newFromBuilder() is the
     * standard safe way to reconstruct a model from attributes already
     * known to have come from the database.
     */
    private function companyFor(Request $request): LogisticsCompany
    {
        $profileId = $request->user()->id;

        $attributes = Cache::remember("logistics_company_for:{$profileId}", now()->addMinute(), fn () => LogisticsCompany::query()
            ->forMember($profileId)
            ->where('status', 'approved')
            ->where('account_status', 'active')
            ->firstOrFail()
            ->getAttributes());

        return (new LogisticsCompany)->newFromBuilder($attributes);
    }

    private function scopedQuery(Request $request, LogisticsCompany $company): Builder
    {
        return Conversation::query()
            ->whereIn('type', ['shipment', 'roster'])
            ->where('logistics_company_id', $company->id)
            ->whereHas('participantRecords', fn (Builder $query) => $query
                ->where('user_id', $request->user()->id)->whereNull('left_at'));
    }

    /**
     * scopedQuery() plus the optional `search` param, and a join (not
     * scopedQuery()'s whereHas() EXISTS subquery) onto this logistics
     * account's own participant row so its per-user `archived_at` (see
     * Conversation::archiveFor()) is available as a real column for
     * applyStatusFilter()'s 'archived' branch and statusCounts()'s
     * aggregate. Mirrors Seller\MessageController::searchScopedQuery().
     */
    private function searchScopedQuery(Request $request, LogisticsCompany $company): Builder
    {
        $userId = $request->user()->id;

        $query = Conversation::query()
            ->join('conversation_participants', function (JoinClause $join) use ($userId) {
                $join->on('conversation_participants.conversation_id', '=', 'conversations.id')
                    ->where('conversation_participants.user_id', $userId)
                    ->whereNull('conversation_participants.left_at');
            })
            ->whereIn('conversations.type', ['shipment', 'roster'])
            ->where('conversations.logistics_company_id', $company->id);

        if ($search = $request->string('search')->toString()) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('conversations.last_message_preview', 'ilike', "%{$search}%")
                    ->orWhereHas('seller', function (Builder $sq) use ($search) {
                        $sq->where(DB::raw("(first_name || ' ' || last_name)"), 'ilike', "%{$search}%");
                    })
                    ->orWhereHas('courier', function (Builder $cq) use ($search) {
                        $cq->where(DB::raw("(first_name || ' ' || last_name)"), 'ilike', "%{$search}%");
                    })
                    ->orWhereHas('order', function (Builder $oq) use ($search) {
                        $oq->where('order_number', 'ilike', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    private function applyStatusFilter(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            'unread' => $query->where('conversations.logistics_unread_count', '>', 0),
            'needs_response' => $query->where('conversations.status', 'open')->where('conversations.last_message_sender_role', '!=', 'logistics'),
            'resolved' => $query->where('conversations.status', 'resolved'),
            'archived' => $query->whereNotNull('conversation_participants.archived_at'),
            default => $query,
        };
    }

    /**
     * The 5 tab badges as one aggregate query — see
     * Seller\MessageController::statusCounts() for why this replaces 5
     * separate COUNT(*) round-trips.
     *
     * @return array{all: int, unread: int, needsResponse: int, resolved: int, archived: int}
     */
    private function statusCounts(Builder $base): array
    {
        $row = $base->selectRaw(<<<'SQL'
            COUNT(*) AS all_count,
            SUM(CASE WHEN conversations.logistics_unread_count > 0 THEN 1 ELSE 0 END) AS unread_count,
            SUM(CASE WHEN conversations.status = 'open' AND conversations.last_message_sender_role != 'logistics' THEN 1 ELSE 0 END) AS needs_response_count,
            SUM(CASE WHEN conversations.status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
            SUM(CASE WHEN conversation_participants.archived_at IS NOT NULL THEN 1 ELSE 0 END) AS archived_count,
            SUM(conversations.logistics_unread_count) AS unread_total
        SQL)->first();

        return [
            'all' => (int) $row->all_count,
            'unread' => (int) $row->unread_count,
            'needsResponse' => (int) $row->needs_response_count,
            'resolved' => (int) $row->resolved_count,
            'archived' => (int) $row->archived_count,
            'unreadTotal' => (int) $row->unread_total,
        ];
    }

    private function findConversation(Request $request, string $id): ?Conversation
    {
        $company = $this->companyFor($request);

        return $this->scopedQuery($request, $company)
            ->with(['seller.sellerDetail', 'order', 'parcelAssignment.rider', 'courier.courierDetail', 'logisticsCompany', 'participantRecords'])
            ->whereKey($id)
            ->first();
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

    private function markConversationRead(Conversation $conversation, string $readerId): void
    {
        DB::transaction(function () use ($conversation, $readerId): void {
            $conversation->messages()->where('sender_role', 'seller')->whereNull('read_at')->update(['read_at' => now()]);
            $conversation->update(['logistics_unread_count' => 0]);
            $conversation->participantRecords()->where('user_id', $readerId)->update(['last_read_at' => now()]);
        });
    }

    private function transformConversation(Conversation $conversation, string $viewerId): array
    {
        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'status' => $conversation->status,
            // Per-participant, not the shared `status` column — see
            // Conversation::archiveFor()/isArchivedFor(). Unlike buyer/
            // seller, a shipment conversation has no direct "logistics_id"
            // column, so every call site passes the requester's own id.
            'archived' => $conversation->isArchivedFor($viewerId),
            'seller' => [
                'id' => $conversation->seller_id,
                'name' => $conversation->seller?->sellerDetail?->business_name ?: $conversation->seller?->full_name,
                // Reuses the Profile relation already eager-loaded for this
                // conversation (no extra query) — the `avatars` bucket is
                // public, so this is a stable URL, unlike message
                // attachments' signed links.
                'avatarUrl' => $conversation->seller?->avatar_url,
                // Activity-based presence (Profile::isOnline()) — refreshed
                // on every conversations poll, no dedicated request needed.
                'online' => (bool) $conversation->seller?->isOnline(),
            ],
            // Only populated for type === 'roster' — a thread with one of
            // this company's own employed couriers (see
            // ensureRosterConversations()), null on a 'shipment' thread.
            'courier' => $conversation->type === 'roster' ? [
                'id' => $conversation->courier_profile_id,
                'name' => $conversation->courier?->full_name,
                'avatarUrl' => $conversation->courier?->avatar_url,
                'online' => (bool) $conversation->courier?->isOnline(),
                'vehicle' => $conversation->courier?->courierDetail?->vehicle,
            ] : null,
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
            // The seller's "Inquiring About" parcel-inquiry card (see
            // Seller\MessageController::storeMessage()'s order_id/
            // product_id) — without these, a card-only message (empty
            // body) rendered as nothing at all on the logistics side.
            'orderContext' => $message->order?->messagePreview(),
            'productContext' => $message->product ? [
                'id' => $message->product->id,
                'name' => $message->product->name,
                'price' => (float) $message->product->price,
                'image' => ($message->product->images ?? [])[0]['url'] ?? null,
                // Read from the already eager-loaded order.items instead of
                // a fresh per-message query — `order.items.product` is
                // always loaded alongside this message (see messages()).
                'quantity' => ($message->order_id && $message->product_id)
                    ? $message->order?->items?->firstWhere('product_id', $message->product_id)?->quantity
                    : null,
            ] : null,
            'at' => optional($message->created_at)->toIso8601String(),
            'read_at' => optional($message->read_at)->toIso8601String(),
        ];
    }
}
