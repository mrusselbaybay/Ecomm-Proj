<?php

namespace App\Services\Payments;

use App\Models\OrderReturnRequest;
use App\Models\Profile;
use App\Services\ReturnShipmentService;
use App\Services\SellerNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Seller decision on a buyer's return/refund request.
 *
 * Refund only: approval refunds the item amount through the mock escrow
 * right away, with the seller funding the refund and logistics keeping its share.
 *
 * Return + Refund: approval only starts the reverse delivery
 * (ReturnShipmentService); the refund is paid once the item is back with
 * the seller, and logistics/platform keep their forward earnings.
 */
class RefundRequestService
{
    public function __construct(
        private MockPaymentService $payments,
        private ReturnShipmentService $returns,
        private SellerNotifier $sellerNotifier,
    ) {}

    public function approve(OrderReturnRequest $request, Profile $seller, ?string $note = null): OrderReturnRequest
    {
        return DB::transaction(function () use ($request, $seller, $note) {
            $locked = $this->lockPending($request, $seller);
            $order = $locked->order;

            if (! in_array($order->escrow_status, [MockPaymentService::ESCROW_FUNDED, MockPaymentService::ESCROW_RELEASED], true)) {
                throw ValidationException::withMessages(['request' => 'This order has no escrow payment left to refund.']);
            }

            $refunded = $this->payments->refundedCents($order->id, true);
            $amount = min(Money::toCents($locked->estimated_amount), Money::toCents($order->total) - $refunded);

            if ($amount <= 0) {
                throw ValidationException::withMessages(['request' => 'This order has already been fully refunded.']);
            }

            if ($locked->needsPhysicalReturn()) {
                $this->returns->start($locked);

                $locked->forceFill([
                    'status' => 'approved',
                    'resolution_note' => $note,
                    'reviewed_by' => $seller->id,
                    'resolved_at' => now(),
                ])->save();

                $this->sellerNotifier->notify(
                    sellerId: $seller->id,
                    type: 'return_started',
                    title: "Return approved — order #{$order->order_number}",
                    body: 'A courier will collect the item from the buyer and bring it back to you through logistics. The return shipping fee is charged to you, and the buyer is refunded once the item reaches you.',
                    data: ['orderNumber' => $order->order_number, 'returnRequestId' => $locked->id],
                    orderId: $order->id,
                    dedupeKey: "return_started:{$locked->id}",
                );

                return $locked;
            }

            $this->payments->settleRefundOnly($order->id, $locked->id, $amount,
                Money::toCents($locked->voucher_discount ?? 0), Money::toCents($locked->platform_discount ?? 0));

            $locked->forceFill([
                'status' => 'approved',
                'refunded_amount' => Money::format($amount),
                'resolution_note' => $note,
                'reviewed_by' => $seller->id,
                'resolved_at' => now(),
            ])->save();

            return $locked;
        });
    }

    public function reject(OrderReturnRequest $request, Profile $seller, string $note): OrderReturnRequest
    {
        return DB::transaction(function () use ($request, $seller, $note) {
            $locked = $this->lockPending($request, $seller);
            $locked->forceFill([
                'status' => 'rejected',
                'resolution_note' => $note,
                'reviewed_by' => $seller->id,
                'resolved_at' => now(),
            ])->save();

            return $locked;
        });
    }

    private function lockPending(OrderReturnRequest $request, Profile $seller): OrderReturnRequest
    {
        $locked = OrderReturnRequest::with('order')->whereKey($request->id)->lockForUpdate()->firstOrFail();

        abort_unless($locked->seller_id === $seller->id, 404);

        if ($locked->status !== 'pending') {
            throw ValidationException::withMessages(['request' => "This request has already been {$locked->status}."]);
        }

        return $locked;
    }
}
