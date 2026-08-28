<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * GET /api/buyer/orders
     */
    public function index(Request $request): JsonResponse
    {
        $buyer = $request->user();

        $orders = Order::with(['items.review', 'seller.sellerDetail', 'statusHistory'])
            ->where('buyer_profile_id', $buyer->id)
            ->orderByDesc('placed_at')
            ->get();

        return response()->json([
            'data' => $orders->map(fn (Order $order) => $this->transform($order)),
        ]);
    }

    /**
     * GET /api/buyer/orders/{id}
     *
     * {id} is the public order number (leading "#" stripped), scoped by
     * buyer_profile_id so another buyer's order resolves as a plain 404.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $buyer = $request->user();

        $order = Order::with(['items.review', 'seller.sellerDetail', 'statusHistory'])
            ->where('buyer_profile_id', $buyer->id)
            ->where('order_number', ltrim($id, '#'))
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json(['data' => $this->transform($order)]);
    }

    private function transform(Order $order): array
    {
        $sellerName = $order->seller?->sellerDetail?->business_name
            ?? $order->seller?->full_name
            ?? 'NEXMART Seller';

        // Full formatted line from every real address component the
        // checkout flow actually captured — the previous version of this
        // endpoint only ever returned shipping_street, silently dropping
        // house_no/barangay/municipality/province even though they're
        // stored and were collected at checkout.
        $addressLine = collect([
            $order->shipping_house_no,
            $order->shipping_street,
            $order->shipping_barangay,
            $order->shipping_municipality_name,
            $order->shipping_province_name,
        ])->filter()->implode(', ');

        return [
            'orderId' => '#' . $order->order_number,
            'createdAt' => optional($order->placed_at)->toIso8601String(),
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'shipping_method' => $order->shipping_service,
            'shipping_carrier' => $order->shipping_carrier,
            'voucher_code' => null,
            'subtotal' => (float) $order->subtotal,
            'shipping_fee' => (float) $order->shipping_fee,
            'tax' => (float) $order->tax,
            'discount' => (float) $order->discount,
            'total' => (float) $order->total,
            'tracking_number' => $order->tracking_number,
            'delivery_address' => [
                'recipient_name' => $order->recipient_name,
                'contact_number' => $order->recipient_contact_no,
                'address' => $addressLine !== '' ? $addressLine : $order->shipping_street,
            ],
            // Real transitions only — whatever's actually been logged to
            // order_status_history (CheckoutService writes the initial
            // "New" entry; a future seller-side status update would add
            // more). No fabricated per-step timestamps for statuses this
            // order hasn't reached yet — see OrderDetails.vue's timeline.
            'statusHistory' => $order->statusHistory->map(fn ($history) => [
                'status' => $history->status,
                'note' => $history->note,
                'createdAt' => optional($history->created_at)->toIso8601String(),
            ])->all(),
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->product_name,
                'seller' => $sellerName,
                'category' => $item->category,
                'variation' => $item->variant,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                // Real, persisted review for this line item if one exists
                // (reviews.order_item_id is unique — at most one). Used
                // by OrderDetails.vue to show "Your review" instead of
                // "Rate Product" once a buyer has actually reviewed this
                // item — see Buyer\ReviewController for where it's created.
                'review' => $item->review ? [
                    'id' => $item->review->id,
                    'rating' => $item->review->rating,
                    'comment' => $item->review->comment,
                    'createdAt' => optional($item->review->created_at)->toIso8601String(),
                ] : null,
            ]),
        ];
    }
}