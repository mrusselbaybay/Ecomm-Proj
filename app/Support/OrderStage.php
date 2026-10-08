<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderReturnRequest;

/**
 * Which My Orders tab an order belongs in, from what the order actually
 * is — its stored status, payment and return requests are never changed.
 *
 * orders.status is written by the seller app, which uses more states than
 * Order::STATUSES lists (seen in the data: New, Confirmed, Ready for
 * Pickup, In Transit, Delivered, Cancelled, Rejected), so the rules match
 * on meaning, case-insensitively:
 *
 *   cancelled      Cancelled, or Rejected by the seller.
 *   return_refund  The buyer has an open or settled return/refund request
 *                  (pending, approved or completed) on any item, or the
 *                  payment was refunded on an order that wasn't cancelled.
 *                  A rejected or withdrawn request puts the order back in
 *                  its normal tab.
 *   completed      Delivered.
 *   to_receive     Handed to the courier: In Transit, Shipped, Out for
 *                  Delivery.
 *   to_pay         Not shipped yet, paid online, and still unpaid. Cash on
 *                  delivery is paid to the courier on arrival, so a COD
 *                  order is never "to pay" — it waits under To Ship. (COD
 *                  is the only method checkout offers today, so this tab
 *                  is empty until an online payment method exists.)
 *   to_ship        Everything else before shipping: New, Confirmed,
 *                  Processing, Ready for Pickup, and any new pre-shipping
 *                  state the seller app adds.
 */
class OrderStage
{
    public const TO_PAY = 'to_pay';

    public const TO_SHIP = 'to_ship';

    public const TO_RECEIVE = 'to_receive';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const RETURN_REFUND = 'return_refund';

    /** Tabs in display order, with their labels. */
    public const LABELS = [
        self::TO_PAY => 'To Pay',
        self::TO_SHIP => 'To Ship',
        self::TO_RECEIVE => 'To Receive',
        self::COMPLETED => 'Completed',
        self::CANCELLED => 'Cancelled',
        self::RETURN_REFUND => 'Return/Refund',
    ];

    private const CANCELLED_STATUSES = ['cancelled', 'canceled', 'rejected'];

    private const SHIPPED_STATUSES = ['in transit', 'shipped', 'out for delivery'];

    private const DELIVERED_STATUSES = ['delivered', 'completed'];

    /** Return requests that keep an order under Return/Refund. */
    private const ACTIVE_RETURN_STATUSES = ['pending', 'approved', 'completed'];

    /**
     * Expects items.returnRequests to be loaded (it reads them as a
     * collection, so an unloaded relation would cost a query per item).
     */
    public static function for(Order $order): string
    {
        $status = mb_strtolower(trim((string) $order->status));

        if (in_array($status, self::CANCELLED_STATUSES, true)) {
            return self::CANCELLED;
        }

        $hasActiveReturn = $order->items->contains(
            fn ($item) => $item->returnRequests->contains(
                fn (OrderReturnRequest $request) => in_array(mb_strtolower((string) $request->status), self::ACTIVE_RETURN_STATUSES, true),
            ),
        );

        if ($hasActiveReturn || $order->payment_status === 'Refunded') {
            return self::RETURN_REFUND;
        }

        if (in_array($status, self::DELIVERED_STATUSES, true)) {
            return self::COMPLETED;
        }

        if (in_array($status, self::SHIPPED_STATUSES, true)) {
            return self::TO_RECEIVE;
        }

        if ($order->payment_method !== 'cod' && $order->payment_status === 'Unpaid') {
            return self::TO_PAY;
        }

        return self::TO_SHIP;
    }
}
