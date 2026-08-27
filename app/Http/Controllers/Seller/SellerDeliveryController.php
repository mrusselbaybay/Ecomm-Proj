<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Backs resources/js/seller/components/Delivery.vue via
 * resources/js/seller/composables/useDeliveries.js.
 *
 * ---------------------------------------------------------------
 * SCOPE — read this before changing anything.
 *
 * This project's order status enum has exactly 5 values (see
 * Order::STATUSES): New, Processing, In Transit, Delivered, Cancelled.
 * There is no shipments, returns, or proof-of-delivery table anywhere
 * in the schema, no buyer-confirmation column, and no "delivery
 * attempt failed" concept. This controller deliberately does NOT
 * expose any of those — the frontend shows honest "not available"
 * states instead of fabricating them.
 *
 * This page covers orders that have reached or passed the shipping
 * stage: In Transit, Delivered, Cancelled. New/Processing orders are
 * Orders.vue/PrepareOrders.vue territory, not this page's.
 *
 * "Mark as Delivered" is NOT implemented here — it already exists via
 * SellerOrderController::updateStatus() (the In Transit -> Delivered
 * transition was already permitted by Order::ALLOWED_TRANSITIONS, and
 * useOrders.js's deliverOrder() already calls it) and this page's
 * Vue composable reuses that same endpoint rather than duplicating
 * status-change logic in two places.
 *
 * "Delivery Issues" = orders now Cancelled that were In Transit at
 * some point (a genuine "shipped, then went wrong" signal derived
 * from real order_status_history), not just any cancelled order —
 * an order cancelled before ever shipping isn't a delivery issue.
 *
 * updated_at is used as an approximation for "when this delivery
 * outcome happened" throughout (same caveat SellerOrderController
 * already documents) — there's no dedicated delivered_at column.
 *
 * Buyer exposure: only recipient_name + municipality/province (a
 * "delivery area", not a full address) are returned in the list —
 * intentionally less than SellerOrderController::show() exposes,
 * since this is a monitoring list, not the full order-details view.
 *
 * Multi-seller orders: orders.seller_id is a column on the order
 * itself (not per-item), so an order is single-seller by construction
 * in this schema — the "don't leak another seller's items" concern
 * the spec raised is structurally impossible to violate here, not
 * something this controller has to defend against separately.
 * ---------------------------------------------------------------
 */
class SellerDeliveryController extends Controller
{
    private const RELEVANT_STATUSES = ['In Transit', 'Delivered', 'Cancelled'];
    private const DEFAULT_PER_PAGE = 10;
    private const MAX_PER_PAGE = 50;
    private const MAX_RANGE_DAYS = 366;

