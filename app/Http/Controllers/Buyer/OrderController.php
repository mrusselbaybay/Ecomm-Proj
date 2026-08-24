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

        $orders = Order::with(['items', 'seller.sellerDetail'])
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

        $order = Order::with(['items', 'seller.sellerDetail'])
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

        return [
            'orderId' => '#' . $order->order_number,
            'createdAt' => optional($order->placed_at)->toIso8601String(),
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'shipping_method' => $order->shipping_service,
            'voucher_code' => null,
            'subtotal' => (float) $order->subtotal,
            'shipping_fee' => (float) $order->shipping_fee,
            'discount' => (float) $order->discount,
            'total' => (float) $order->total,
            'tracking_number' => $order->tracking_number,
            'delivery_address' => [
                'recipient_name' => $order->recipient_name,
                'contact_number' => $order->recipient_contact_no,
                'address' => $order->shipping_street,
            ],
            'items' => $order->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'name' => $item->product_name,
                'seller' => $sellerName,
                'category' => $item->category,
                'variation' => $item->variant,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
            ]),
        ];
    }
}