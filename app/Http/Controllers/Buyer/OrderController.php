<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ProductImageController;
use App\Models\Order;
use App\Models\OrderReturnRequest;
use App\Services\OrderCancellationService;
use App\Support\OrderStage;
use App\Support\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    /**
     * Item photos come with the order (no request per product): the
     * product and the purchased variant, each with only the columns a
     * photo needs and inline photos replaced by image-endpoint links
     * (ProductImage::liteSql()).
     *
     * @return array<int|string, mixed>
     */
    private function with(): array
    {
        return [
            'items.review',
            'items.returnRequests',
            'items.product' => fn ($q) => ProductImage::selectWithLiteImages($q, ['products.id', 'products.updated_at']),
            'items.productVariant' => fn ($q) => ProductImage::selectVariantWithLiteImage($q, ['product_variants.id', 'product_variants.product_id']),
            'seller.sellerDetail',
            'statusHistory',
        ];
    }

    /**
     * GET /api/buyer/orders
     */
    public function index(Request $request): JsonResponse
    {
        $buyer = $request->user();

        $orders = Order::with($this->with())
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
        $order = $this->findForBuyer($request, $id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json(['data' => $this->transform($order)]);
    }

    /**
     * POST /api/buyer/orders/{id}/cancel
     *
     * Buyer-initiated cancellation, allowed only while the order is still
     * "New". Restores stock and writes the same order_status_history entry
     * the seller backend expects — see OrderCancellationService.
     */
    public function cancel(Request $request, string $id, OrderCancellationService $service): JsonResponse
    {
        $order = $this->findForBuyer($request, $id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $reason = $request->input('reason');

        if ($reason !== null && ! is_string($reason)) {
            return response()->json(['message' => 'Invalid cancellation reason.'], 422);
        }

        try {
            $order = $service->cancel($request->user(), $order, $reason ? mb_substr($reason, 0, 500) : null);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'This order can no longer be cancelled.',
            ], 422);
        }

        return response()->json(['data' => $this->transform($order)]);
    }

    private function findForBuyer(Request $request, string $id): ?Order
    {
        return Order::with($this->with())
            ->where('buyer_profile_id', $request->user()->id)
            ->where('order_number', ltrim($id, '#'))
            ->first();
    }

    private function transform(Order $order): array
    {
        $sellerName = $order->seller?->sellerDetail?->business_name
            ?? $order->seller?->full_name
            ?? 'BuyTheWay Seller';

        $addressLine = collect([
            $order->shipping_house_no,
            $order->shipping_street,
            $order->shipping_barangay,
            $order->shipping_municipality_name,
            $order->shipping_province_name,
        ])->filter()->implode(', ');

        return [
            'orderId' => '#'.$order->order_number,
            'createdAt' => optional($order->placed_at)->toIso8601String(),
            'status' => $order->status,
            // My Orders tab (OrderStage): to_pay | to_ship | to_receive |
            // completed | cancelled | return_refund. `status` is unchanged.
            'stage' => OrderStage::for($order),
            'seller_id' => $order->seller_id,
            'seller' => $sellerName,
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
                // The exact variant bought, for Buy Again (never substituted).
                'variant_id' => $item->variant_id,
                'image' => $this->itemImage($item),
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'review' => $item->review ? [
                    'id' => $item->review->id,
                    'rating' => $item->review->rating,
                    'comment' => $item->review->comment,
                    'images' => collect(is_array($item->review->images) ? $item->review->images : [])
                        ->filter(fn ($url) => is_string($url) && $url !== '')->values()->all(),
                    'createdAt' => optional($item->review->created_at)->toIso8601String(),
                    'updatedAt' => optional($item->review->updated_at)->toIso8601String(),
                    'isEdited' => (bool) ($item->review->updated_at && $item->review->created_at
                        && ! $item->review->updated_at->equalTo($item->review->created_at)),
                ] : null,
                // Most recent return/refund request for this line item, if
                // any — shape matches OrderDetails.vue's `item.returnRequest`
                // block (requestType/status/reason/quantity/evidence/details).
                'returnRequest' => $this->transformReturnRequest(
                    $item->returnRequests->sortByDesc('created_at')->first()
                ),
            ]),
        ];
    }

    /**
     * The purchased variant's photo, else the product's first photo, else
     * null (the page shows its placeholder). Inline photos are card-sized
     * image-endpoint links.
     */
    private function itemImage($item): ?string
    {
        $product = $item->product;
        $variant = $item->productVariant;

        if ($variant) {
            $url = ProductImage::normalize($variant->image, fn () => ProductImage::inlineUrl(
                (string) $variant->product_id,
                0,
                $product?->updated_at,
                ProductImageController::CARD_WIDTH,
                (string) $variant->id,
            ));

            if ($url !== null) {
                return $url;
            }
        }

        return $product ? ProductImage::cardUrl($product) : null;
    }

    private function transformReturnRequest(?OrderReturnRequest $request): ?array
    {
        if (! $request) {
            return null;
        }

        return [
            'id' => $request->id,
            'requestType' => $request->request_type,
            'reason' => $request->reason,
            'details' => $request->details,
            'quantity' => $request->quantity,
            'evidence' => $request->evidence ?? [],
            'status' => ucfirst($request->status),
            'submittedAt' => optional($request->created_at)->toIso8601String(),
        ];
    }
}
