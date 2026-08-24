<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\CheckoutRequest;
use App\Models\Order;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutService $checkoutService)
    {
    }

    /**
     * POST /api/buyer/checkout
     *
     * Creates one order per seller represented in the cart, revalidating
     * price/stock server-side, and returns the created orders.
     */
    public function store(CheckoutRequest $request): JsonResponse
    {
        try {
            $orders = $this->checkoutService->checkout($request->user(), $request->validated());
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Checkout failed.',
            ], 422);
        }

        return response()->json([
            'data' => $orders->map(fn (Order $order) => [
                'id' => '#' . $order->order_number,
                'seller_id' => $order->seller_id,
                'status' => $order->status,
                'total' => (float) $order->total,
                'items' => $order->items->map(fn ($item) => [
                    'name' => $item->product_name,
                    'qty' => $item->quantity,
                    'price' => (float) $item->unit_price,
                    'variant' => $item->variant,
                ]),
            ]),
        ], 201);
    }
}