    /**
     * GET /api/seller/deliveries
     *
     * Query params: search, status (all|in_transit|delivered|issues),
     * from, to (Y-m-d, optional), sort (updated_desc|updated_asc|
     * placed_desc, default updated_desc), page, per_page.
     *
     * Server-side paginated — never loads the seller's full delivery
     * history into one response.
     */
    public function index(Request $request): JsonResponse
    {
        $seller = $request->user();
        $range = $this->resolveOptionalRange($request);

        if ($range instanceof JsonResponse) {
            return $range;
        }

        $base = $this->baseQuery($seller->id, $request, $range);

        $statusCounts = [
            'all' => (clone $base)->count(),
            'inTransit' => (clone $base)->where('status', 'In Transit')->count(),
            'delivered' => (clone $base)->where('status', 'Delivered')->count(),
            'issues' => $this->applyIssuesFilter(clone $base)->count(),
        ];

        $query = $this->applyStatusFilter(clone $base, $request->string('status')->toString());

        match ($request->string('sort')->toString() ?: 'updated_desc') {
            'updated_asc' => $query->orderBy('updated_at'),
            'placed_desc' => $query->orderByDesc('placed_at'),
            default => $query->orderByDesc('updated_at'),
        };

        $perPage = min((int) ($request->integer('per_page') ?: self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
        $paginated = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (Order $o) => $this->transform($o))->all(),
            'meta' => [
                'currentPage' => $paginated->currentPage(),
                'lastPage' => $paginated->lastPage(),
                'perPage' => $paginated->perPage(),
                'total' => $paginated->total(),
                'statusCounts' => $statusCounts,
            ],
        ]);
    }

    /**
     * GET /api/seller/deliveries/summary
     *
     * The 3 summary cards. Deliberately ignores search/status filters
     * (describes the seller's whole relevant delivery population, like
     * SellerFeedbackController::summary() does), but DOES respect an
     * explicit date range if one was passed, since "Delivered Today"
     * only makes sense relative to *some* reference point.
     */
    public function summary(Request $request): JsonResponse
    {
        $seller = $request->user();
        $tz = config('app.timezone');
        $today = CarbonImmutable::now($tz);

        $deliveredToday = Order::where('seller_id', $seller->id)
            ->where('status', 'Delivered')
            ->whereDate('updated_at', $today->toDateString())
            ->count();

        $inTransit = Order::where('seller_id', $seller->id)
            ->where('status', 'In Transit')
            ->count();

        $issues = $this->applyIssuesFilter(
            Order::where('seller_id', $seller->id)->whereIn('status', self::RELEVANT_STATUSES)
        )->count();

        return response()->json([
            'data' => [
                'deliveredToday' => $deliveredToday,
                'inTransit' => $inTransit,
                'issues' => $issues,
                'timezone' => $tz,
                'generatedAt' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/seller/deliveries/export
     *
     * Same filters as index() (minus pagination), streamed as CSV.
     * Formula-injection-safe (leading =, +, -, @ get a forcing quote —
     * same approach as SellerReportController::csvSafe()). Excludes
     * buyer contact details not needed for delivery reporting.
     */
    public function export(Request $request): Response|JsonResponse
    {
        $seller = $request->user();
        $range = $this->resolveOptionalRange($request);

        if ($range instanceof JsonResponse) {
            return $range;
        }

        $query = $this->applyStatusFilter(
            $this->baseQuery($seller->id, $request, $range),
            $request->string('status')->toString(),
        );

        $orders = $query->orderByDesc('updated_at')->get();

        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['Order #', 'Status', 'Placed At', 'Last Updated', 'Delivery Area', 'Courier', 'Service', 'Tracking #']);

        foreach ($orders as $order) {
            fputcsv($handle, [
                $this->csvSafe($order->order_number),
                $this->csvSafe($order->status),
                $order->placed_at?->format('Y-m-d H:i'),
                $order->updated_at?->format('Y-m-d H:i'),
                $this->csvSafe(trim("{$order->shipping_municipality_name}, {$order->shipping_province_name}", ', ')),
                $this->csvSafe($order->shipping_carrier ?? ''),
                $this->csvSafe($order->shipping_service ?? ''),
                $this->csvSafe($order->tracking_number ?? ''),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $range = $range ?? [null, null];
        $filename = $range[0]
            ? sprintf('nexmart-deliveries-%s-to-%s.csv', $range[0]->toDateString(), $range[1]->toDateString())
            : 'nexmart-deliveries-' . now()->format('Y-m-d') . '.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Search (order #, product name, buyer name, tracking #, courier)
     * + optional date range applied, but NOT status — kept separate so
     * index() can compute all 4 status tab counts off the same base
     * (via clone), matching SellerFeedbackController's pattern.
     */
    private function baseQuery(string $sellerId, Request $request, ?array $range)
    {
        $query = Order::with(['items', 'statusHistory'])
            ->where('seller_id', $sellerId)
            ->whereIn('status', self::RELEVANT_STATUSES);

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'ilike', "%{$search}%")
                    ->orWhere('recipient_name', 'ilike', "%{$search}%")
                    ->orWhere('tracking_number', 'ilike', "%{$search}%")
                    ->orWhere('shipping_carrier', 'ilike', "%{$search}%")
                    ->orWhere('shipping_service', 'ilike', "%{$search}%")
                    ->orWhereHas('items', function ($iq) use ($search) {
                        $iq->where('product_name', 'ilike', "%{$search}%");
                    });
            });
        }

        if ($range) {
            $query->whereBetween('placed_at', $range);
        }

        return $query;
    }

    private function applyStatusFilter($query, ?string $status)
    {
        return match ($status) {
            'in_transit' => $query->where('status', 'In Transit'),
            'delivered' => $query->where('status', 'Delivered'),
            'issues' => $this->applyIssuesFilter($query),
            default => $query,
        };
    }

    /**
     * "Delivery Issues" = Cancelled AND was In Transit at some point —
     * a real shipped-then-cancelled signal from order_status_history,
     * not just any cancellation (many are cancelled before shipping).
     */
    private function applyIssuesFilter($query)
    {
        return $query->where('status', 'Cancelled')
            ->whereHas('statusHistory', fn ($q) => $q->where('status', 'In Transit'));
    }

    /**
     * Same shape as SellerReportController::resolveRange() but with
     * both ends optional — this page's date range is a real filter,
     * not a "defaults to last 30 days" requirement, since a seller
     * monitoring deliveries usually wants to see everything relevant
     * by default.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null|JsonResponse
     */
    private function resolveOptionalRange(Request $request): array|null|JsonResponse
    {
        if (!$request->filled('from') && !$request->filled('to')) {
            return null;
        }

        $tz = config('app.timezone');

        try {
            $to = $request->filled('to')
                ? CarbonImmutable::createFromFormat('Y-m-d', $request->string('to')->toString(), $tz)->endOfDay()
                : CarbonImmutable::now($tz)->endOfDay();

            $from = $request->filled('from')
                ? CarbonImmutable::createFromFormat('Y-m-d', $request->string('from')->toString(), $tz)->startOfDay()
                : $to->copy()->subDays(29)->startOfDay();
        } catch (\Exception) {
            return response()->json(['message' => 'Invalid date range.'], 422);
        }

        if ($to->lessThan($from)) {
            return response()->json(['message' => 'The end date must be on or after the start date.'], 422);
        }

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            return response()->json(['message' => 'Date range is too large. Please select '.self::MAX_RANGE_DAYS.' days or fewer.'], 422);
        }

        return [$from, $to];
    }

    private function transform(Order $order): array
    {
        return [
            'id' => '#'.$order->order_number,
            'status' => $order->status,
            'placedAt' => optional($order->placed_at)->toIso8601String(),
            'updatedAt' => optional($order->updated_at)->toIso8601String(),
            // "Delivery area" only — no street/barangay/house_no, unlike
            // the full order-details view. See class docblock.
            'deliveryArea' => trim("{$order->shipping_municipality_name}, {$order->shipping_province_name}", ', ') ?: null,
            'courier' => $order->shipping_carrier,
            'service' => $order->shipping_service,
            'trackingNumber' => $order->tracking_number,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->product_name,
                'variant' => $item->variant,
                'qty' => $item->quantity,
            ])->all(),
            // Real order_status_history events only — never fabricated.
            // Loaded eagerly per-row here (not with()'d on the base
            // query) because it's only needed for the small paginated
            // page, not the count queries above.
            'timeline' => $order->statusHistory->map(fn ($h) => [
                'status' => $h->status,
                'note' => $h->note,
                'at' => optional($h->created_at)->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * Same formula-injection guard as SellerReportController::csvSafe().
     */
    private function csvSafe(mixed $value): string
    {
        $value = (string) $value;

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }
}