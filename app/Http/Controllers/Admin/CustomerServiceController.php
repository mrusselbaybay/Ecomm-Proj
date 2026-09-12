<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignSupportTicketRequest;
use App\Http\Requests\Admin\ReplySupportTicketRequest;
use App\Http\Requests\Admin\ResolveSupportTicketRequest;
use App\Http\Requests\Admin\StoreSupportTicketInternalNoteRequest;
use App\Http\Requests\Admin\UpdateSupportTicketStatusRequest;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Profile;
use App\Models\SupportTicket;
use App\Notifications\SupportTicketUpdated;
use App\Policies\SupportTicketPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerServiceController extends Controller
{
    public function __construct(private SupportTicketPolicy $supportTicketPolicy) {}

    public function index(Request $request): JsonResponse
    {
        $admin = $request->user();
        $query = SupportTicket::query()
            ->with(['creator', 'order', 'conversation'])
            ->where(fn (Builder $builder) => $builder
                ->whereNull('assigned_admin_id')
                ->orWhere('assigned_admin_id', $admin->id));

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($category = $request->string('category')->toString()) {
            $query->where('category', $category);
        }

        if ($search = trim($request->string('search')->toString())) {
            $query->where(fn (Builder $builder) => $builder
                ->where('ticket_number', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%"));
        }

        $tickets = $query->orderByRaw("case when priority = 'urgent' then 1 when priority = 'high' then 2 when priority = 'normal' then 3 else 4 end")
            ->orderBy('created_at')
            ->paginate(min(max($request->integer('per_page', 20), 1), 50));

        return response()->json([
            'data' => $tickets->getCollection()
                ->map(fn (SupportTicket $ticket): array => $this->transformAdminTicket($ticket))
                ->all(),
            'meta' => [
                'currentPage' => $tickets->currentPage(),
                'lastPage' => $tickets->lastPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->ensureAdminCanView($request->user(), $ticket);

        return response()->json([
            'data' => $this->transformAdminTicket(
                $ticket->load(['creator', 'order', 'assignedAdmin', 'conversation.messages', 'internalNotes.admin']),
                true,
            ),
        ]);
    }

    public function assignment(AssignSupportTicketRequest $request, SupportTicket $ticket): JsonResponse
    {
        $admin = $request->user();

        DB::transaction(function () use ($admin, $ticket): void {
            $ticket->forceFill([
                'assigned_admin_id' => $admin->id,
                'status' => in_array($ticket->status, ['submitted', 'reopened'], true) ? 'open' : $ticket->status,
            ])->save();

            $conversation = $ticket->conversation()->firstOrFail();
            ConversationParticipant::query()->firstOrCreate(
                ['conversation_id' => $conversation->id, 'user_id' => $admin->id],
                ['joined_at' => now()],
            );
        });

        $ticket->creator->notify(new SupportTicketUpdated(
            'support_ticket_opened',
            $ticket->id,
            $ticket->ticket_number,
            $ticket->fresh()->status,
            'Your ticket is now being reviewed.',
        ));

        return response()->json([
            'data' => $this->transformAdminTicket($ticket->fresh(['creator', 'order', 'assignedAdmin', 'conversation'])),
        ]);
    }

    public function status(UpdateSupportTicketStatusRequest $request, SupportTicket $ticket): JsonResponse
    {
        $status = $request->validated('status');
        $ticket->forceFill([
            'status' => $status,
            'escalated_at' => $status === 'escalated' ? now() : $ticket->escalated_at,
        ])->save();

        if (in_array($status, ['waiting_for_customer', 'escalated'], true)) {
            $event = $status === 'waiting_for_customer' ? 'support_action_required' : 'support_ticket_escalated';
            $ticket->creator->notify(new SupportTicketUpdated(
                $event,
                $ticket->id,
                $ticket->ticket_number,
                $status,
                $status === 'waiting_for_customer'
                    ? 'Platform Customer Service needs more information.'
                    : 'Your ticket has been escalated for review.',
            ));
        }

        return response()->json([
            'data' => $this->transformAdminTicket($ticket->fresh(['creator', 'order', 'assignedAdmin', 'conversation'])),
        ]);
    }

    public function reply(ReplySupportTicketRequest $request, SupportTicket $ticket): JsonResponse
    {
        $body = trim($request->validated('body'));
        $message = DB::transaction(function () use ($request, $ticket, $body): Message {
            $conversation = $ticket->conversation()->firstOrFail();
            $message = $conversation->messages()->create([
                'sender_id' => $request->user()->id,
                'sender_role' => 'admin',
                'message_type' => 'text',
                'body' => $body,
            ]);

            $conversation->forceFill([
                'status' => 'active',
                'last_message_at' => $message->created_at,
                'last_message_preview' => Str::limit($body, 160),
                'last_message_sender_role' => 'admin',
            ])->save();

            $ticket->forceFill(['status' => 'open'])->save();

            return $message;
        });

        $ticket->creator->notify(new SupportTicketUpdated(
            'support_reply_received',
            $ticket->id,
            $ticket->ticket_number,
            'open',
            $body,
        ));

        return response()->json(['data' => $this->transformAdminMessage($message)], 201);
    }

    public function internalNote(StoreSupportTicketInternalNoteRequest $request, SupportTicket $ticket): JsonResponse
    {
        $note = $ticket->internalNotes()->create([
            'admin_id' => $request->user()->id,
            'body' => trim($request->validated('body')),
        ]);

        return response()->json([
            'data' => [
                'id' => $note->id,
                'body' => $note->body,
                'adminId' => $note->admin_id,
                'createdAt' => optional($note->created_at)->toIso8601String(),
            ],
        ], 201);
    }

    public function resolve(ResolveSupportTicketRequest $request, SupportTicket $ticket): JsonResponse
    {
        $summary = trim($request->validated('resolution_summary'));

        DB::transaction(function () use ($request, $ticket, $summary): void {
            $conversation = $ticket->conversation()->firstOrFail();
            $message = $conversation->messages()->create([
                'sender_id' => $request->user()->id,
                'sender_role' => 'admin',
                'message_type' => 'system',
                'body' => $summary,
            ]);

            $conversation->forceFill([
                'status' => 'resolved',
                'last_message_at' => $message->created_at,
                'last_message_preview' => Str::limit($summary, 160),
                'last_message_sender_role' => 'admin',
            ])->save();

            $ticket->forceFill([
                'status' => 'resolved',
                'resolution_summary' => $summary,
                'resolved_at' => now(),
            ])->save();
        });

        $ticket->creator->notify(new SupportTicketUpdated(
            'support_ticket_resolved',
            $ticket->id,
            $ticket->ticket_number,
            'resolved',
            $summary,
        ));

        return response()->json([
            'data' => $this->transformAdminTicket(
                $ticket->fresh(['creator', 'order', 'assignedAdmin', 'conversation.messages', 'internalNotes.admin']),
                true,
            ),
        ]);
    }

    private function ensureAdminCanView(Profile $admin, SupportTicket $ticket): void
    {
        abort_unless($this->supportTicketPolicy->viewAsAdmin($admin, $ticket), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformAdminTicket(SupportTicket $ticket, bool $withDetails = false): array
    {
        $data = [
            'id' => $ticket->id,
            'ticketNumber' => $ticket->ticket_number,
            'category' => $ticket->category,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'creator' => [
                'id' => $ticket->creator?->id,
                'name' => $ticket->creator?->full_name ?: 'User',
                'role' => $ticket->creator?->role,
            ],
            'assignedAdminId' => $ticket->assigned_admin_id,
            'order' => $ticket->order ? [
                'id' => $ticket->order->id,
                'orderNumber' => $ticket->order->order_number,
                'status' => $ticket->order->status,
            ] : null,
            'resolutionSummary' => $ticket->resolution_summary,
            'escalatedAt' => optional($ticket->escalated_at)->toIso8601String(),
            'resolvedAt' => optional($ticket->resolved_at)->toIso8601String(),
            'createdAt' => optional($ticket->created_at)->toIso8601String(),
            'updatedAt' => optional($ticket->updated_at)->toIso8601String(),
        ];

        if ($withDetails) {
            $data['messages'] = $ticket->conversation?->messages
                ->map(fn (Message $message): array => $this->transformAdminMessage($message))
                ->values()
                ->all() ?? [];
            $data['internalNotes'] = $ticket->internalNotes
                ->map(fn ($note): array => [
                    'id' => $note->id,
                    'body' => $note->body,
                    'adminId' => $note->admin_id,
                    'adminName' => $note->admin?->full_name,
                    'createdAt' => optional($note->created_at)->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformAdminMessage(Message $message): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'messageType' => $message->message_type,
            'senderRole' => $message->sender_role,
            'displayName' => in_array($message->sender_role, ['admin', 'system'], true)
                ? 'Platform Customer Service'
                : 'Customer',
            'createdAt' => optional($message->created_at)->toIso8601String(),
        ];
    }
}
