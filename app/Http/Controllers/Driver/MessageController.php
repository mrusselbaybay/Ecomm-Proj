<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\SendMessageRequest;
use App\Http\Requests\Driver\StartDeliveryConversationRequest;
use App\Http\Requests\Driver\UpdateConversationStatusRequest;
use App\Http\Requests\Messaging\UploadMessageAttachmentRequest;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\CourierApplication;
use App\Models\LogisticsCompany;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Product;
use App\Policies\ConversationPolicy;
use App\Services\DeliveryConversationService;
use App\Services\MessageAttachmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Messaging for the driver/courier mobile app. Two conversation types live
 * here: 'roster' (Logistics\MessageController's other end — a logistics
 * company's thread with one of its employed riders, always has a
 * logistics_company_id) and 'delivery' (this controller's own —
 * DeliveryConversationService::findOrCreate(), a rider's direct thread with
 * one delivery's buyer or seller, always has an order_id + exactly one of
 * buyer_id/seller_id). Mirrors the Logistics/Seller/Buyer controllers'
 * shape (paginated inbox, cursor-paginated messages, denormalised unread
 * counts, per-user archive) so the same client-side polling/caching pattern
 * already proven there applies unchanged here.
 */
class MessageController extends Controller
{
    private const CONVERSATIONS_PAGE_SIZE = 20;

    private const MAX_PER_PAGE = 50;

    private const MESSAGES_PAGE_SIZE = 20;

    /** Every conversation type this controller's rider ever participates in. */
    private const TYPES = ['roster', 'delivery'];

    public function __construct(
        private ConversationPolicy $conversationPolicy,
        private MessageAttachmentService $messageAttachmentService,
        private DeliveryConversationService $deliveryConversationService,
    ) {}

