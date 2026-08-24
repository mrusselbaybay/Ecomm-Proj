<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerOrderController extends Controller
{
    /**
     * Friendly labels used to build the order timeline shown on the
     * Order Details page (resources/js/seller/components/OrderDetails.vue).
     */
    private const STATUS_LABELS = [
        'New' => 'Order Placed',
        'Processing' => 'Order accepted by seller',
        'In Transit' => 'Shipped',
        'Delivered' => 'Delivered',
        'Cancelled' => 'Cancelled',
    ];

    /**
     * GET /api/seller/orders
     *
     * Returns every order that belongs to the authenticated seller.
     * Optional query params (all client-side already in Orders.vue, kept
     * here too so the same endpoint scales if that page's filtering ever
     * moves server-side): status, search.
     */
    public function index(Request $request): JsonResponse
    {
        $seller = $request->user();

        $query = Order::with(['items', 'buyer.address'])
            ->where('seller_id', $seller->id);

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'ilike', "%{$search}%")
                    ->orWhere('recipient_name', 'ilike', "%{$search}%");
            });
        }

        $orders = $query->orderByDesc('placed_at')->get();

        return response()->json([
            'data' => $orders->map(fn (Order $order) => $this->transformSummary($order)),
        ]);
    }

    /**
     * GET /api/seller/orders/{id}
     *
     * {id} is the public order number (e.g. "SN-97210" or "#SN-97210" —
     * the leading "#" the UI displays is stripped), not the internal
     * uuid — the frontend never needs to see the raw primary key.
     *
     * Scoped by seller_id in the query itself (rather than route-model
     * binding) so an order belonging to another seller resolves as a
     * plain 404 instead of leaking that the order exists via a 403.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $seller = $request->user();

        $order = Order::with(['items', 'buyer.address', 'statusHistory.changedBy'])
            ->where('seller_id', $seller->id)
            ->where('order_number', ltrim($id, '#'))
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json(['data' => $this->transformDetail($order)]);
    }

    /**
     * PUT /api/seller/orders/{id}/status
     *
     * Sellers may only move an order forward through the allowed state
     * machine (Order::ALLOWED_TRANSITIONS) or cancel it — never reopen a
     * finished order, and never touch another seller's order.
     */
    public function updateStatus(UpdateOrderStatusRequest $request, string $id): JsonResponse
    {
        $seller = $request->user();

        $order = Order::where('seller_id', $seller->id)
            ->where('order_number', ltrim($id, '#'))
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $newStatus = $request->validated('status');

        if (!$order->canTransitionTo($newStatus)) {
            return response()->json([
                'message' => "Order cannot move from \"{$order->status}\" to \"{$newStatus}\".",
            ], 422);
        }

        DB::transaction(function () use ($order, $newStatus, $request, $seller) {
            $order->status = $newStatus;

            if ($request->filled('tracking_number')) {
                $order->tracking_number = $request->validated('tracking_number');
            }
            if ($request->filled('shipping_carrier')) {
                $order->shipping_carrier = $request->validated('shipping_carrier');
            }
            if ($request->filled('shipping_service')) {
                $order->shipping_service = $request->validated('shipping_service');
            }

            $order->save();

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status' => $newStatus,
                'note' => $request->validated('reason'),
                'changed_by' => $seller->id,
            ]);
        });

        $order->load(['items', 'buyer.address', 'statusHistory.changedBy']);

        return response()->json(['data' => $this->transformDetail($order)]);
    }

    /**
     * Shape used by both index() and show() for the shared fields, matching
     * the sample-data contract in resources/js/seller/composables/useOrders.js
     * so the Vue components need no template changes.
     */
    private function transformSummary(Order $order): array
    {
        $buyer = $order->buyer;

        return [
            'id' => '#' . $order->order_number,
            'customer' => $order->recipient_name,
            'email' => $buyer?->email,
            'phone' => $order->recipient_contact_no,
            'date' => optional($order->placed_at)->format('F d, Y'),
            'time' => optional($order->placed_at)->format('h:i A'),
            'placedAt' => optional($order->placed_at)->toIso8601String(),
            // Approximates "last status change" for orders that have
            // already shipped (In Transit/Delivered are effectively
            // terminal-ish states, so this is a reasonable proxy for
            // "handed to courier at" without a dedicated timestamp
            // column) — used by the Courier Handover history table.
            'updatedAt' => optional($order->updated_at)->toIso8601String(),
            'status' => $order->status,
            'paymentMethod' => $order->payment_method,
            'paymentStatus' => $order->payment_status,
            'subtotal' => (float) $order->subtotal,
            'shippingFee' => (float) $order->shipping_fee,
            'tax' => (float) $order->tax,
            'discount' => (float) $order->discount,
            'total' => (float) $order->total,
            // Flat fields (not nested under `shipping`, unlike
            // transformDetail below) so the Courier Handover history
            // table can read them straight from the list response
            // without an extra per-order detail fetch.
            'trackingNumber' => $order->tracking_number,
            'shippingCarrier' => $order->shipping_carrier,
            'shippingService' => $order->shipping_service,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->product_name,
                'category' => $item->category,
                'sku' => $item->sku,
                'variant' => $item->variant,
                'qty' => $item->quantity,
                'price' => (float) $item->unit_price,
            ])->all(),
        ];
    }

    private function transformDetail(Order $order): array
    {
        $summary = $this->transformSummary($order);

        $summary['address'] = [
            'recipient' => $order->recipient_name,
            'street' => $order->shipping_street,
            'barangay' => $order->shipping_barangay,
            'municipality' => $order->shipping_municipality_name,
            'province' => $order->shipping_province_name,
            'country' => 'Philippines',
        ];

        $summary['shipping'] = [
            'method' => $order->shipping_service,
            'handlingTime' => null,
            'carrier' => $order->shipping_carrier,
            'service' => $order->shipping_service,
            'trackingNumber' => $order->tracking_number,
        ];

        $summary['timeline'] = $order->statusHistory->map(fn (OrderStatusHistory $h) => [
            'label' => self::STATUS_LABELS[$h->status] ?? $h->status,
            'time' => $h->created_at?->format('F d, Y \a\t h:i A'),
            'done' => true,
            'detail' => $h->note,
        ])->all();

        return $summary;
    }
}