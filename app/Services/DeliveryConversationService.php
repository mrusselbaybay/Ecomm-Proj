<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Order;
use App\Models\ParcelAssignment;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates (or reuses) a courier's direct thread with one delivery's buyer
 * or seller — the "Message" action next to the call button on the
 * driver app's delivery-detail screen (assigned_delivery_detail_screen.dart).
 * Mirrors ShipmentConversationService's shape (seller <-> logistics), one
 * level down: this is the individual courier reaching the individual
 * buyer/seller.
 *
 * Keyed by (courier, contact) only — NOT by order — so the same rider
 * delivering this buyer/seller a second parcel later reuses the exact
 * same thread instead of opening a new one per order, mirroring how a
 * buyer<->seller 'direct' thread spans every order between that pair
 * (DirectConversationService). `order_id`/`parcel_assignment_id` on the
 * conversation row are therefore just "the most recently touched
 * delivery", refreshed on every findOrCreate()/findOrCreateForBuyer()
 * call — individual messages carry their own order_id/product_id for
 * anything that needs to point at a specific past parcel (see
 * Buyer\MessageController::appendMessage()'s order_id/product_id params
 * and the "Inquire about a certain parcel" picker).
 */
class DeliveryConversationService
{
    public function findOrCreate(Profile $courier, string $parcelAssignmentId, string $contact): Conversation
    {
        $assignment = ParcelAssignment::query()
            ->with(['order', 'logisticsCompany'])
            // Same scoping DriverDeliveryController::index() uses — a
            // delivery currently dispatched to this rider, or one they
            // personally ran the pickup leg on.
            ->where(fn ($q) => $q->where('rider_profile_id', $courier->id)->orWhere('picked_up_by', $courier->id))
            ->whereKey($parcelAssignmentId)
            ->first();

        $order = $assignment?->order;
        $contactId = $contact === 'buyer' ? $order?->buyer_profile_id : $order?->seller_id;

        if (! $assignment || ! $order || ! $contactId) {
            throw ValidationException::withMessages([
                'parcel_assignment_id' => "This delivery has no eligible {$contact} to message.",
            ]);
        }

        $contextKey = Conversation::makeContextKey('delivery', $contact, [$courier->id, $contactId]);

        return DB::transaction(function () use ($assignment, $order, $courier, $contact, $contactId, $contextKey): Conversation {
            $conversation = Conversation::query()->firstOrCreate(
                ['context_key' => $contextKey],
                [
                    'type' => 'delivery',
                    'created_by' => $courier->id,
                    'courier_profile_id' => $courier->id,
                    'buyer_id' => $contact === 'buyer' ? $contactId : null,
                    'seller_id' => $contact === 'seller' ? $contactId : null,
                    'order_id' => $order->id,
                    'parcel_assignment_id' => $assignment->id,
                    'subject' => 'Order '.$order->order_number,
                    'status' => 'open',
                ],
            );

            // wasRecentlyCreated (set by firstOrCreate() above, no extra
            // query) tells us for free whether this row is brand new. The
            // hot path — a rider re-opening a thread that already exists —
            // skips straight past participant bootstrapping: that only
            // ever matters once, on genuine creation, and re-running it on
            // every single "Message" tap was exactly the "repeat the
            // initialization workflow every click" cost this method used
            // to pay even when nothing had changed. reviveLeftParticipants()
            // still always runs — it's one cheap indexed UPDATE (a no-op
            // when nothing's archived), and skipping it would silently
            // break re-messaging into a thread either side had archived.
            $conversation->reviveLeftParticipants();

            if (! $conversation->wasRecentlyCreated) {
                // Reused thread: point it at whichever delivery prompted
                // this tap so anything reading conversation-level order/
                // assignment context (e.g. ConversationPolicy) reflects the
                // current one, not a stale earlier parcel.
                if ($conversation->order_id !== $order->id) {
                    $conversation->forceFill([
                        'order_id' => $order->id,
                        'parcel_assignment_id' => $assignment->id,
                        'subject' => 'Order '.$order->order_number,
                    ])->save();
                }
            } else {
                foreach ([$courier->id, $contactId] as $participantId) {
                    ConversationParticipant::query()->firstOrCreate(
                        ['conversation_id' => $conversation->id, 'user_id' => $participantId],
                        ['joined_at' => now()],
                    );
                }
            }

            // Lock the row before the "already greeted for this order?"
            // check so two concurrent requests racing to greet the exact
            // same order (a double-tap, or a retried request) can't both
            // see "not yet greeted" and both insert one — the second
            // transaction blocks here until the first commits, then finds
            // the row already has it.
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->first();

            // Auto-greeting, sent once per order rather than once per
            // thread — a thread now spans every delivery this courier has
            // run for this contact, so a genuinely new parcel still needs
            // its own tracking-number/COD greeting even in a thread that
            // already has history from a previous one.
            if ($conversation->messages()->where('order_id', $order->id)->doesntExist()) {
                $this->sendGreeting($conversation, $courier, $assignment, $order, $contact);
            }

            return $conversation;
        });
    }