    /**
     * Paginated (page/per_page) inbox. `search` (employer name / preview
     * text) and `status` (all|unread|needs_response|resolved|archived)
     * narrow the same search-scoped base query statusCounts is computed
     * from, so the tab counts and the list never disagree.
     */
    public function conversations(Request $request): JsonResponse
    {
        $courierId = $request->user()->id;
        $this->ensureOwnRosterConversations($courierId);

        $base = $this->searchScopedQuery($request, $courierId);
        $statusCounts = $this->statusCounts(clone $base);
        $currentEmployerIds = $this->currentEmployerCompanyIds($courierId);

        $query = $this->applyStatusFilter(clone $base, $request->string('status')->toString())
            ->select('conversations.*')
            ->with(['logisticsCompany.owner', 'buyer', 'seller', 'participantRecords'])
            ->orderByRaw('conversations.last_message_at desc nulls last')
            ->orderByDesc('conversations.created_at');

        $perPage = min(
            max((int) ($request->integer('per_page') ?: self::CONVERSATIONS_PAGE_SIZE), 1),
            self::MAX_PER_PAGE,
        );
        $paginated = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => $paginated->getCollection()
                ->map(fn (Conversation $c) => $this->transformConversation(
                    $c,
                    $courierId,
                    $currentEmployerIds->contains($c->logistics_company_id),
                ))
                ->all(),
            'meta' => [
                'currentPage' => $paginated->currentPage(),
                'lastPage' => $paginated->lastPage(),
                'perPage' => $paginated->perPage(),
                'total' => $paginated->total(),
                'unread_total' => (int) $this->scopedQuery($request, $courierId)->sum('courier_unread_count'),
                'statusCounts' => $statusCounts,
            ],
        ]);
    }

    /**
     * PUT /api/driver/messages/conversations/{id}/status
     *
     * `archived`/un-archiving are per-user (Conversation::archiveFor()/
     * unarchiveFor()) — hides the thread from this rider's own inbox
     * without touching the employer's copy or blocking anyone from writing
     * into it. A genuine 'resolved' <-> 'open' transition is the shared
     * Conversation.status — mirrors Logistics\MessageController::setStatus().
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

        return response()->json(['data' => $this->transformConversation(
            $conversation,
            $userId,
            $this->currentEmployerCompanyIds($userId)->contains($conversation->logistics_company_id),
        )]);
    }

    /**
     * POST /api/driver/messages/attachments
     */
    public function uploadAttachment(UploadMessageAttachmentRequest $request): JsonResponse
    {
        $attachment = $this->messageAttachmentService->stage($request->user(), $request->file('file'));

        return response()->json(['data' => $attachment->toContractArray()], 201);
    }

    /**
     * POST /api/driver/messages/delivery-conversations
     *
     * The "Message" button next to the call icon on a delivery's Buyer/
     * Seller contact row (assigned_delivery_detail_screen.dart) — finds or
     * creates this rider's 'delivery' thread with that contact and returns
     * it *with its latest messages already attached*, in one round trip.
     * Opening a conversation normally costs the mobile app two follow-up
     * calls (show() for metadata + markRead, messages() for the message
     * list) — folding both into this response is what lets the app skip
     * straight to a populated thread instead of a second and third
     * request it would otherwise have to wait on right after this one.
     * It's added to "the courier's conversation list" implicitly: the
     * thread this creates already belongs to this rider
     * (courier_profile_id) and satisfies scopedQuery() below, so the very
     * next inbox load (or this response itself, opened directly) shows it
     * — no separate "add to list" step exists.
     */
    public function startDeliveryConversation(StartDeliveryConversationRequest $request): JsonResponse
    {
        $courier = $request->user();
        $conversation = $this->deliveryConversationService->findOrCreate(
            $courier,
            $request->validated('parcel_assignment_id'),
            $request->validated('contact'),
        );
        $conversation->load(['logisticsCompany.owner', 'buyer', 'seller', 'participantRecords']);
        $this->markConversationRead($conversation, $courier->id);

        ['messages' => $messages, 'hasMore' => $hasMore] = $this->latestMessages($conversation);

        return response()->json([
            'data' => $this->transformConversation($conversation, $courier->id, false),
            'messages' => $messages->map(fn (Message $m) => $this->transformMessage($m))->all(),
            'meta' => ['hasMore' => $hasMore],
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findConversation($request, $id);
        abort_unless($conversation, 404);

        $userId = $request->user()->id;

        // markConversationRead() below is several UPDATE statements across
        // 3 tables — real writes, but nothing this response needs to wait
        // on: the mobile app already treats opening a thread as marking it
        // read on its own side. Reflecting that in-memory (not persisted —
        // just what transformConversation() below reads) and running the
        // actual writes after the response has been sent takes them off
        // the blocking path entirely — mirrors the same fix already
        // applied to Buyer\MessageController::showConversation() and
        // Logistics\MessageController::show().
        $conversation->courier_unread_count = 0;

        $response = response()->json(['data' => $this->transformConversation(
            $conversation,
            $userId,
            $this->currentEmployerCompanyIds($userId)->contains($conversation->logistics_company_id),
        )]);

        app()->terminating(fn () => $this->markConversationRead($conversation, $userId));

        return $response;
    }

    /**
     * Cursor pagination by message id, mirroring Buyer/Seller/Logistics\
     * MessageController::messages():
     *   - no cursor    -> the latest `limit` messages, returned oldest->newest
     *   - before=<id>  -> the `limit` messages immediately older than <id>
     *   - after=<id>   -> messages newer than <id> (the poll path — pulls in
     *     only what's actually new instead of re-fetching the whole thread)
     *
     * Ownership is folded directly into each query below (whereHas) instead
     * of a separate findConversation() round trip first — that call's own
     * eager loads (logisticsCompany.owner/buyer/seller/participantRecords)
     * were never used here anyway, only $conversation->id was. hasMore is
     * read off the same result set (fetch limit+1, trim) instead of a
     * second exists() query. Mirrors the identical fix already applied to
     * Buyer/Seller/Logistics\MessageController::messages().
     */
    public function messages(Request $request, string $id): JsonResponse
    {
        $courierId = $request->user()->id;
        $limit = min(max((int) ($request->integer('limit') ?: self::MESSAGES_PAGE_SIZE), 1), 100);
        $afterId = $request->string('after')->toString();
        $ownedConversation = fn (Builder $q) => $q
            ->whereIn('type', self::TYPES)
            ->where('courier_profile_id', $courierId)
            ->whereHas('participantRecords', fn (Builder $p) => $p->where('user_id', $courierId)->whereNull('left_at'));

        if ($afterId !== '') {
            $afterCursor = Message::where('conversation_id', $id)
                ->whereHas('conversation', $ownedConversation)
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

        $beforeId = $request->string('before')->toString();

        $query = Message::where('conversation_id', $id)
            ->whereHas('conversation', $ownedConversation)
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

    public function send(SendMessageRequest $request, string $id): JsonResponse
    {
        $conversation = $this->findConversation($request, $id);
        abort_unless($conversation, 404);

        if (! $this->conversationPolicy->sendMessage($request->user(), $conversation)) {
            return response()->json(['message' => 'This conversation is not open for new messages.'], 422);
        }

        $body = trim((string) $request->validated('body', ''));
        $attachmentIds = $request->validated('attachment_ids', []);
        // 'driver' or 'courier' — whichever this profile actually is.
        $senderRole = $request->user()->role;
        // 'roster's other participant is always 'logistics'; 'delivery's is
        // whichever of buyer/seller this thread is with — see
        // counterpartyUnreadColumn()'s docblock for why this can't just be
        // "not this courier's role" the way applyStatusFilter() gets away with.
        $counterpartyRole = $this->counterpartyRole($conversation);
        $unreadColumn = $this->counterpartyUnreadColumn($conversation);

        $message = DB::transaction(function () use ($body, $attachmentIds, $conversation, $request, $senderRole, $counterpartyRole, $unreadColumn): Message {
            $staged = $this->messageAttachmentService->findOwnedUnlinked($request->user(), $attachmentIds);

            if ($staged->count() !== count($attachmentIds)) {
                throw ValidationException::withMessages([
                    'attachment_ids' => 'One or more attachments are unavailable.',
                ]);
            }

            $message = $conversation->messages()->create([
                'sender_id' => $request->user()->id,
                'sender_role' => $senderRole,
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
                'last_message_sender_role' => $senderRole,
                'courier_unread_count' => 0,
                $unreadColumn => $conversation->{$unreadColumn} + 1,
            ])->save();

            $conversation->messages()->where('sender_role', $counterpartyRole)->whereNull('read_at')->update(['read_at' => now()]);
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
     * DELETE /api/driver/messages/conversations/{id}
     *
     * Removes the conversation from this rider's own inbox only (see
     * Conversation::leaveFor()) — the employer's copy and the message
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
        $courierId = $request->user()->id;
        $this->ensureOwnRosterConversations($courierId);
        $count = (int) $this->scopedQuery($request, $courierId)->sum('courier_unread_count');

        return response()->json(['data' => ['count' => $count]]);
    }

    /**
     * Every logistics company that currently employs this rider (an
     * 'accepted' CourierApplication — see STATUS_ACCEPTED, the source of
     * truth for employment) gets a 'roster' conversation automatically, so
     * the rider's inbox lists their employer even before either side has
     * sent a message. Uses the exact same context_key formula as
     * Logistics\MessageController::ensureRosterConversations() (type +
     * courierId + sorted participant ids), so firstOrCreate() reuses
     * whichever side created the row first instead of ever duplicating it.
     *
     * Cached per rider for a few minutes so the frequent inbox poll
     * doesn't re-run this on every tick — a brand new employer simply
     * takes up to that long to appear automatically.
     */
    private function ensureOwnRosterConversations(string $courierId): void
    {
        $cacheKey = "courier_roster_ensured:{$courierId}";
        if (Cache::has($cacheKey)) {
            return;
        }

        $employerCompanyIds = $this->currentEmployerCompanyIds($courierId);

        if ($employerCompanyIds->isNotEmpty()) {
            $existingCompanyIds = Conversation::query()
                ->where('type', 'roster')
                ->where('courier_profile_id', $courierId)
                ->pluck('logistics_company_id');

            $companies = LogisticsCompany::query()
                ->whereIn('id', $employerCompanyIds->diff($existingCompanyIds))
                ->get(['id', 'owner_profile_id']);

            foreach ($companies as $company) {
                DB::transaction(function () use ($company, $courierId): void {
                    $conversation = Conversation::query()->firstOrCreate(
                        ['context_key' => Conversation::makeContextKey('roster', $courierId, [$company->owner_profile_id, $courierId])],
                        [
                            'type' => 'roster',
                            'created_by' => $courierId,
                            'logistics_company_id' => $company->id,
                            'courier_profile_id' => $courierId,
                            'status' => 'open',
                        ],
                    );

                    foreach ([$company->owner_profile_id, $courierId] as $participantId) {
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
     * @return Collection<int, string>
     */
    private function currentEmployerCompanyIds(string $courierId): Collection
    {
        // Cache::remember() round-trips the value through the cache
        // store's own (de)serialization — caching the Collection object
        // itself risks __PHP_Incomplete_Class on read (the same pitfall
        // companyFor() above already works around for Eloquent models).
        // A plain array of ids has no class metadata to lose, so it's safe
        // regardless of cache driver; wrap it back into a Collection here.
        $ids = Cache::remember(
            "courier_current_employers:{$courierId}",
            now()->addMinute(),
            fn () => CourierApplication::query()
                ->where('courier_profile_id', $courierId)
                ->where('status', CourierApplication::STATUS_ACCEPTED)
                ->pluck('logistics_company_id')
                ->all(),
        );

        return collect($ids);
    }

    private function scopedQuery(Request $request, string $courierId): Builder
    {
        return Conversation::query()
            ->whereIn('type', self::TYPES)
            ->where('courier_profile_id', $courierId)
            ->whereHas('participantRecords', fn (Builder $query) => $query
                ->where('user_id', $courierId)->whereNull('left_at'));
    }

    /**
     * scopedQuery() plus the optional `search` param, and a join (not
     * scopedQuery()'s whereHas() EXISTS subquery) onto this rider's own
     * participant row so its per-user `archived_at` (see
     * Conversation::archiveFor()) is available as a real column for
     * applyStatusFilter()'s 'archived' branch and statusCounts()'s
     * aggregate. Mirrors Logistics\MessageController::searchScopedQuery().
     */
    private function searchScopedQuery(Request $request, string $courierId): Builder
    {
        $query = Conversation::query()
            ->join('conversation_participants', function (JoinClause $join) use ($courierId) {
                $join->on('conversation_participants.conversation_id', '=', 'conversations.id')
                    ->where('conversation_participants.user_id', $courierId)
                    ->whereNull('conversation_participants.left_at');
            })
            ->whereIn('conversations.type', self::TYPES)
            ->where('conversations.courier_profile_id', $courierId);

        if ($search = $request->string('search')->toString()) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('conversations.last_message_preview', 'ilike', "%{$search}%")
                    ->orWhereHas('logisticsCompany', function (Builder $lq) use ($search) {
                        $lq->where('company_name', 'ilike', "%{$search}%");
                    })
                    ->orWhereHas('buyer', function (Builder $bq) use ($search) {
                        $bq->where(DB::raw("(first_name || ' ' || last_name)"), 'ilike', "%{$search}%");
                    })
                    ->orWhereHas('seller', function (Builder $sq) use ($search) {
                        $sq->where(DB::raw("(first_name || ' ' || last_name)"), 'ilike', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    private function applyStatusFilter(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            'unread' => $query->where('conversations.courier_unread_count', '>', 0),
            // "Needs a reply" for either thread type is simply "the last
            // message wasn't sent by this rider" — true whether the
            // counterparty sent as 'logistics' (roster) or 'buyer'/'seller'
            // (delivery), so this needs no per-type branching.
            'needs_response' => $query->where('conversations.status', 'open')->whereNotIn('conversations.last_message_sender_role', ['driver', 'courier']),
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
            SUM(CASE WHEN conversations.courier_unread_count > 0 THEN 1 ELSE 0 END) AS unread_count,
            SUM(CASE WHEN conversations.status = 'open' AND conversations.last_message_sender_role NOT IN ('driver','courier') THEN 1 ELSE 0 END) AS needs_response_count,
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

    private function findConversation(Request $request, string $id): ?Conversation
    {
        $courierId = $request->user()->id;

        return $this->scopedQuery($request, $courierId)
            ->with(['logisticsCompany.owner', 'buyer', 'seller', 'participantRecords'])
            ->whereKey($id)
            ->first();
    }

    /**
     * The same "no cursor" branch messages() uses — latest MESSAGES_PAGE_SIZE
     * messages, oldest→newest, plus whether older ones exist. Factored out
     * so startDeliveryConversation() can fold this straight into its own
     * response instead of the mobile app needing a follow-up messages()
     * call right after.
     *
     * @return array{messages: \Illuminate\Support\Collection<int, Message>, hasMore: bool}
     */
    private function latestMessages(Conversation $conversation): array
    {
        // Fetch one extra row to read hasMore off this same result set
        // instead of a second exists() round trip — same technique as
        // messages() above.
        $rows = Message::where('conversation_id', $conversation->id)
            ->with(['order.items'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MESSAGES_PAGE_SIZE + 1)
            ->get();
        $hasMore = $rows->count() > self::MESSAGES_PAGE_SIZE;
        $rows = $rows->take(self::MESSAGES_PAGE_SIZE)->reverse()->values();
        $this->hydrateProductContext($rows);

        return ['messages' => $rows, 'hasMore' => $hasMore];
    }

    /**
     * transformMessage() needs each message's own `product` (productContext)
     * and, via Order::messagePreview(), each order item's `product`
     * (orderContext's thumbnail) — mirrors
     * Buyer\MessageController::hydrateProductContext(): collects every
     * product id either path needs across the page and fetches them in one
     * query instead of lazy-loading per message/item.
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
        $counterpartyRole = $this->counterpartyRole($conversation);

        DB::transaction(function () use ($conversation, $readerId, $counterpartyRole): void {
            $conversation->messages()->where('sender_role', $counterpartyRole)->whereNull('read_at')->update(['read_at' => now()]);
            $conversation->update(['courier_unread_count' => 0]);
            $conversation->participantRecords()->where('user_id', $readerId)->update(['last_read_at' => now()]);
        });
    }

    /**
     * The role this rider's counterparty sends as — 'logistics' for a
     * 'roster' thread, otherwise whichever of buyer/seller the 'delivery'
     * thread is with (see ConversationPolicy::hasValidContext()'s
     * 'delivery' case: exactly one of buyer_id/seller_id is ever set).
     */
    private function counterpartyRole(Conversation $conversation): string
    {
        if ($conversation->type === 'roster') {
            return 'logistics';
        }

        return $conversation->buyer_id !== null ? 'buyer' : 'seller';
    }

    /**
     * Which denormalised *_unread_count column belongs to this
     * conversation's counterparty — can't be derived from counterpartyRole()
     * alone since the column names ('logistics_unread_count' etc.) don't
     * literally match every role string ('buyer'/'seller' do, 'logistics'
     * does too here, but keeping this its own lookup avoids the two ever
     * silently drifting apart if that changes).
     */
    private function counterpartyUnreadColumn(Conversation $conversation): string
    {
        return match (true) {
            $conversation->type === 'roster' => 'logistics_unread_count',
            $conversation->buyer_id !== null => 'buyer_unread_count',
            default => 'seller_unread_count',
        };
    }

    /**
     * The "employer" JSON key here is a holdover name from when 'roster'
     * was the only conversation type this controller ever produced (the
     * mobile client — chat_models.dart's ChatConversation — still reads it
     * under that key) — it now really means "this conversation's
     * counterparty", which for a 'delivery' thread is the order's buyer or
     * seller rather than an employer. Not renamed: it's an internal field
     * name, never shown as literal text in the app's UI, and renaming it
     * would only cost a matching Flutter-side rename for no user-facing
     * benefit.
     */
    private function transformConversation(Conversation $conversation, string $viewerId, bool $isCurrentEmployer): array
    {
        $isDelivery = $conversation->type === 'delivery';
        // Exactly one of buyer/seller is ever set on a 'delivery' thread —
        // see ConversationPolicy::hasValidContext()'s 'delivery' case.
        $counterparty = $isDelivery
            ? ($conversation->buyer_id !== null ? $conversation->buyer : $conversation->seller)
            : $conversation->logisticsCompany?->owner;
        $counterpartyName = $isDelivery
            ? ($counterparty?->full_name ?: ucfirst($this->counterpartyRole($conversation)))
            : ($conversation->logisticsCompany?->company_name ?: 'Logistics Company');

        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'status' => $conversation->status,
            // Per-participant, not the shared `status` column — see
            // Conversation::archiveFor()/isArchivedFor().
            'archived' => $conversation->isArchivedFor($viewerId),
            // Drives the mobile inbox's "Priority" section — true only
            // while an 'accepted' CourierApplication ties this rider to
            // this company right now; a past employer's thread (relationship
            // ended) still shows, just in the normal list below. Never true
            // for a 'delivery' thread — those aren't employer relationships.
            'isCurrentEmployer' => $isCurrentEmployer,
            'employer' => [
                'id' => $isDelivery ? $counterparty?->id : $conversation->logistics_company_id,
                'name' => $counterpartyName,
                // Lets the mobile inbox badge a 'delivery' row as "Buyer"/
                // "Seller" instead of showing every thread as if it were
                // the employer — see counterpartyRole()'s docblock.
                'role' => $this->counterpartyRole($conversation),
                // Activity-based presence (Profile::isOnline()) — refreshed
                // on every conversations poll, no dedicated request needed.
                'online' => (bool) $counterparty?->isOnline(),
                // Same accessor/field the Logistics/Seller inboxes already
                // expose for their own conversation partners — no extra
                // query, both relations are eager-loaded above.
                'avatarUrl' => $counterparty?->avatar_url,
            ],
            'last_message' => $conversation->last_message_preview,
            'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
            'last_message_sender_role' => $conversation->last_message_sender_role,
            'unread' => (int) $conversation->courier_unread_count,
        ];
    }

    private function transformMessage(Message $message): array
    {
        return [
            'id' => $message->id,
            'from' => $message->sender_role,
            'type' => $message->message_type,
            'text' => $message->body,
            'attachments' => collect($message->attachments ?? [])->map(fn (array $attachment) => [
                ...$attachment,
                'url' => MessageAttachment::contractUrlFor($attachment),
            ])->all(),
            // A buyer's "Inquire about a certain parcel" card
            // (message_type 'inquiry', no body) — same shape
            // Buyer\MessageController::transformMessage() sends, so the
            // driver app can render the same card instead of an empty
            // bubble. See hydrateProductContext() for how order/product are
            // populated without per-message queries.
            'orderContext' => $message->order?->messagePreview(),
            'productContext' => $message->product ? [
                'id' => $message->product->id,
                'name' => $message->product->name,
                'price' => (float) $message->product->price,
                'image' => ($message->product->images ?? [])[0]['url'] ?? null,
                'quantity' => ($message->order_id && $message->product_id)
                    ? $message->order?->items?->firstWhere('product_id', $message->product_id)?->quantity
                    : null,
                'trackingNumber' => $message->order?->tracking_number,
            ] : null,
            'at' => optional($message->created_at)->toIso8601String(),
            'read_at' => optional($message->read_at)->toIso8601String(),
        ];
    }
}
