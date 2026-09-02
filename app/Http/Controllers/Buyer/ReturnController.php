<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\StoreReturnRequest;
use App\Models\OrderItem;
use App\Models\OrderReturnRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Buyer-initiated return / refund requests (order_return_requests).
 *
 * A request can only be opened for an order_item that:
 *   - belongs to one of this buyer's own orders,
 *   - is on an order that's actually Delivered (mirrors
 *     OrderDetails.vue's canRequestReturn — re-checked here since the
 *     frontend guard is only UX),
 *   - has no other open (pending/approved) request already,
 *   - and where the requested quantity doesn't exceed what was ordered.
 */
class ReturnController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $requests = OrderReturnRequest::with(['order', 'orderItem'])
            ->where('buyer_profile_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $requests->map(fn (OrderReturnRequest $r) => $this->transform($r)),
        ]);
    }

    public function store(StoreReturnRequest $request): JsonResponse
    {
        $buyer = $request->user();
        $data = $request->validated();

        $orderItem = OrderItem::with('order')->whereKey($data['order_item_id'])->first();

        if (! $orderItem || $orderItem->order?->buyer_profile_id !== $buyer->id) {
            throw ValidationException::withMessages([
                'order_item_id' => 'This item was not found on one of your orders.',
            ]);
        }

        if ($orderItem->order->status !== 'Delivered') {
            throw ValidationException::withMessages([
                'order_item_id' => 'You can only request a return for items from delivered orders.',
            ]);
        }

        if ($data['quantity'] > (int) $orderItem->quantity) {
            throw ValidationException::withMessages([
                'quantity' => "You only ordered {$orderItem->quantity} of this item.",
            ]);
        }

        $hasOpen = OrderReturnRequest::where('order_item_id', $orderItem->id)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($hasOpen) {
            throw ValidationException::withMessages([
                'order_item_id' => 'There is already an open request for this item.',
            ]);
        }

        $returnRequest = OrderReturnRequest::create([
            'order_id' => $orderItem->order_id,
            'order_item_id' => $orderItem->id,
            'buyer_profile_id' => $buyer->id,
            'seller_id' => $orderItem->order->seller_id,
            'request_type' => $data['request_type'],
            'reason' => $data['reason'],
            'details' => $data['details'],
            'quantity' => $data['quantity'],
            'estimated_amount' => (float) $orderItem->unit_price * (int) $data['quantity'],
            'evidence' => array_values($data['evidence']),
            'status' => 'pending',
        ]);

        return response()->json(['data' => $this->transform($returnRequest)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(OrderReturnRequest $request): array
    {
        return [
            'id' => $request->id,
            'orderId' => $request->order ? '#'.$request->order->order_number : null,
            'orderItemId' => $request->order_item_id,
            'productName' => $request->orderItem?->product_name,
            'requestType' => $request->request_type,
            'reason' => $request->reason,
            'details' => $request->details,
            'quantity' => $request->quantity,
            'estimatedAmount' => (float) $request->estimated_amount,
            'evidence' => $request->evidence ?? [],
            'status' => ucfirst($request->status),
            'resolutionNote' => $request->resolution_note,
            'submittedAt' => optional($request->created_at)->toIso8601String(),
            'resolvedAt' => optional($request->resolved_at)->toIso8601String(),
        ];
    }
}
