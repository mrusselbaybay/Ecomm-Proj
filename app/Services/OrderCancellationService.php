<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buyer-initiated order cancellation.
 *
 * Kept compatible with the seller order backend: it uses the exact same
 * 'Cancelled' status value and order_status_history contract that
 * Seller\SellerOrderController::updateStatus writes, so a buyer
 * cancellation is indistinguishable to the seller UI from one the seller
 * made — no parallel "buyer cancelled" state.
 *
 * A buyer may only cancel while the order is still 'New' (nothing packed
 * or shipped yet). Order::ALLOWED_TRANSITIONS already permits
 * New -> Cancelled; this re-checks it rather than assuming.
 */
class OrderCancellationService
{
    /**
     * @throws ValidationException when the order can't be cancelled by the buyer.
     */
    public function cancel(Profile $buyer, Order $order, ?string $reason = null): Order
    {
        if ($order->buyer_profile_id !== $buyer->id) {
            throw ValidationException::withMessages([
                'order' => 'This order was not found on your account.',
            ]);
        }

        if ($order->status === 'Cancelled') {
            throw ValidationException::withMessages([
                'order' => 'This order is already cancelled.',
            ]);
        }

        // Buyers can only cancel before the seller starts fulfilment.
        if ($order->status !== 'New' || ! $order->canTransitionTo('Cancelled')) {
            throw ValidationException::withMessages([
                'order' => 'This order can no longer be cancelled. Please contact the seller.',
            ]);
        }

        return DB::transaction(function () use ($buyer, $order, $reason) {
            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status !== 'New') {
                throw ValidationException::withMessages([
                    'order' => 'This order can no longer be cancelled. Please contact the seller.',
                ]);
            }

            $order->loadMissing('items');

            // Put stock back exactly the way CheckoutService took it out.
            foreach ($order->items as $item) {
                if ($item->variant_id) {
                    $variant = ProductVariant::query()->lockForUpdate()->find($item->variant_id);
                    $variant?->increment('stock', (int) $item->quantity);

                    continue;
                }

                if ($item->product_id) {
                    $product = Product::query()->lockForUpdate()->find($item->product_id);
                    $product?->increment('stock', (int) $item->quantity);
                }
            }

            $order->status = 'Cancelled';

            if ($order->payment_status === 'Paid') {
                $order->payment_status = 'Refunded';
            }

            $order->save();

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status' => 'Cancelled',
                'note' => $reason !== null && $reason !== '' ? $reason : 'Cancelled by buyer.',
                'changed_by' => $buyer->id,
            ]);

            return $order->load(['items.review', 'items.returnRequests', 'seller.sellerDetail', 'statusHistory']);
        });
    }
}
