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
use App\Models\ParcelAssignment;
use App\Models\Profile;
use App\Policies\ConversationPolicy;
use App\Services\MessageAttachmentService;
use App\Services\ShipmentConversationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    private const MESSAGES_PAGE_SIZE = 30;

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
                $conversation->fresh(['buyer', 'order', 'product', 'logisticsCompany.owner', 'parcelAssignment.rider']),
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
     * GET /api/seller/messages/conversations
     *
     * Query: search?, status? (all|unread|needs_response|resolved|archived),
     * page?, per_page?.
     *
     * `statusCounts` is computed off the same search-filtered base (minus
     * the status filter) so the tab counts and the list never disagree —
     * the pattern SellerFeedbackController::index() uses.
     */
    public function conversations(Request $request): JsonResponse
    {
        $seller = $request->user();

        $base = $this->searchScopedQuery($seller->id, $request);

        $statusCounts = [
            'all' => (clone $base)->count(),
            'unread' => (clone $base)->where('seller_unread_count', '>', 0)->count(),
            'needsResponse' => (clone $base)->where('status', 'open')
                ->where('last_message_sender_role', '!=', 'seller')->count(),
            'resolved' => (clone $base)->where('status', 'resolved')->count(),
            'archived' => (clone $base)->where('status', 'archived')->count(),
        ];

        $query = $this->applyStatusFilter(clone $base, $request->string('status')->toString())
            ->with(['buyer', 'order', 'product', 'logisticsCompany.owner', 'parcelAssignment.rider'])
            ->orderByRaw('last_message_at desc nulls last')
            ->orderByDesc('created_at');

        $perPage = min(
            (int) ($request->integer('per_page') ?: self::DEFAULT_PER_PAGE),
            self::MAX_PER_PAGE,
        );
        $paginated = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => $paginated->getCollection()
                ->map(fn (Conversation $c) => $this->transformConversation($c))
                ->all(),
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
     * GET /api/seller/messages/conversations/{id}
     */
    public function showConversation(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findForSeller($request, $id, ['buyer', 'order', 'product', 'logisticsCompany.owner', 'parcelAssignment.rider']);

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
        $conversation = $this->findForSeller($request, $id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $limit = min(max((int) ($request->integer('limit') ?: self::MESSAGES_PAGE_SIZE), 1), 100);

        $beforeCursor = $this->resolveCursor($conversation->id, $request->string('before')->toString());
        $afterCursor = $this->resolveCursor($conversation->id, $request->string('after')->toString());

        if ($afterCursor) {
            $rows = Message::where('conversation_id', $conversation->id)
                ->where(fn (Builder $q) => $this->tupleGreaterThan($q, $afterCursor))
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
            $query->where(fn (Builder $q) => $this->tupleLessThan($q, $beforeCursor));
        }

        // Pull newest-first so "latest N" / "N older than cursor" both work,
        // then flip to chronological for the client.
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->reverse()->values();

        $oldest = $rows->first();
        $hasMore = $oldest
            ? Message::where('conversation_id', $conversation->id)
                ->where(fn (Builder $q) => $this->tupleLessThan($q, $oldest))
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

        $message = DB::transaction(function () use ($conversation, $seller, $body, $attachmentIds) {
            return $this->storeMessage($conversation, $seller, $body, $attachmentIds);
        });

        return response()->json(['data' => $this->transformMessage($message)], 201);
    }

    /** @param list<string> $attachmentIds */
    private function storeMessage(Conversation $conversation, Profile $seller, string $body, array $attachmentIds): Message
    {
        $staged = $this->messageAttachmentService->findOwnedUnlinked($seller, $attachmentIds);

        if ($staged->count() !== count($attachmentIds)) {
            throw ValidationException::withMessages([
                'attachment_ids' => 'One or more attachments are unavailable.',
            ]);
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $seller->id,
            'sender_role' => 'seller',
            'message_type' => $body === '' ? 'attachment' : 'text',
            'body' => $body,
            'attachments' => $staged->map->toStoredArray()->all(),
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
            'last_message_preview' => $body !== ''
                ? Str::limit($body, 140)
                : ($staged->count() === 1 ? 'Sent an attachment' : 'Sent attachments'),
            'last_message_sender_role' => 'seller',
            'seller_unread_count' => 0,
        ])->save();

        $conversation->increment(
            $conversation->type === 'shipment' ? 'logistics_unread_count' : 'buyer_unread_count'
        );

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
     * PUT /api/seller/messages/conversations/{id}/status
     */
    public function setStatus(UpdateConversationStatusRequest $request, string $id): JsonResponse
    {
        $conversation = $this->findForSeller($request, $id, ['buyer', 'order', 'product', 'logisticsCompany.owner', 'parcelAssignment.rider']);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $status = $request->validated('status');

        if (! $conversation->canTransitionTo($status)) {
            return response()->json([
                'message' => "A conversation cannot move from {$conversation->status} to {$status}.",
            ], 422);
        }

        $conversation->forceFill(['status' => $status])->save();

        return response()->json([
            'data' => $this->transformConversationDetail($conversation->fresh(['buyer', 'order', 'product', 'logisticsCompany.owner', 'parcelAssignment.rider'])),
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

        if (! $conversation || $conversation->type === 'shipment') {
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

    private function searchScopedQuery(string $sellerId, Request $request): Builder
    {
        $query = Conversation::query()
            ->where('seller_id', $sellerId)
            ->where('type', '!=', 'support')
            ->whereHas('participantRecords', fn (Builder $participantQuery) => $participantQuery
                ->where('user_id', $sellerId)
                ->whereNull('left_at'));

        if ($search = $request->string('search')->toString()) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('subject', 'ilike', "%{$search}%")
                    ->orWhere('last_message_preview', 'ilike', "%{$search}%")
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

    private function applyStatusFilter(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            'unread' => $query->where('seller_unread_count', '>', 0),
            'needs_response' => $query->where('status', 'open')->where('last_message_sender_role', '!=', 'seller'),
            'resolved' => $query->where('status', 'resolved'),
            'archived' => $query->where('status', 'archived'),
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

    private function resolveCursor(string $conversationId, string $messageId): ?Message
    {
        if ($messageId === '') {
            return null;
        }

        return Message::where('conversation_id', $conversationId)->whereKey($messageId)->first();
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

    private function transformConversation(Conversation $c): array
    {
        $counterpartyName = $c->type === 'shipment'
            ? ($c->logisticsCompany?->company_name ?: 'Logistics')
            : ($c->buyer?->full_name ?: 'Buyer');

        return [
            'id' => $c->id,
            'type' => $c->type,
            'status' => $c->status,
            'buyer' => [
                'id' => $c->type === 'shipment' ? $c->logisticsCompany?->owner_profile_id : $c->buyer_id,
                'name' => $counterpartyName,
                'initials' => $this->initialsFor($counterpartyName),
                'role' => $c->type === 'shipment' ? 'logistics' : 'buyer',
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
            'attachments' => collect($m->attachments ?? [])->map(fn ($a) => [
                'id' => $a['id'] ?? null,
                'name' => $a['name'] ?? 'attachment',
                'url' => MessageAttachment::contractUrlFor($a),
                'mime' => $a['mime'] ?? null,
                'size' => $a['size'] ?? null,
            ])->all(),
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
