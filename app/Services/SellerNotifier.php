<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SellerNotification;
use Throwable;

/**
 * Writes rows into a seller's notification inbox (seller_notifications).
 *
 * Call these AFTER the related DB transaction has committed — never from
 * inside it (spec: "Send notifications only after the related database
 * transaction succeeds"). Each notification carries a dedupe_key so a
 * repeated request produces no duplicate row.
 */
class SellerNotifier
{
    /**
     * A buyer placed an order containing this seller's products.
     * The buyer checkout (feature/buyer) calls this once per created
     * seller order, right after CheckoutService's transaction commits.
     */
    public function orderPlaced(Order $order): void
    {
        $this->notify(
            sellerId: $order->seller_id,
            type: 'order_placed',
            title: "New order {$this->ref($order)}",
            body: sprintf(
                '%s · %s item%s · ₱%s',
                $order->recipient_name ?: 'A buyer',
                $order->items()->count(),
                $order->items()->count() === 1 ? '' : 's',
                number_format((float) $order->total, 2),
            ),
            data: [
                'orderNumber' => $order->order_number,
                'buyer' => $order->recipient_name,
                'total' => (float) $order->total,
                'placedAt' => optional($order->placed_at)->toIso8601String(),
            ],
            orderId: $order->id,
            dedupeKey: "order_placed:{$order->id}",
        );
    }

    /**
     * An order's fulfilment status changed. `$actorLabel` is e.g.
     * "the seller", "logistics", "the buyer", "system".
     */
    public function orderStatusChanged(Order $order, string $from, string $to, string $actorLabel): void
    {
        $this->notify(
            sellerId: $order->seller_id,
            type: 'order_status_changed',
            title: "Order {$this->ref($order)} is now {$this->label($to)}",
            body: sprintf('%s → %s (by %s)', $this->label($from), $this->label($to), $actorLabel),
            data: [
                'orderNumber' => $order->order_number,
                'from' => $from,
                'to' => $to,
                'toLabel' => $this->label($to),
            ],
            orderId: $order->id,
            dedupeKey: "order_status_changed:{$order->id}:{$to}",
        );
    }

    /**
     * Low-level insert. Safe to call twice with the same dedupe_key — the
     * unique (seller_id, dedupe_key) index makes the second a no-op.
     */
    public function notify(
        string $sellerId,
        string $type,
        string $title,
        ?string $body,
        array $data = [],
        ?string $orderId = null,
        ?string $dedupeKey = null,
    ): ?SellerNotification {
        $attributes = [
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'order_id' => $orderId,
        ];

        try {
            if ($dedupeKey !== null) {
                return SellerNotification::firstOrCreate(
                    ['seller_id' => $sellerId, 'dedupe_key' => $dedupeKey],
                    $attributes,
                );
            }

            return SellerNotification::create($attributes + ['seller_id' => $sellerId]);
        } catch (Throwable $e) {
            // A notification failing must never break the action that
            // triggered it (the transaction has already committed).
            report($e);

            return null;
        }
    }

    private function ref(Order $order): string
    {
        return '#'.$order->order_number;
    }

    private function label(string $status): string
    {
        return Order::STATUS_LABELS[$status] ?? $status;
    }
}
