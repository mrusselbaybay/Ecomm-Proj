<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buyer confirmation of a courier-delivered order — the trigger that
 * releases escrow. Only possible once the courier has marked the order
 * 'Delivered'; idempotent (a second confirmation returns the order as-is).
 */
class OrderReceiptService
{
    public const VIA_BUYER = 'buyer';
    public const VIA_AUTO = 'auto';

    public const AUTO_CONFIRM_DAYS = 7;

    public function __construct(private MockPaymentService $payments) {}

    public function confirm(Order $order, string $via = self::VIA_BUYER): Order
    {
        return DB::transaction(function () use ($order, $via) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->received_at) {
                return $locked;
            }

            if ($locked->status !== 'Delivered') {
                throw ValidationException::withMessages([
                    'order' => 'You can confirm receipt once the courier marks this order as delivered.',
                ]);
            }

            // Mock escrow: orders placed before checkout funded escrow are
            // funded here so the release below still demonstrates the split.
            if ($locked->escrow_status === MockPaymentService::ESCROW_UNFUNDED) {
                $this->payments->chargeBuyer($locked->id, $locked->total);
            }

            $this->payments->releaseEscrow($locked->id);

            $locked->refresh()->forceFill(['received_at' => now(), 'received_via' => $via])->save();

            return $locked;
        });
    }
}
