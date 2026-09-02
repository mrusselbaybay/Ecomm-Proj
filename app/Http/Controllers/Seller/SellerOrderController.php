<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Services\InventoryService;
use App\Services\OrderTrackingService;
use App\Services\ParcelIntakeService;
use App\Services\SellerNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SellerOrderController extends Controller
{
    /**
     * Event-phrased labels for the order timeline on the details page.
     * (Order::STATUS_LABELS has the plain noun labels for badges.)
     */
    private const STATUS_LABELS = [
        'New' => 'Order Placed',
        'Confirmed' => 'Confirmed by seller',
        'Processing' => 'Preparing items',
        'Packed' => 'Packed',
        'Ready for Pickup' => 'Ready for pickup',
        'In Transit' => 'Shipped',
        'Delivered' => 'Delivered',
        'Cancelled' => 'Cancelled',
        'Rejected' => 'Rejected',
    ];

    private const SORTS = [
        'newest' => ['placed_at', 'desc'],
        'oldest' => ['placed_at', 'asc'],
        'total_high' => ['total', 'desc'],
        'total_low' => ['total', 'asc'],
    ];

    /**
     * GET /api/seller/orders
     *
     * Query: status?, search?, sort? (newest|oldest|total_high|total_low),
     *        page?, per_page?.
     *
     * Back-compatible: with no `page` it returns the full list (Orders.vue
     * still filters client-side). Pass `page` to switch to a paginated
     * response with meta.{currentPage,lastPage,total} — the eager loads
     * and indexes make either mode cheap. `meta.statusCounts` is always
     * computed across ALL the seller's orders, never just the page.
     */
    public function index(Request $request): JsonResponse
    {
        $seller = $request->user();

        $base = Order::where('seller_id', $seller->id);

        if ($search = $request->string('search')->toString()) {
            $base->where(function ($q) use ($search) {
                $q->where('order_number', 'ilike', "%{$search}%")
                    ->orWhere('recipient_name', 'ilike', "%{$search}%");
            });
        }

        $statusCounts = (clone $base)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        // No items.product here — product rows carry base64 images and
        // would bloat the list. show() loads them for the details page.
        $query = (clone $base)->with(['items', 'buyer.address']);

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        [$col, $dir] = self::SORTS[$request->string('sort')->toString()] ?? self::SORTS['newest'];
        $query->orderBy($col, $dir)->orderBy('order_number', 'desc');

        if ($request->filled('page')) {
            $perPage = min((int) ($request->integer('per_page') ?: 15), 50);
            $paginated = $query->paginate($perPage)->withQueryString();

            return response()->json([
                'data' => $paginated->getCollection()->map(fn (Order $o) => $this->transformSummary($o))->all(),
                'meta' => [
                    'currentPage' => $paginated->currentPage(),
                    'lastPage' => $paginated->lastPage(),
                    'perPage' => $paginated->perPage(),
                    'total' => $paginated->total(),
                    'statusCounts' => $statusCounts,
                ],
            ]);
        }

        return response()->json([
            'data' => $query->get()->map(fn (Order $order) => $this->transformSummary($order)),
            'meta' => ['statusCounts' => $statusCounts],
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

        $order = Order::with([
            'items.product:id,images',
            'items.productVariant:id,image',
            'buyer.address',
            'statusHistory.changedBy',
            'seller.address',
            'seller.sellerDetail',
        ])
            ->where('seller_id', $seller->id)
            ->where('order_number', ltrim($id, '#'))
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json(['data' => $this->transformDetail($order)]);
    }

    /**
     * GET /api/seller/orders/{id}/tracking
     *
     * Just the journey payload (OrderTrackingService) — a light endpoint
     * the Order Details map polls every few seconds while an order is in
     * transit, instead of refetching the whole order.
     */
    public function tracking(Request $request, string $id): JsonResponse
    {
        // parcelLocations is loaded lazily inside OrderTrackingService,
        // which guards against the table not existing yet.
        $order = Order::with(['statusHistory', 'seller.address', 'seller.sellerDetail'])
            ->where('seller_id', $request->user()->id)
            ->where('order_number', ltrim($id, '#'))
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json(['data' => (new OrderTrackingService)->journey($order)]);
    }

    /**
     * PUT /api/seller/orders/{id}/status
     *
     * All validation is server-side and the write runs in one
     * transaction:
     *   - the move must be in Order::ALLOWED_TRANSITIONS from the current
     *     status (no skipping / reversing);
     *   - a repeat of the same status is a no-op (no duplicate history
     *     row, stock restore or notification);
     *   - Cancelled / Rejected need a reason, are only allowed from the
     *     early stages (Order::SELLER_CANCELLABLE_FROM), restore any
     *     previously-deducted stock exactly once, and stamp who/when.
     * The status-changed notification is sent only after the commit.
     */
    public function updateStatus(UpdateOrderStatusRequest $request, string $id): JsonResponse
    {
        $seller = $request->user();

        $order = Order::where('seller_id', $seller->id)
            ->where('order_number', ltrim($id, '#'))
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $newStatus = $request->validated('status');
        $fromStatus = $order->status;
        $isCancelLike = in_array($newStatus, ['Cancelled', 'Rejected'], true);

        // Idempotent: same status in -> return the order unchanged.
        if ($fromStatus === $newStatus) {
            return response()->json(['data' => $this->transformDetail($this->reloadDetail($order))]);
        }

        if (! $order->canTransitionTo($newStatus)) {
            return response()->json([
                'message' => "This order can't move from \"{$order->statusLabel()}\" to \"".Order::labelFor($newStatus).'".',
            ], 422);
        }

        if ($isCancelLike && ! $order->sellerMayCancel()) {
            return response()->json([
                'message' => 'An order can only be cancelled or rejected while it is still Pending or Confirmed.',
            ], 422);
        }

        if ($isCancelLike && ! trim((string) $request->validated('reason'))) {
            return response()->json(['message' => 'A reason is required to cancel or reject an order.'], 422);
        }

        try {
            DB::transaction(function () use ($order, $newStatus, $fromStatus, $isCancelLike, $request, $seller) {
                // Lock the row so a concurrent change can't race this one.
                $order = Order::whereKey($order->id)->lockForUpdate()->first();

                if ($order->status !== $fromStatus) {
                    throw ValidationException::withMessages(['status' => 'This order was updated by someone else — reload and try again.']);
                }

                $reason = $request->validated('reason');

                $order->status = $newStatus;

                foreach (['tracking_number', 'shipping_carrier', 'shipping_service'] as $field) {
                    if ($request->filled($field)) {
                        $order->{$field} = $request->validated($field);
                    }
                }

                if ($isCancelLike) {
                    $order->cancellation_reason = $reason;
                    $order->cancelled_by = $seller->id;
                    $order->cancelled_at = now();

                    // Restores only what a 'sale' movement actually
                    // deducted, and only once per order (guarded inside
                    // InventoryService by the movement log).
                    app(InventoryService::class)->restoreForOrder($order, 'cancellation_restock', $seller->id);
                }

                $order->save();

                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'status' => $newStatus,
                    'previous_status' => $fromStatus,
                    'note' => $reason,
                    'changed_by' => $seller->id,
                ]);

                // The moment a seller hands a parcel to a registered
                // courier, put it in that company's sorting queue —
                // logistics shouldn't have to wait for someone to scan it
                // in before it even shows up. No-op if the carrier text
                // doesn't match an active LogisticsCompany (free-text
                // carriers pre-date the dropdown, see CourierHandover.vue).
                if ($newStatus === 'In Transit') {
                    $parcelIntake = app(ParcelIntakeService::class);
                    $company = $parcelIntake->findActiveCompanyByName($order->shipping_carrier);

                    if ($company) {
                        $parcelIntake->intake($order, $company);
                    }
                }
            });
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }

        $order = $this->reloadDetail($order);

        // After the commit only (spec).
        app(SellerNotifier::class)->orderStatusChanged($order, $fromStatus, $newStatus, 'the seller');

        return response()->json(['data' => $this->transformDetail($order)]);
    }

    private function reloadDetail(Order $order): Order
    {
        return $order->fresh([
            'items.product:id,images',
            'items.productVariant:id,image',
            'buyer.address',
            'statusHistory.changedBy',
            'seller.address',
            'seller.sellerDetail',
        ]);
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
            'id' => '#'.$order->order_number,
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
            'statusLabel' => $order->statusLabel(),
            'isTerminal' => $order->isTerminal(),
            // Fulfilment vs. money are separate concerns — see the spec.
            // Sellers never write payment status; this is display only.
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
                // Snapshot fields — these come from order_items, NOT the
                // live product, so editing the product later never
                // changes a past order.
                'name' => $item->product_name,
                'category' => $item->category,
                'sku' => $item->sku,
                'variantSku' => $item->variant_sku,
                'variant' => $item->variant,
                'variantOptions' => $item->variant_options,
                'qty' => $item->quantity,
                'price' => (float) $item->unit_price,
                'subtotal' => (float) ($item->subtotal ?? $item->unit_price * $item->quantity),
                // Image is the one field with no snapshot column — pulled
                // from the current product/variant when it still exists.
                'image' => $this->itemImage($item),
            ])->all(),
        ];
    }

    /**
     * First renderable image for an order item, or null. Only looks at
     * eager-loaded relations (show() loads them; the list does not, so it
     * gets null rather than an N+1 of base64 blobs).
     */
    private function itemImage(OrderItem $item): ?string
    {
        if ($item->relationLoaded('productVariant') && is_array($item->productVariant?->image ?? null)) {
            $fromVariant = $item->productVariant->image['url'] ?? null;

            if ($fromVariant) {
                return $fromVariant;
            }
        }

        if ($item->relationLoaded('product')) {
            $images = $item->product?->images ?? [];

            return $images[0]['url'] ?? ($images[0] ?? null);
        }

        return null;
    }

    private function transformDetail(Order $order): array
    {
        $summary = $this->transformSummary($order);

        // Storefront identity for the printable receipt header.
        $summary['seller'] = [
            'name' => $order->seller?->sellerDetail?->business_name ?? 'Store',
            'city' => $order->seller?->address?->municipality_name,
            'province' => $order->seller?->address?->province_name,
        ];

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
            'status' => $h->status,
            'previousStatus' => $h->previous_status,
            'time' => $h->created_at?->format('F d, Y \a\t h:i A'),
            'at' => optional($h->created_at)->toIso8601String(),
            'actor' => $h->changedBy?->full_name,
            'done' => true,
            'detail' => $h->note,
        ])->all();

        $summary['cancellation'] = $order->cancellation_reason ? [
            'reason' => $order->cancellation_reason,
            'by' => $order->cancelled_by,
            'at' => optional($order->cancelled_at)->toIso8601String(),
        ] : null;

        // What the seller may do next (drives the action buttons).
        $summary['nextStatuses'] = collect(Order::ALLOWED_TRANSITIONS[$order->status] ?? [])
            ->map(fn ($s) => ['value' => $s, 'label' => Order::labelFor($s)])
            ->values()
            ->all();
        $summary['canCancel'] = $order->sellerMayCancel();

        // Estimated-position tracking map data (shared with the buyer
        // Order Details screen). See OrderTrackingService — the parcel
        // position is derived from order status, not a live feed.
        $summary['journey'] = (new OrderTrackingService)->journey($order);

        return $summary;
    }
}
