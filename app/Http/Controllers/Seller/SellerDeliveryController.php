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
 * "Mark as Delivered" is NOT implemented here, or anywhere a seller can
 * reach — SellerOrderController::updateStatus() rejects a seller trying
 * to set 'Delivered' directly (see Order::SELLER_SETTABLE_STATUSES): a
 * seller declaring their own order delivered isn't a real confirmation
 * of anything. It's set automatically instead, either by
 * app/Console/Commands/AutoDeliverStaleOrders.php (In Transit 7+ days
 * with no confirmation) or eventually a buyer-side confirmation.
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
 * Delivery duration ("2.1 days", courier avg speed, on-time rate) is a
 * real computed value: order_status_history has one real row per
 * transition, so "when this order actually became In Transit" and
 * "when it actually became Delivered" both exist as real timestamps —
 * see durationsFor(). "On time" has no real backing anywhere in this
 * schema (no SLA / promised-delivery-date column exists), so it's a
 * disclosed, fixed threshold applied to that real duration
 * (ON_TIME_THRESHOLD_DAYS below), the same kind of business-rule
 * cutoff useSellerProducts.js's LOW_STOCK_THRESHOLD already uses on
 * real stock counts — not fabricated data, a stated rule over it.
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

    // Disclosed business rule, not a real SLA (see class docblock) — a
    // delivered order whose real In-Transit -> Delivered duration is at
    // or under this many days counts as "on time".
    private const ON_TIME_THRESHOLD_DAYS = 3.0;

    // How many rows the Delivery Issues sidebar shows.
    private const ISSUES_LIMIT = 5;

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
     * The 4 summary cards. Deliberately ignores search/status filters
     * (describes the seller's whole relevant delivery population, like
     * SellerFeedbackController::summary() does), but DOES respect an
     * explicit date range if one was passed, since "Delivered This
     * Week" only makes sense relative to *some* reference point.
     */
    public function summary(Request $request): JsonResponse
    {
        $seller = $request->user();
        $tz = config('app.timezone');
        $now = CarbonImmutable::now($tz);

        $range = $this->resolveOptionalRange($request);

        if ($range instanceof JsonResponse) {
            return $range;
        }

        $deliveredThisWeekQuery = Order::where('seller_id', $seller->id)
            ->where('status', 'Delivered');

        if ($range) {
            // An explicit range replaces the default trailing-7-days
            // window with the picker's own window (see this method's
            // docblock) — same placed_at scoping the rest of this page
            // uses (baseQuery(), courierPerformance(), issues()).
            $deliveredThisWeekQuery->whereBetween('placed_at', $range);
        } else {
            $deliveredThisWeekQuery->where('updated_at', '>=', $now->subDays(7));
        }

        $deliveredThisWeek = $deliveredThisWeekQuery->count();

        $inTransitQuery = Order::where('seller_id', $seller->id)->where('status', 'In Transit');

        if ($range) {
            $inTransitQuery->whereBetween('placed_at', $range);
        }

        $inTransit = $inTransitQuery->count();

        $issuesQuery = $this->applyIssuesFilter(
            Order::where('seller_id', $seller->id)->whereIn('status', self::RELEVANT_STATUSES)
        );

        if ($range) {
            $issuesQuery->whereBetween('placed_at', $range);
        }

        $issues = $issuesQuery->count();

        $onTimeQuery = Order::with('statusHistory')->where('seller_id', $seller->id)->where('status', 'Delivered');

        if ($range) {
            $onTimeQuery->whereBetween('placed_at', $range);
        }

        $onTimeRate = $this->onTimeRateFor($onTimeQuery->get());

        return response()->json([
            'data' => [
                'deliveredThisWeek' => $deliveredThisWeek,
                'inTransit' => $inTransit,
                'issues' => $issues,
                'onTimeRate' => $onTimeRate,
                'onTimeThresholdDays' => self::ON_TIME_THRESHOLD_DAYS,
                'timezone' => $tz,
                'generatedAt' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/seller/deliveries/courier-performance
     *
     * One row per real shipping_carrier value seen on this seller's
     * shipped orders (In Transit/Delivered/Cancelled — same population
     * as the rest of this page), within the same optional date range
     * the toolbar's picker applies. "insight" is a real, conditional
     * sentence generated only when one courier is genuinely worse than
     * every other courier on both avg speed and on-time rate — there is
     * no insight line at all when the data doesn't support one that
     * clearly, rather than always forcing a generic sentence.
     */
    public function courierPerformance(Request $request): JsonResponse
    {
        $seller = $request->user();
        $range = $this->resolveOptionalRange($request);

        if ($range instanceof JsonResponse) {
            return $range;
        }

        $query = Order::with('statusHistory')
            ->where('seller_id', $seller->id)
            ->whereIn('status', self::RELEVANT_STATUSES)
            ->whereNotNull('shipping_carrier')
            ->where('shipping_carrier', '!=', '');

        if ($range) {
            $query->whereBetween('placed_at', $range);
        }

        $rows = $query->get()->groupBy('shipping_carrier')->map(function ($orders, $courier) {
            $delivered = $orders->where('status', 'Delivered');
            $issues = $this->countIssues($orders);
            $onTime = $this->onTimeRateFor($delivered);
            $avgDays = $this->avgDeliveryDays($delivered);

            return [
                'courier' => $courier,
                'shipments' => $orders->count(),
                'avgDeliveryDays' => $avgDays,
                'onTimeRate' => $onTime,
                'issues' => $issues,
            ];
        })->sortByDesc('shipments')->values();

        return response()->json([
            'data' => $rows->all(),
            'meta' => [
                'insight' => $this->courierInsight($rows),
                'onTimeThresholdDays' => self::ON_TIME_THRESHOLD_DAYS,
            ],
        ]);
    }

    /**
     * GET /api/seller/deliveries/issues
     *
     * Most recent real delivery issues (see applyIssuesFilter — shipped,
     * then cancelled) with the seller's own real cancellation_reason
     * text, for the sidebar list. Same optional date range as the rest
     * of the page.
     */
    public function issues(Request $request): JsonResponse
    {
        $seller = $request->user();
        $range = $this->resolveOptionalRange($request);

        if ($range instanceof JsonResponse) {
            return $range;
        }

        $query = $this->applyIssuesFilter(
            Order::where('seller_id', $seller->id)->whereIn('status', self::RELEVANT_STATUSES)
        );

        if ($range) {
            $query->whereBetween('placed_at', $range);
        }

        $orders = $query->orderByDesc('cancelled_at')->limit(self::ISSUES_LIMIT)->get();

        return response()->json([
            'data' => $orders->map(fn (Order $o) => [
                'id' => '#'.$o->order_number,
                'courier' => $o->shipping_carrier,
                'reason' => $o->cancellation_reason,
                'cancelledAt' => optional($o->cancelled_at)->toIso8601String(),
            ])->all(),
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
            ? sprintf('buytheway-deliveries-%s-to-%s.csv', $range[0]->toDateString(), $range[1]->toDateString())
            : 'buytheway-deliveries-' . now()->format('Y-m-d') . '.csv';

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
            'customer' => $order->recipient_name,
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
            // Real durations from those same history rows — see
            // durationsFor() and the class docblock re: ON_TIME_THRESHOLD_DAYS.
            ...$this->durationsFor($order),
        ];
    }

    /**
     * Real shipped-at / delivered-at timestamps for one order, read from
     * its own status history (not `updated_at`, which only reflects the
     * *last* change — useless for "how long did shipping take" once an
     * order has moved past Delivered). `deliveryDays` and `onTime` are
     * both null until the order has actually reached Delivered with a
     * real In-Transit timestamp preceding it.
     */
    private function durationsFor(Order $order): array
    {
        $shippedAt = optional($order->statusHistory->firstWhere('status', 'In Transit'))->created_at;
        $deliveredAt = optional($order->statusHistory->firstWhere('status', 'Delivered'))->created_at;

        $deliveryDays = ($shippedAt && $deliveredAt)
            ? round($shippedAt->diffInMinutes($deliveredAt) / 1440, 1)
            : null;

        return [
            'shippedAt' => optional($shippedAt)->toIso8601String(),
            'deliveredAt' => optional($deliveredAt)->toIso8601String(),
            'deliveryDays' => $deliveryDays,
            'onTime' => $deliveryDays === null ? null : $deliveryDays <= self::ON_TIME_THRESHOLD_DAYS,
        ];
    }

    /**
     * Average real delivery duration across a collection of Delivered
     * orders (statusHistory must already be loaded on each). Null when
     * none of them have a computable duration.
     */
    private function avgDeliveryDays($deliveredOrders): ?float
    {
        $days = $deliveredOrders
            ->map(fn (Order $o) => $this->durationsFor($o)['deliveryDays'])
            ->filter(fn ($d) => $d !== null);

        return $days->isEmpty() ? null : round($days->avg(), 1);
    }

    /**
     * % of a collection of Delivered orders whose real duration is at
     * or under ON_TIME_THRESHOLD_DAYS. Null when none have a computable
     * duration, rather than a misleading 0%.
     */
    private function onTimeRateFor($deliveredOrders): ?float
    {
        $withDuration = $deliveredOrders
            ->map(fn (Order $o) => $this->durationsFor($o))
            ->filter(fn ($d) => $d['onTime'] !== null);

        if ($withDuration->isEmpty()) {
            return null;
        }

        return (int) round($withDuration->filter(fn ($d) => $d['onTime'])->count() / $withDuration->count() * 100);
    }

    /**
     * In-memory equivalent of applyIssuesFilter()'s definition, for a
     * collection of already-loaded orders (courierPerformance() groups
     * orders in memory rather than re-querying per courier).
     */
    private function countIssues($orders): int
    {
        return $orders->filter(
            fn (Order $o) => $o->status === 'Cancelled'
                && $o->statusHistory->contains(fn ($h) => $h->status === 'In Transit'),
        )->count();
    }

    /**
     * A real, conditional call-out — only produced when one courier is
     * genuinely worse than every other courier on BOTH avg delivery
     * speed and on-time rate (not just worst on one, or a near-tie).
     * Requires at least 2 shipments and a computable duration to be
     * eligible, so one bad early shipment can't skew a new courier's
     * whole reputation. No insight at all when the data doesn't
     * support one this clearly — never a forced generic sentence.
     */
    private function courierInsight($rows): ?string
    {
        $eligible = $rows->filter(
            fn ($r) => $r['shipments'] >= 2 && $r['avgDeliveryDays'] !== null && $r['onTimeRate'] !== null,
        );

        if ($eligible->count() < 2) {
            return null;
        }

        $worst = $eligible->sortByDesc('avgDeliveryDays')->first();

        if ($eligible->sortBy('onTimeRate')->first()['courier'] !== $worst['courier']) {
            return null;
        }

        $others = $eligible->reject(fn ($r) => $r['courier'] === $worst['courier']);
        $isWorseThanAll = $others->every(
            fn ($r) => $r['avgDeliveryDays'] < $worst['avgDeliveryDays'] && $r['onTimeRate'] > $worst['onTimeRate'],
        );

        if (! $isWorseThanAll) {
            return null;
        }

        return "{$worst['courier']} is trending below your other couriers on both speed and reliability — worth a check-in if it continues.";
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