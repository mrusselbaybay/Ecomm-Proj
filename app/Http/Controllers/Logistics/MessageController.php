<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\SendMessageRequest;
use App\Http\Requests\Logistics\UpdateConversationStatusRequest;
use App\Http\Requests\Messaging\UploadMessageAttachmentRequest;
use App\Models\Conversation;
use App\Models\LogisticsCompany;
use App\Models\Message;
use App\Models\MessageAttachment;
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
        $base = $this->searchScopedQuery($request, $company);

        $statusCounts = $this->statusCounts(clone $base);

        $query = $this->applyStatusFilter(clone $base, $request->string('status')->toString())
            ->select('conversations.*')
            ->with(['seller.sellerDetail', 'order', 'parcelAssignment.rider', 'participantRecords'])
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
                'unread_total' => (int) $this->scopedQuery($request, $company)->sum('logistics_unread_count'),
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

        $this->markConversationRead($conversation, $request->user()->id);

        return response()->json(['data' => $this->transformConversation($conversation, $request->user()->id)]);
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
        $conversation = $this->findConversation($request, $id);
        abort_unless($conversation, 404);

        $limit = min(max((int) ($request->integer('limit') ?: self::MESSAGES_PAGE_SIZE), 1), 100);

        $beforeCursor = $this->resolveCursor($conversation->id, $request->string('before')->toString());
        $afterCursor = $this->resolveCursor($conversation->id, $request->string('after')->toString());

        if ($afterCursor) {
            $rows = Message::where('conversation_id', $conversation->id)
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

        $query = Message::where('conversation_id', $conversation->id);

        if ($beforeCursor) {
            $query->where(fn ($q) => $this->tupleLessThan($q, $beforeCursor));
        }

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
        $count = (int) $this->scopedQuery($request, $company)->sum('logistics_unread_count');

        return response()->json(['data' => ['count' => $count]]);
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
            ->where('owner_profile_id', $profileId)
            ->where('status', 'approved')
            ->where('account_status', 'active')
            ->firstOrFail()
            ->getAttributes());

        return (new LogisticsCompany)->newFromBuilder($attributes);
    }

    private function scopedQuery(Request $request, LogisticsCompany $company): Builder
    {
        return Conversation::query()
            ->where('type', 'shipment')
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
            ->where('conversations.type', 'shipment')
            ->where('conversations.logistics_company_id', $company->id);

        if ($search = $request->string('search')->toString()) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('conversations.last_message_preview', 'ilike', "%{$search}%")
                    ->orWhereHas('seller', function (Builder $sq) use ($search) {
                        $sq->where(DB::raw("(first_name || ' ' || last_name)"), 'ilike', "%{$search}%");
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
        $company = $this->companyFor($request);

        return $this->scopedQuery($request, $company)
            ->with(['seller.sellerDetail', 'order', 'parcelAssignment.rider', 'logisticsCompany', 'participantRecords'])
            ->whereKey($id)
            ->first();
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
