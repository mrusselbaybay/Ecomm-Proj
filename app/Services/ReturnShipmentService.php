<?php

namespace App\Services;

use App\Models\LogisticsCompany;
use App\Models\OrderReturnRequest;
use App\Models\ParcelAssignment;
use App\Services\Payments\MockPaymentService;
use App\Services\Payments\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reverse logistics for an approved Return + Refund — the forward delivery
 * run backwards on the same ParcelAssignment state machine:
 *
 *   forward:  seller -> courier -> origin hub [-> courier -> destination hub] -> courier -> buyer
 *   return:   buyer  -> courier -> destination hub [-> courier -> origin hub] -> courier -> seller
 *
 * Every return leg carries return_request_id; ParcelAssignment::routingOrder()
 * swaps the seller/buyer addresses so rider matching, transfer routing and
 * drop-off labels all work unchanged. The refund is only paid out once the
 * last leg is delivered back to the seller (complete()).
 */
class ReturnShipmentService
{
    public function __construct(
        private readonly MockPaymentService $payments,
        private readonly ParcelAutoAssignService $autoAssign,
        private readonly SellerNotifier $sellerNotifier,
    ) {}

    /**
     * Opens the first return leg at the company that delivered the parcel
     * to the buyer (the forward chain's last company), waiting on a courier
     * to collect it from the buyer's address.
     */
    public function start(OrderReturnRequest $request): ParcelAssignment
    {
        $order = $request->order;
        $chain = $this->payments->logisticsChain($order->id);

        if ($chain === []) {
            throw ValidationException::withMessages(['request' => 'This order was never shipped through a logistics company, so it cannot be returned through one.']);
        }

        $company = LogisticsCompany::findOrFail(end($chain));

        $assignment = new ParcelAssignment([
            'order_id' => $order->id,
            'logistics_company_id' => $company->id,
            'return_request_id' => $request->id,
            // Mirrors the forward is_transfer hint: the item has to cross
            // to another company before it can reach the seller.
            'is_transfer' => count($chain) > 1,
            'status' => ParcelAssignment::STATUS_RECEIVED,
            'received_at' => now(),
        ]);
        $assignment->setRelation('order', $order);

        $match = $this->autoAssign->matchRider($assignment->routingOrder(), $company, null, true);
        $hasRoute = $match['barangayAssignment'] !== null || $match['rider'] !== null;

        $assignment->fill([
            'barangay_assignment_id' => $match['barangayAssignment']?->id,
            'rider_profile_id' => $match['rider']?->id,
            'status' => $hasRoute ? ParcelAssignment::STATUS_SORTED : ParcelAssignment::STATUS_RECEIVED,
            'sorted_at' => $hasRoute ? now() : null,
        ])->save();

        $request->forceFill(['return_shipping_fee' => $order->shipping_fee])->save();

        return $assignment;
    }

    /**
     * The final return leg reached the seller: mark the request returned,
     * then refund the buyer and settle every party (MockPaymentService::settleReturn).
     */
    public function complete(ParcelAssignment $assignment): OrderReturnRequest
    {
        return DB::transaction(function () use ($assignment) {
            $request = OrderReturnRequest::with('order.items')->whereKey($assignment->return_request_id)->lockForUpdate()->firstOrFail();

            if ($request->status === 'completed') {
                return $request;
            }

            $order = $request->order;
            $itemCents = Money::toCents($request->estimated_amount);
            // What the buyer actually paid for shipping (after any shipping voucher).
            $shippingCents = Money::toCents($order->shipping_fee) - Money::toCents($order->shipping_discount ?? 0);

            // Original shipping goes back to the buyer (seller-funded) only
            // once every unit of the order has been returned.
            $returnedUnits = (int) OrderReturnRequest::where('order_id', $order->id)
                ->where('request_type', OrderReturnRequest::TYPE_RETURN_AND_REFUND)
                ->where(fn ($q) => $q->where('status', 'completed')->orWhere('id', $request->id))
                ->sum('quantity');
            $shippingRefund = $returnedUnits >= (int) $order->items->sum('quantity') ? $shippingCents : 0;

            $returnChain = ParcelAssignment::where('return_request_id', $request->id)
                ->orderBy('created_at')->pluck('logistics_company_id')->unique()->values()->all();

            $this->payments->settleReturn(
                $order->id,
                $request->id,
                $itemCents,
                $shippingRefund,
                Money::toCents($request->return_shipping_fee ?? $order->shipping_fee),
                $returnChain,
                Money::toCents($request->voucher_discount ?? 0),
                Money::toCents($request->platform_discount ?? 0),
            );

            $request->forceFill([
                'status' => 'completed',
                'refunded_amount' => Money::format($itemCents + $shippingRefund),
                'returned_at' => now(),
            ])->save();

            $this->sellerNotifier->notify(
                sellerId: $request->seller_id,
                type: 'return_completed',
                title: "Returned item received — order #{$order->order_number}",
                body: 'The courier delivered the returned item back to you. The buyer has been refunded and the return shipping was charged to your account.',
                data: ['orderNumber' => $order->order_number, 'returnRequestId' => $request->id],
                orderId: $order->id,
                dedupeKey: "return_completed:{$request->id}",
            );

            return $request;
        });
    }
}
