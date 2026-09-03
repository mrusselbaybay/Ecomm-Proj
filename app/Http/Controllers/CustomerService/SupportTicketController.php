<?php

namespace App\Http\Controllers\CustomerService;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerService\ReplySupportTicketRequest;
use App\Http\Requests\CustomerService\StoreSupportTicketRequest;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Order;
use App\Models\Profile;
use App\Models\SupportTicket;
use App\Notifications\SupportTicketUpdated;
use App\Policies\SupportTicketPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupportTicketController extends Controller
{
    private const FAQS = [
        ['id' => 'order-status', 'question' => 'Where can I check my order status?', 'answer' => 'Open your Orders page and select the seller-specific order you want to track.', 'category' => 'order'],
        ['id' => 'refund-time', 'question' => 'How long does a refund take?', 'answer' => 'Approved refunds depend on the payment provider. Track the official decision in your Customer Service ticket.', 'category' => 'refund'],
        ['id' => 'failed-delivery', 'question' => 'What should I do after a failed delivery?', 'answer' => 'Check the latest tracking update, then create a Delivery ticket if the issue remains unresolved.', 'category' => 'delivery'],
        ['id' => 'account-access', 'question' => 'How do I report an account problem?', 'answer' => 'Create an Account ticket and describe the sign-in or profile issue without sharing your password.', 'category' => 'account'],
    ];

    private const CATEGORY_LABELS = [
        'account' => 'Account',
        'order' => 'Order',
        'payment' => 'Payment',
        'refund' => 'Refund',
        'delivery' => 'Delivery',
        'product_seller' => 'Product or Seller',
        'compliance' => 'Compliance',
        'safety' => 'Safety',
        'other' => 'Other',
    ];

    public function __construct(private SupportTicketPolicy $supportTicketPolicy) {}

    public function faqs(Request $request): JsonResponse
    {
        $this->ensureCanUseCustomerService($request->user());

        return response()->json(['data' => self::FAQS]);
    }

    public function categories(Request $request): JsonResponse
    {
        $this->ensureCanUseCustomerService($request->user());

        return response()->json([
            'data' => collect(self::CATEGORY_LABELS)
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureCanUseCustomerService($request->user());

        $tickets = SupportTicket::query()
            ->with(['order', 'conversation'])
            ->where('created_by', $request->user()->id)
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'data' => $tickets->map(fn (SupportTicket $ticket): array => $this->transformTicket($ticket)),
        ]);
    }

    public function store(StoreSupportTicketRequest $request): JsonResponse
    {
        $creator = $request->user();
        $data = $request->validated();
        $order = $this->resolveRelatedOrder($creator, $data['order_id'] ?? null);

        $ticket = DB::transaction(function () use ($creator, $data, $order): SupportTicket {
            $ticket = SupportTicket::create([
                'ticket_number' => $this->nextTicketNumber(),
                'created_by' => $creator->id,
                'category' => $data['category'],
                'subject' => trim($data['subject']),
                'description' => trim($data['description']),
                'priority' => 'normal',
                'status' => 'submitted',
                'order_id' => $order?->id,
            ]);

            $conversation = Conversation::create([
                'type' => 'support',
                'created_by' => $creator->id,
                'context_key' => Conversation::makeContextKey('support', $ticket->id, [$creator->id]),
                'buyer_id' => $creator->role === 'buyer' ? $creator->id : null,
                'seller_id' => $creator->role === 'seller' ? $creator->id : null,
                'order_id' => $order?->id,
                'support_ticket_id' => $ticket->id,
                'subject' => $ticket->subject,
                'status' => 'active',
            ]);

            ConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $creator->id,
                'joined_at' => now(),
            ]);

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $creator->id,
                'sender_role' => $creator->role,
                'message_type' => 'text',
                'body' => trim($data['description']),
            ]);

            $conversation->forceFill([
                'last_message_at' => $message->created_at,
                'last_message_preview' => Str::limit($message->body, 160),
                'last_message_sender_role' => $creator->role,
            ])->save();

            return $ticket;
        });

        $creator->notify(new SupportTicketUpdated(
            'support_ticket_created',
            $ticket->id,
            $ticket->ticket_number,
            $ticket->status,
            $ticket->subject,
        ));

        return response()->json([
            'data' => $this->transformTicket($ticket->fresh(['order', 'conversation.messages']), true),
        ], 201);
    }

    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->ensureOwnTicket($request->user(), $ticket);
        $this->markRead($ticket, $request->user()->id);

        return response()->json([
            'data' => $this->transformTicket($ticket->load(['order', 'conversation.messages']), true),
        ]);
    }

    public function messages(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->ensureOwnTicket($request->user(), $ticket);
        $this->markRead($ticket, $request->user()->id);

        return response()->json([
            'data' => $ticket->conversation?->messages()
                ->orderBy('created_at')
                ->get()
                ->map(fn (Message $message): array => $this->transformCustomerMessage($message))
                ->values() ?? [],
        ]);
    }

    public function reply(ReplySupportTicketRequest $request, SupportTicket $ticket): JsonResponse
    {
        if (! $this->supportTicketPolicy->replyAsCreator($request->user(), $ticket)) {
            return response()->json(['message' => 'This ticket is not accepting replies.'], 422);
        }

        $body = trim($request->validated('body'));

        $message = DB::transaction(function () use ($request, $ticket, $body): Message {
            $conversation = $ticket->conversation()->firstOrFail();
            $message = $conversation->messages()->create([
                'sender_id' => $request->user()->id,
                'sender_role' => $request->user()->role,
                'message_type' => 'text',
                'body' => $body,
            ]);

            $conversation->forceFill([
                'status' => 'active',
                'last_message_at' => $message->created_at,
                'last_message_preview' => Str::limit($body, 160),
                'last_message_sender_role' => $request->user()->role,
            ])->save();

            if ($ticket->status === 'waiting_for_customer') {
                $ticket->forceFill(['status' => 'open'])->save();
            } else {
                $ticket->touch();
            }

            return $message;
        });

        if ($ticket->assignedAdmin) {
            $ticket->assignedAdmin->notify(new SupportTicketUpdated(
                'support_reply_received',
                $ticket->id,
                $ticket->ticket_number,
                $ticket->fresh()->status,
                $body,
            ));
        }

        return response()->json(['data' => $this->transformCustomerMessage($message)], 201);
    }

    public function close(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->ensureOwnTicket($request->user(), $ticket);

        if (! $this->supportTicketPolicy->closeAsCreator($request->user(), $ticket)) {
            return response()->json(['message' => 'Only a resolved ticket can be closed.'], 422);
        }

        DB::transaction(function () use ($ticket): void {
            $ticket->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
            $ticket->conversation()->update(['status' => 'closed']);
        });

        return response()->json(['data' => $this->transformTicket($ticket->fresh(['order', 'conversation']))]);
    }

    public function reopen(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->ensureOwnTicket($request->user(), $ticket);

        if (! $this->supportTicketPolicy->reopenAsCreator($request->user(), $ticket)) {
            return response()->json(['message' => 'This ticket cannot be reopened.'], 422);
        }

        DB::transaction(function () use ($ticket): void {
            $ticket->forceFill([
                'status' => 'reopened',
                'resolved_at' => null,
                'closed_at' => null,
            ])->save();
            $ticket->conversation()->update(['status' => 'active']);
        });

        return response()->json(['data' => $this->transformTicket($ticket->fresh(['order', 'conversation']))]);
    }

    private function ensureCanUseCustomerService(Profile $user): void
    {
        abort_unless($this->supportTicketPolicy->create($user), 403, 'Customer Service is unavailable for this account.');
    }

    private function ensureOwnTicket(Profile $user, SupportTicket $ticket): void
    {
        abort_unless($this->supportTicketPolicy->viewOwn($user, $ticket), 404);
    }

    private function resolveRelatedOrder(Profile $creator, ?string $orderId): ?Order
    {
        if (! $orderId) {
            return null;
        }

        $order = Order::query()
            ->whereKey($orderId)
            ->when($creator->role === 'buyer', fn ($query) => $query->where('buyer_profile_id', $creator->id))
            ->when($creator->role === 'seller', fn ($query) => $query->where('seller_id', $creator->id))
            ->when(! in_array($creator->role, ['buyer', 'seller'], true), fn ($query) => $query->whereRaw('1 = 0'))
            ->first();

        if (! $order) {
            throw ValidationException::withMessages([
                'order_id' => 'That order is not available to your account.',
            ]);
        }

        return $order;
    }

    private function nextTicketNumber(): string
    {
        do {
            $number = 'CS-'.now()->format('Y').'-'.Str::upper(Str::random(8));
        } while (SupportTicket::where('ticket_number', $number)->exists());

        return $number;
    }

    private function markRead(SupportTicket $ticket, string $userId): void
    {
        $ticket->conversation?->participantRecords()
            ->where('user_id', $userId)
            ->update(['last_read_at' => now()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformTicket(SupportTicket $ticket, bool $withMessages = false): array
    {
        $conversation = $ticket->conversation;
        $data = [
            'id' => $ticket->id,
            'ticketNumber' => $ticket->ticket_number,
            'category' => $ticket->category,
            'categoryLabel' => self::CATEGORY_LABELS[$ticket->category] ?? Str::headline($ticket->category),
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'status' => $ticket->status,
            'statusLabel' => $this->customerStatusLabel($ticket->status),
            'actionNeeded' => $ticket->status === 'waiting_for_customer',
            'order' => $ticket->order ? [
                'id' => $ticket->order->id,
                'orderNumber' => $ticket->order->order_number,
                'status' => $ticket->order->status,
            ] : null,
            'resolutionSummary' => $ticket->resolution_summary,
            'lastUpdatedAt' => optional($conversation?->last_message_at ?? $ticket->updated_at)->toIso8601String(),
            'createdAt' => optional($ticket->created_at)->toIso8601String(),
            'updatedAt' => optional($ticket->updated_at)->toIso8601String(),
        ];

        if ($withMessages) {
            $data['messages'] = $conversation?->messages
                ->map(fn (Message $message): array => $this->transformCustomerMessage($message))
                ->values()
                ->all() ?? [];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformCustomerMessage(Message $message): array
    {
        $fromCustomerService = in_array($message->sender_role, ['admin', 'system'], true);

        return [
            'id' => $message->id,
            'body' => $message->body,
            'messageType' => $message->message_type,
            'sender' => [
                'type' => $fromCustomerService ? 'customer_service' : 'customer',
                'name' => $fromCustomerService ? 'Platform Customer Service' : 'You',
            ],
            'createdAt' => optional($message->created_at)->toIso8601String(),
        ];
    }

    private function customerStatusLabel(string $status): string
    {
        return match ($status) {
            'open' => 'In Review',
            'waiting_for_customer' => 'Action Needed',
            default => Str::headline($status),
        };
    }
}