    /**
     * The buyer-initiated mirror of findOrCreate() above — the "Message
     * Courier" button on the buyer's Order Details page, rather than the
     * driver app's "Message" button. Reuses the exact same context_key
     * formula (type + contact + sorted participant ids, no order — see
     * this class's docblock) so whichever side starts the thread first is
     * the only one either side ever sees, and a buyer messaging the same
     * courier again about a later order lands in that same thread instead
     * of a new one. Never sends the courier-authored greeting (that's
     * specific to the courier-initiated path) — the buyer supplies their
     * own first message, appended by the caller (see
     * Buyer\MessageController::appendMessage(), which already handles a
     * 'delivery' conversation's courier_unread_count correctly).
     */
    public function findOrCreateForBuyer(Profile $buyer, string $orderNumber): Conversation
    {
        $order = Order::query()
            ->where('order_number', ltrim($orderNumber, '#'))
            ->where('buyer_profile_id', $buyer->id)
            ->with(['parcelAssignment.rider'])
            ->first();

        $assignment = $order?->parcelAssignment;
        $courier = $assignment?->rider;

        if (! $order) {
            throw ValidationException::withMessages([
                'order_number' => 'That order was not found on your account.',
            ]);
        }

        if (! $assignment || ! $courier) {
            throw ValidationException::withMessages([
                'order_number' => 'This order has no courier assigned yet.',
            ]);
        }

        $contextKey = Conversation::makeContextKey('delivery', 'buyer', [$courier->id, $buyer->id]);

        return DB::transaction(function () use ($assignment, $order, $courier, $buyer, $contextKey): Conversation {
            $conversation = Conversation::query()->firstOrCreate(
                ['context_key' => $contextKey],
                [
                    'type' => 'delivery',
                    'created_by' => $buyer->id,
                    'courier_profile_id' => $courier->id,
                    'buyer_id' => $buyer->id,
                    'seller_id' => null,
                    'order_id' => $order->id,
                    'parcel_assignment_id' => $assignment->id,
                    'subject' => 'Order '.$order->order_number,
                    'status' => 'open',
                ],
            );

            $conversation->reviveLeftParticipants();

            if ($conversation->wasRecentlyCreated) {
                foreach ([$courier->id, $buyer->id] as $participantId) {
                    ConversationParticipant::query()->firstOrCreate(
                        ['conversation_id' => $conversation->id, 'user_id' => $participantId],
                        ['joined_at' => now()],
                    );
                }
            } elseif ($conversation->order_id !== $order->id) {
                // Reused thread, but about a different order than it was
                // last pointed at — keep conversation-level context on
                // whichever delivery the buyer is asking about right now.
                $conversation->forceFill([
                    'order_id' => $order->id,
                    'parcel_assignment_id' => $assignment->id,
                    'subject' => 'Order '.$order->order_number,
                ])->save();
            }

            return $conversation;
        });
    }

    /**
     * "{Logistics}!, TN: {tracking}, Rider: {name}. Your COD amount is
     * {total}." — lets the buyer/seller immediately recognise who's
     * messaging them and why, without the rider having to type it out
     * every time. Composed server-side (not trusted from the client) since
     * every piece of it — the rider's own employer/name and the order's
     * tracking number/total — is already authoritative data this service
     * just loaded.
     */
    private function sendGreeting(Conversation $conversation, Profile $courier, ParcelAssignment $assignment, Order $order, string $contact): void
    {
        $logisticsName = $assignment->logisticsCompany?->company_name ?: 'Logistics';
        $trackingNumber = $order->tracking_number ?: 'N/A';
        $riderName = $courier->full_name ?: 'Rider';
        $codAmount = '₱'.number_format((float) $order->total, 2);
        $senderRole = $courier->role;

        $body = "{$logisticsName}!, TN: {$trackingNumber}, Rider: {$riderName}. Your COD amount is {$codAmount}.";

        $message = $conversation->messages()->create([
            'sender_id' => $courier->id,
            'sender_role' => $senderRole,
            'message_type' => 'text',
            'body' => $body,
            'attachments' => [],
            // Tags which delivery this greeting was for, so findOrCreate()
            // can tell "already greeted for this order" apart from
            // "already greeted for a different, earlier order in this same
            // thread" — a thread now spans every delivery this courier has
            // run for this contact, not just one.
            'order_id' => $order->id,
        ]);

        $unreadColumn = $contact === 'buyer' ? 'buyer_unread_count' : 'seller_unread_count';

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
            'last_message_preview' => mb_substr($body, 0, 160),
            'last_message_sender_role' => $senderRole,
            'courier_unread_count' => 0,
            $unreadColumn => $conversation->{$unreadColumn} + 1,
        ])->save();
    }
}
