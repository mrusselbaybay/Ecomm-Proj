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
 * buyer/seller on a specific order, not a company-to-company thread.
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

        $contextKey = Conversation::makeContextKey('delivery', $order->id.':'.$contact, [$courier->id, $contactId]);

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
            // skips straight past participant bootstrapping and the
            // greeting entirely: both only ever matter once, on genuine
            // creation, and re-running them on every single "Message" tap
            // was exactly the "repeat the initialization workflow every
            // click" cost this method used to pay even when nothing had
            // changed. reviveLeftParticipants() still always runs — it's
            // one cheap indexed UPDATE (a no-op when nothing's archived),
            // and skipping it would silently break re-messaging into a
            // thread either side had archived.
            $conversation->reviveLeftParticipants();

            if (! $conversation->wasRecentlyCreated) {
                return $conversation;
            }

            foreach ([$courier->id, $contactId] as $participantId) {
                ConversationParticipant::query()->firstOrCreate(
                    ['conversation_id' => $conversation->id, 'user_id' => $participantId],
                    ['joined_at' => now()],
                );
            }

            // Lock the row before the "has a message yet?" check so two
            // concurrent requests racing to create the exact same brand-new
            // conversation (a double-tap, or a retried request) can't both
            // see "no messages" and both insert the greeting — the second
            // transaction blocks here until the first commits, then finds
            // the row already has one.
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->first();

            // Auto-greeting, sent exactly once per thread — mirrors
            // Seller\MessageController::startLogisticsConversation()'s own
            // "only if nothing's been said yet" gate.
            if ($conversation->messages()->doesntExist()) {
                $this->sendGreeting($conversation, $courier, $assignment, $order, $contact);
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
