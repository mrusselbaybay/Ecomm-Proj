<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderReturnRequest;

/**
 * Maps an order to the buyer-facing My Orders tab without changing its
 * stored fulfilment status.
 */
class OrderStage
{
    public const TO_PAY = 'to_pay';

    public const TO_SHIP = 'to_ship';

    public const TO_RECEIVE = 'to_receive';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const RETURN_REFUND = 'return_refund';

    private const CANCELLED_STATUSES = ['cancelled', 'canceled', 'rejected'];

    private const SHIPPED_STATUSES = ['in transit', 'shipped', 'out for delivery'];

    private const DELIVERED_STATUSES = ['delivered', 'completed'];

    private const ACTIVE_RETURN_STATUSES = ['pending', 'approved', 'completed'];

    /**
     * Expects items.returnRequests to be eager loaded.
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
