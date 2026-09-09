<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Services\InventoryService;
use App\Services\OrderTrackingService;
use App\Services\SellerNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
     * Query: status?, search?, payment_status?, date_from?, date_to?,
     *        sort? (newest|oldest|total_high|total_low), page?, per_page?.
     *
     * Back-compatible: with no `page` it returns the full list (older
     * callers filter client-side). Pass `page` to switch to a paginated
     * response with meta.{currentPage,lastPage,total} — the eager loads
     * and indexes make either mode cheap. `meta.statusCounts` is computed
     * across every order that matches the search / payment / date filters
     * (but NOT the status filter), so the summary cards and the status
     * tabs always agree on the same population.
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

        // Payment status is display-only for sellers, but they still need
        // to filter by it (e.g. "show me the unpaid COD orders"). With
        // pagination on, this can't be done client-side.
        if ($paymentStatus = $request->string('payment_status')->toString()) {
            $base->where('payment_status', $paymentStatus);
        }

        if ($from = $request->string('date_from')->toString()) {
            $base->whereDate('placed_at', '>=', $from);
        }

        if ($to = $request->string('date_to')->toString()) {
            $base->whereDate('placed_at', '<=', $to);
        }

        $statusCounts = (clone $base)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        // Real product photos were tried here for the Orders kanban card
        // thumbnail (items.product:id,images, opt-in via ?with_thumbnails)
        // but a `products` query filtered by `WHERE id IN (...)` while
        // selecting the `images` column hangs indefinitely against this
        // Supabase instance — confirmed directly via `php artisan tinker`
        // with the web server, browser, and any concurrency ruled out
        // (SELECT id, images FROM products alone works; the same select
        // with a literal or bound WHERE id IN (...) never returns). Not
        // safe to eager-load here; the kanban card uses a generic icon
        // instead (see OrderCard.vue).
        $with = ['items', 'buyer.address'];

        // Return/refund rollup for the list badge (returnStatusFor()).
        // Guarded so the page still works before that migration is run.
        if (Schema::hasTable('order_return_requests')) {
            $with['returnRequests'] = fn ($q) => $q->select('id', 'order_id', 'status');
        }

        $query = (clone $base)->with($with);

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

        $order = Order::with($this->detailRelations())
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

        // Delivered is the one real status a seller can never set directly
        // through this endpoint (see Order::SELLER_SETTABLE_STATUSES) — a
        // seller declaring their own order delivered isn't a real
        // confirmation of anything. It's set automatically instead (see
        // AutoDeliverStaleOrders), or by a future buyer confirmation.
        if (! $order->sellerMaySet($newStatus)) {
            return response()->json([
                'message' => Order::labelFor($newStatus).' is set automatically, not by the seller — '
                    .'it clears on its own once the order is confirmed delivered or has been in transit long enough.',
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

                foreach (['shipping_carrier', 'shipping_service'] as $field) {
                    if ($request->filled($field)) {
                        $order->{$field} = $request->validated($field);
                    }
                }

                // Tracking number is never taken from the request — a
                // seller typing an arbitrary string isn't a real AWB, and
                // the buyer trusts this number to actually track their
                // parcel. It's generated here, once, at the real moment
                // the order is actually handed to a courier (In Transit),
                // and left alone on every other transition so it's never
                // silently regenerated later.
                if ($newStatus === 'In Transit' && ! $order->tracking_number) {
                    $order->tracking_number = $this->generateTrackingNumber();
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
        return $order->fresh($this->detailRelations());
    }

    /**
     * A real, unique AWB-style tracking number — BTW (BuyTheWay) +
     * today's date + 6 random base-32 characters (Str::random's default
     * pool minus visually-ambiguous 0/O/1/I would be nicer, but plain
     * upper-alphanumeric keeps this simple and still collision-checked
     * against every tracking number ever issued before it's accepted).
     */
    private function generateTrackingNumber(): string
    {
        do {
            $candidate = 'BTW'.now()->format('ymd').strtoupper(Str::random(6));
        } while (Order::where('tracking_number', $candidate)->exists());

        return $candidate;
    }

    /**
     * Eager loads for a single order's detail view. `returnRequests` is
     * guarded so the details page still works before the returns
     * migration has run.
     */
    private function detailRelations(): array
    {
        $relations = [
            'items.product:id,images',
            'items.variant:id,image',
            'buyer.address',
            'statusHistory.changedBy',
            'seller.address',
            'seller.sellerDetail',
        ];

        if (Schema::hasTable('order_return_requests')) {
            $relations['returnRequests'] = fn ($q) => $q->select('id', 'order_id', 'status');
        }

        return $relations;
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
            // Workflow-aware actions, straight from the model — the list
            // and the details page both render buttons from this, so
            // Order::ALLOWED_TRANSITIONS stays the single source of truth
            // (no mirror in JS). Cheap: no journey/tracking work here.
            'canCancel' => $order->sellerMayCancel(),
            'nextStatuses' => collect(Order::ALLOWED_TRANSITIONS[$order->status] ?? [])
                ->map(fn ($s) => ['value' => $s, 'label' => Order::labelFor($s)])
                ->values()
                ->all(),
            // Rollup of any buyer return/refund requests, or null.
            'returnStatus' => $this->returnStatusFor($order),
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
                // Always null here: itemImage() only returns something
                // when product/variant were eager-loaded, and this list
                // deliberately doesn't (see the $with comment in index()
                // above — selecting products.images filtered by
                // WHERE id IN (...) hangs against this DB). The Orders
                // kanban card shows a generic icon instead of a photo.
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
        if ($item->relationLoaded('variant') && is_array($item->variant?->image ?? null)) {
            $fromVariant = $item->variant->image['url'] ?? null;

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

    /**
     * One-word rollup of an order's buyer return/refund requests for the
     * list badge, or null when there are none. Reads only the
     * eager-loaded relation (guarded), so it never fires a query per row.
     *
     *   requested -> a request is still pending review
     *   approved  -> at least one approved, none completed yet
     *   returned  -> at least one completed (item back / refund done)
     *   rejected  -> only rejected requests remain
     */
    private function returnStatusFor(Order $order): ?string
    {
        if (! $order->relationLoaded('returnRequests') || $order->returnRequests->isEmpty()) {
            return null;
        }

        $statuses = $order->returnRequests->pluck('status');

        return match (true) {
            $statuses->contains('completed') => 'returned',
            $statuses->contains('approved') => 'approved',
            $statuses->contains('pending') => 'requested',
            $statuses->contains('rejected') => 'rejected',
            default => null,
        };
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

        // `nextStatuses` / `canCancel` / `returnStatus` already come from
        // transformSummary() — the details page reads the same keys.

        // Estimated-position tracking map data (shared with the buyer
        // Order Details screen). See OrderTrackingService — the parcel
        // position is derived from order status, not a live feed.
        $summary['journey'] = (new OrderTrackingService)->journey($order);

        return $summary;
    }
}
