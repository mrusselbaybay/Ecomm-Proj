<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\ReportBuyerRequest;
use App\Http\Requests\Seller\SendSellerMessageRequest;
use App\Http\Requests\Seller\UpdateConversationStatusRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    private const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

    private const ALLOWED_ATTACHMENT_MIMES = ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'];

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
                ->where('last_message_sender_role', 'buyer')->count(),
            'resolved' => (clone $base)->where('status', 'resolved')->count(),
            'archived' => (clone $base)->where('status', 'archived')->count(),
        ];

        $query = $this->applyStatusFilter(clone $base, $request->string('status')->toString())
            ->with(['buyer', 'order', 'product'])
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
        $conversation = $this->findForSeller($request, $id, ['buyer', 'order', 'product']);

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

        if ($conversation->status === 'archived') {
            return response()->json(['message' => 'This conversation is archived.'], 422);
        }

        $body = trim($request->validated('body'));
        $attachmentIds = $request->validated('attachment_ids') ?? [];

        $message = DB::transaction(function () use ($conversation, $seller, $body, $attachmentIds) {
            // Only this seller's own still-unlinked uploads count.
            $staged = MessageAttachment::whereIn('id', $attachmentIds)
                ->where('seller_id', $seller->id)
                ->whereNull('message_id')
                ->get();

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $seller->id,
                'sender_role' => 'seller',
                'body' => $body,
                'attachments' => $staged->map->toContractArray()->all(),
            ]);

            if ($staged->isNotEmpty()) {
                MessageAttachment::whereKey($staged->pluck('id'))->update(['message_id' => $message->id]);
            }

            // The seller replying means they've seen everything in the
            // thread — clear their unread and stamp buyer messages read.
            Message::where('conversation_id', $conversation->id)
                ->where('sender_role', 'buyer')
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            $conversation->forceFill([
                'last_message_at' => $message->created_at,
                'last_message_preview' => Str::limit($body, 140),
                'last_message_sender_role' => 'seller',
                'seller_unread_count' => 0,
            ])->save();

            $conversation->increment('buyer_unread_count');

            return $message;
        });

        return response()->json(['data' => $this->transformMessage($message)], 201);
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
                ->where('sender_role', 'buyer')
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            $conversation->forceFill(['seller_unread_count' => 0])->save();
        });

        return response()->json(['data' => ['unreadCount' => 0]]);
    }

    /**
     * PUT /api/seller/messages/conversations/{id}/status
     */
    public function setStatus(UpdateConversationStatusRequest $request, string $id): JsonResponse
    {
        $conversation = $this->findForSeller($request, $id, ['buyer', 'order', 'product']);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $conversation->forceFill(['status' => $request->validated('status')])->save();

        return response()->json([
            'data' => $this->transformConversationDetail($conversation->fresh(['buyer', 'order', 'product'])),
        ]);
    }

    /**
     * GET /api/seller/messages/unread-count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = (int) Conversation::where('seller_id', $request->user()->id)
            ->sum('seller_unread_count');

        return response()->json(['data' => ['count' => $count]]);
    }

    /**
     * POST /api/seller/messages/attachments  (multipart, field "file")
     *
     * Stores the file as a base64 data: URL (the way products.images /
     * reviews.images hold binary in this schema) and returns its id for
     * the follow-up send. Swapping to a Storage bucket later only changes
     * what gets written to message_attachments.url.
     */
    public function uploadAttachment(Request $request): JsonResponse
    {
        $seller = $request->user();

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.(self::MAX_ATTACHMENT_BYTES / 1024),
                'mimetypes:'.implode(',', self::ALLOWED_ATTACHMENT_MIMES),
            ],
        ]);

        $file = $validated['file'];
        $dataUrl = 'data:'.$file->getMimeType().';base64,'.base64_encode(file_get_contents($file->getRealPath()));

        $attachment = MessageAttachment::create([
            'seller_id' => $seller->id,
            'message_id' => null,
            'name' => $file->getClientOriginalName() ?: 'attachment',
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'url' => $dataUrl,
        ]);

        return response()->json(['data' => $attachment->toContractArray()], 201);
    }

    /**
     * POST /api/seller/messages/conversations/{id}/report
     *
     * Deliberately log-only. A `complaints` table exists in the schema but
     * has no model/owner on this branch — that's the admin moderation
     * feature's territory (see useMessaging.js's contract note). Writing
     * into it unilaterally from here would be a cross-role decision this
     * endpoint shouldn't make, so it acknowledges the report (keeping the
     * UI's flow intact) and records it for a human to pick up.
     */
    public function report(ReportBuyerRequest $request, string $id): JsonResponse
    {
        $seller = $request->user();
        $conversation = $this->findForSeller($request, $id, ['buyer']);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        Log::warning('Seller reported a buyer', [
            'conversation_id' => $conversation->id,
            'seller_id' => $seller->id,
            'buyer_id' => $conversation->buyer_id,
            'order_id' => $conversation->order_id,
            'reason' => $request->validated('reason'),
        ]);

        return response()->json(['data' => ['reported' => true]]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function searchScopedQuery(string $sellerId, Request $request): Builder
    {
        $query = Conversation::query()->where('seller_id', $sellerId);

        if ($search = $request->string('search')->toString()) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('subject', 'ilike', "%{$search}%")
                    ->orWhere('last_message_preview', 'ilike', "%{$search}%")
                    ->orWhereHas('buyer', function (Builder $bq) use ($search) {
                        $bq->where(DB::raw("(first_name || ' ' || last_name)"), 'ilike', "%{$search}%");
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
            'unread' => $query->where('seller_unread_count', '>', 0),
            'needs_response' => $query->where('status', 'open')->where('last_message_sender_role', 'buyer'),
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
        return [
            'id' => $c->id,
            'status' => $c->status,
            'buyer' => [
                'id' => $c->buyer_id,
                'name' => $c->buyer?->full_name ?: 'Buyer',
                'initials' => $this->initialsFor($c->buyer?->full_name),
            ],
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
            'needsResponse' => $c->status === 'open' && $c->last_message_sender_role === 'buyer',
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
                'url' => $a['url'] ?? null,
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
