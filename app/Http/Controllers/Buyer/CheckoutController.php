<?php

namespace App\Http\Controllers\Buyer;

use App\Exceptions\CheckoutQuoteChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\CheckoutRequest;
use App\Models\Order;
use App\Models\SellerDetail;
use App\Services\CheckoutService;
use App\Support\CheckoutOptions;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    /** How long a placed checkout's response is kept for repeats. */
    private const IDEMPOTENCY_TTL_HOURS = 24;

    public function __construct(private readonly CheckoutService $checkoutService) {}

    /**
     * POST /api/buyer/checkout/quote
     *
     * Prices the checkout from the database without changing anything:
     * every line at today's price with whether (and why not) it can be
     * bought, shipping options and fee per seller, and the totals. The
     * checkout page shows only these numbers.
     */
    public function quote(CheckoutRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->checkoutService->quote($request->user(), $request->validated())]);
    }

    /**
     * POST /api/buyer/checkout
     *
     * Creates one order per seller, revalidating price, stock and
     * availability under row locks.
     *
     *   422  a line can't be bought (the message names it), or invalid input
     *   409  code=quote_changed: the total differs from expected_total —
     *        nothing was ordered; `quote` holds the new amounts
     *   409  code=in_progress: the same checkout is already being placed
     *
     * With an idempotency_key, a repeat of a checkout that already went
     * through returns the same orders (200) instead of creating more.
     */
    public function store(CheckoutRequest $request): JsonResponse
    {
        $buyer = $request->user();
        $key = $request->validated('idempotency_key');

        if (! $key) {
            return $this->place($request);
        }

        $cacheKey = "checkout:{$buyer->id}:{$key}";

        if ($previous = Cache::get($cacheKey)) {
            return response()->json($previous, 200);
        }

        $lock = Cache::lock("{$cacheKey}:lock", 60);

        try {
            $lock->block(0);
        } catch (LockTimeoutException) {
            return response()->json([
                'code' => 'in_progress',
                'message' => 'Your order is already being placed. Check My Orders in a moment.',
            ], 409);
        }

        try {
            // Re-check after taking the lock: a first request may have
            // finished in between.
            if ($previous = Cache::get($cacheKey)) {
                return response()->json($previous, 200);
            }

            $response = $this->place($request);

            if ($response->getStatusCode() === 201) {
                Cache::put($cacheKey, $response->getData(true), now()->addHours(self::IDEMPOTENCY_TTL_HOURS));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function place(CheckoutRequest $request): JsonResponse
    {
        $payload = $request->validated();

        try {
            $orders = $this->checkoutService->checkout($request->user(), $payload);
        } catch (CheckoutQuoteChanged $e) {
            return response()->json([
                'code' => 'quote_changed',
                'message' => $e->getMessage(),
                'quote' => $e->quote,
            ], 409);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Checkout failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        $storeNames = SellerDetail::query()
            ->whereIn('profile_id', $orders->pluck('seller_id'))
            ->pluck('business_name', 'profile_id');

        return response()->json([
            'data' => $orders->map(fn (Order $order) => $this->transform($order, $storeNames[$order->seller_id] ?? 'BuyTheWay Seller'))->values(),
        ], 201);
    }

    /**
     * What the confirmation page shows for one created order.
     *
     * @return array<string, mixed>
     */
    private function transform(Order $order, string $storeName): array
    {
        $shipping = CheckoutOptions::SHIPPING[$order->shipping_service] ?? null;

        return [
            'id' => '#'.$order->order_number,
            'seller_id' => $order->seller_id,
            'store_name' => $storeName,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'payment_method_name' => CheckoutOptions::PAYMENT_METHODS[$order->payment_method]['name'] ?? $order->payment_method,
            'payment_status' => $order->payment_status,
            'subtotal' => (float) $order->subtotal,
            'shipping_fee' => (float) $order->shipping_fee,
            'discount' => (float) $order->discount,
            'total' => (float) $order->total,
            'shipping' => [
                'method' => $order->shipping_service,
                'name' => $shipping['name'] ?? $order->shipping_service,
                'eta' => $shipping['eta'] ?? null,
            ],
            'delivery_address' => [
                'recipient_name' => $order->recipient_name,
                'contact_number' => $order->recipient_contact_no,
                'address' => $order->shipping_street,
            ],
            'placed_at' => optional($order->placed_at)->toIso8601String(),
            'items' => $order->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'name' => $item->product_name,
                'qty' => $item->quantity,
                'price' => (float) $item->unit_price,
                'line_total' => (float) $item->subtotal,
                'variant' => $item->variant,
            ])->values(),
        ];
    }
}
