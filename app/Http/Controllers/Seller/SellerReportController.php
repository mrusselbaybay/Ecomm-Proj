<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Services\SellerReportService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\Constants\UnitValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Backs resources/js/seller/components/Reports.vue via
 * resources/js/seller/composables/useReports.js.
 *
 * Every query is scoped by seller_id (same pattern as
 * SellerOrderController/SellerFeedbackController) so a seller can only
 * ever see or export their own performance data — never another
 * seller's, regardless of what id/date range is passed in.
 *
 * ---------------------------------------------------------------
 * METRIC DEFINITIONS (see also the CHANGES/summary shared with the
 * seller alongside this file):
 *
 * - Eligible revenue orders = status = 'Delivered' within range.
 *   New/Processing/In Transit haven't reached an outcome yet and
 *   Cancelled never earned revenue, so neither counts.
 * - Gross revenue = SUM(total) over eligible orders.
 * - Net revenue = Gross revenue minus SUM(total) of eligible orders
 *   where payment_status = 'Refunded'.
 * - Average order value = Gross revenue / count(eligible orders).
 * - Fulfillment rate = Delivered / (Delivered + Cancelled). Orders
 *   still New/Processing/In Transit are excluded from the denominator
 *   since they haven't reached an outcome — including them would
 *   understate the rate for a seller who's simply mid-cycle.
 * - Cancellation rate = Cancelled / (Delivered + Cancelled).
 * - Refund rate = orders with payment_status = 'Refunded' / Delivered
 *   orders. NOTE: this project has no itemized refunds/returns table,
 *   only the order-level payment_status enum — so this is a coarse
 *   proxy, not a per-item refund rate. The frontend surfaces that
 *   caveat rather than presenting it as more precise than it is.
 * - Average rating = AVG(reviews.rating) where reviews.seller_id =
 *   seller, review created_at in range.
 *
 * Every trend/comparison figure is computed against the immediately
 * preceding period of equal length, and is OMITTED (not shown as 0%
 * or a misleading number) when that prior period has zero eligible
 * orders/reviews — same null-when-insufficient pattern as
 * SellerFeedbackController::computeTrend().
 *
 * Timezone: this project has no per-seller timezone field, so all
 * date-range boundaries use the app's configured timezone
 * (config('app.timezone'), currently UTC) consistently, applied via
 * Carbon rather than per-request.
 * ---------------------------------------------------------------
 */
class SellerReportController extends Controller
{
    private const DEFAULT_RANGE_DAYS = 30;

    private const MAX_RANGE_DAYS = 366;

    private const TOP_PRODUCTS_DEFAULT_LIMIT = 10;

    private const TOP_PRODUCTS_MAX_LIMIT = 50;

    /**
     * GET /api/seller/reports/summary
     *
     * Query params: from, to (Y-m-d, optional — defaults to the last 30
     * days). Returns the KPI row + secondary metrics + per-metric trend
     * (null when there's no valid comparison period).
     */
    public function summary(Request $request): JsonResponse
    {
        $seller = $request->user();
        $range = $this->resolveRange($request);

        if ($range instanceof JsonResponse) {
            return $range;
        }

        [$from, $to] = $range;
        $previous = $this->previousPeriod($from, $to);

        $current = $this->computeMetrics($seller->id, $from, $to);
        $prior = $previous ? $this->computeMetrics($seller->id, $previous[0], $previous[1]) : null;

        return response()->json([
            'data' => [
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'comparisonRange' => $previous
                    ? ['from' => $previous[0]->toDateString(), 'to' => $previous[1]->toDateString()]
                    : null,
                'timezone' => config('app.timezone'),
                'generatedAt' => now()->toIso8601String(),
                'metrics' => [
                    'netRevenue' => $this->metricWithTrend($current['netRevenue'], $prior['netRevenue'] ?? null),
                    'grossRevenue' => $this->metricWithTrend($current['grossRevenue'], $prior['grossRevenue'] ?? null),
                    'deliveredOrders' => $this->metricWithTrend($current['deliveredOrders'], $prior['deliveredOrders'] ?? null),
                    'averageOrderValue' => $this->metricWithTrend($current['averageOrderValue'], $prior['averageOrderValue'] ?? null),
                    'fulfillmentRate' => $this->metricWithTrend($current['fulfillmentRate'], $prior['fulfillmentRate'] ?? null),
                    'cancellationRate' => $this->metricWithTrend($current['cancellationRate'], $prior['cancellationRate'] ?? null),
                    'refundRate' => $this->metricWithTrend($current['refundRate'], $prior['refundRate'] ?? null),
                    'averageRating' => $this->metricWithTrend($current['averageRating'], $prior['averageRating'] ?? null),
                ],
                // Real seller status counts and best-selling product are
                // deliberately NOT duplicated here — they're already
                // available from /reports/order-breakdown and
                // /reports/top-products (sorted by revenue, the
                // default), and re-querying the same aggregates twice
                // per page load would be wasteful. The frontend derives
                // both from those responses instead.
                'ratingCount' => $current['ratingCount'],
            ],
        ]);
    }

    /**
     * GET /api/seller/reports/revenue-trend
     *
     * Query params: from, to, granularity (day|week|month, optional —
     * auto-selected based on range length if omitted or invalid for
     * that range; see allowedGranularities()).
     *
     * Every bucket in range is present with 0 when there's no revenue
     * that period (zero-value dates are shown, not skipped), so the
     * chart's x-axis is continuous.
     */
    public function revenueTrend(Request $request): JsonResponse
    {
        $seller = $request->user();
        $range = $this->resolveRange($request);

        if ($range instanceof JsonResponse) {
            return $range;
        }

        [$from, $to] = $range;
        $allowed = $this->allowedGranularities($from, $to);
        $granularity = $request->string('granularity')->toString() ?: $allowed['default'];

        if (! in_array($granularity, $allowed['options'], true)) {
            return response()->json([
                'message' => "Granularity \"{$granularity}\" isn't supported for this date range.",
                'allowed' => $allowed['options'],
            ], 422);
        }

        $previous = $this->previousPeriod($from, $to);

        return response()->json([
            'data' => [
                'granularity' => $granularity,
                'allowedGranularities' => $allowed['options'],
                'current' => $this->bucketedRevenue($seller->id, $from, $to, $granularity),
                'previous' => $previous
                    ? $this->bucketedRevenue($seller->id, $previous[0], $previous[1], $granularity)
                    : null,
                'comparisonRange' => $previous
                    ? ['from' => $previous[0]->toDateString(), 'to' => $previous[1]->toDateString()]
                    : null,
            ],
        ]);
    }

    /**
     * GET /api/seller/reports/order-breakdown
     *
     * Real status counts, using this project's actual 5 order statuses
     * directly (New/Processing/In Transit/Delivered/Cancelled) rather
     * than inventing merged categories — so the chart total always
     * equals the sum of the visible segments by construction.
     */
    public function orderBreakdown(Request $request): JsonResponse
    {
        $seller = $request->user();
        $range = $this->resolveRange($request);

        if ($range instanceof JsonResponse) {
            return $range;
        }

        [$from, $to] = $range;

        $counts = Order::where('seller_id', $seller->id)
            ->whereBetween('placed_at', [$from, $to])
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $total = $counts->sum();

        $segments = collect(Order::STATUSES)->map(fn (string $status) => [
            'status' => $status,
            'count' => (int) ($counts[$status] ?? 0),
            'percent' => $total > 0 ? round((($counts[$status] ?? 0) / $total) * 100, 1) : 0,
        ])->values();

        return response()->json([
            'data' => [
                'total' => $total,
                'segments' => $segments,
            ],
        ]);
    }

    /**
     * GET /api/seller/reports/top-products
     *
     * Query params: from, to, sort (revenue|units|orders, default
     * revenue), limit (default 10, max 50).
     *
     * Grouped by product_id (order_items always records the parent
     * product_id even for a variant purchase — variant_id is a
     * separate nullable column), so variant sales roll up under one
     * product row without double-counting.
     */
    public function topProducts(Request $request): JsonResponse
    {
        $seller = $request->user();
        $range = $this->resolveRange($request);

        if ($range instanceof JsonResponse) {
            return $range;
        }

        [$from, $to] = $range;
        $previous = $this->previousPeriod($from, $to);

        $sort = $request->string('sort')->toString() ?: 'revenue';
        if (! in_array($sort, ['revenue', 'units', 'orders'], true)) {
            return response()->json(['message' => 'Invalid sort option.'], 422);
        }

        $limit = min((int) ($request->integer('limit') ?: self::TOP_PRODUCTS_DEFAULT_LIMIT), self::TOP_PRODUCTS_MAX_LIMIT);

        $current = $this->productAggregates($seller->id, $from, $to);
        $prior = $previous ? $this->productAggregates($seller->id, $previous[0], $previous[1]) : collect();

        $sortColumn = match ($sort) {
            'units' => 'units',
            'orders' => 'orderCount',
            default => 'revenue',
        };

        $ranked = $current->sortByDesc($sortColumn)->take($limit)->values();

        $productIds = $ranked->pluck('productId')->all();
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        $rows = $ranked->map(function (array $row) use ($prior, $products) {
            $product = $products->get($row['productId']);
            $priorRow = $prior->get($row['productId']);

            return [
                'productId' => $row['productId'],
                'name' => $row['name'],
                'sku' => $row['sku'],
                'image' => ($product?->images ?? [])[0]['url'] ?? null,
                'unitsSold' => $row['units'],
                'orderCount' => $row['orderCount'],
                'revenue' => round($row['revenue'], 2),
                'growth' => $priorRow && $priorRow['revenue'] > 0
                    ? round((($row['revenue'] - $priorRow['revenue']) / $priorRow['revenue']) * 100, 1)
                    : null,
            ];
        })->values();

        return response()->json([
            'data' => $rows,
            'meta' => ['sort' => $sort, 'limit' => $limit],
        ]);
    }

    /**
     * GET /api/seller/reports/export
     *
     * Streams a CSV of the same eligible (Delivered) orders behind the
     * summary metrics, scoped to the authenticated seller and the
     * requested date range only. Values are escaped against spreadsheet
     * formula injection (a leading =, +, -, or @ gets a leading single
     * quote — Excel/Sheets/LibreOffice all treat that as "force text"
     * and won't evaluate it as a formula).
     */
    public function export(Request $request): Response|JsonResponse
    {
        $seller = $request->user();
        $range = $this->resolveRange($request);

        if ($range instanceof JsonResponse) {
            return $range;
        }

        [$from, $to] = $range;

        $orders = Order::with('items')
            ->where('seller_id', $seller->id)
            ->where('status', 'Delivered')
            ->whereBetween('placed_at', [$from, $to])
            ->orderBy('placed_at')
            ->get();

        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['Order #', 'Date', 'Status', 'Payment Status', 'Items', 'Subtotal', 'Shipping Fee', 'Discount', 'Total']);

        foreach ($orders as $order) {
            fputcsv($handle, [
                $this->csvSafe($order->order_number),
                $order->placed_at?->format('Y-m-d H:i'),
                $this->csvSafe($order->status),
                $this->csvSafe($order->payment_status),
                $this->csvSafe($order->items->sum('quantity')),
                (float) $order->subtotal,
                (float) $order->shipping_fee,
                (float) $order->discount,
                (float) $order->total,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $filename = sprintf('nexmart-seller-report-%s-to-%s.csv', $from->toDateString(), $to->toDateString());

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * GET /api/seller/reports/sales
     *
     * Daily/weekly/monthly sales for the range with the exact metric
     * set from the spec: gross, discounts, refunds, net, order count,
     * units sold, AOV. All computed in SellerReportService.
     */
    public function sales(Request $request, SellerReportService $reports): JsonResponse
    {
        $range = $this->resolveRange($request);
        if ($range instanceof JsonResponse) {
            return $range;
        }
        [$from, $to] = $range;
        $filters = $this->reportFilters($request);

        return $this->reportResponse($from, $to, $filters, $reports->salesSummary($request->user()->id, $from, $to, $filters));
    }

    /**
     * GET /api/seller/reports/order-summary
     *
     * Order counts across all fulfilment statuses + Returned / Refunded.
     */
    public function orderSummary(Request $request, SellerReportService $reports): JsonResponse
    {
        $range = $this->resolveRange($request);
        if ($range instanceof JsonResponse) {
            return $range;
        }
        [$from, $to] = $range;
        $filters = $this->reportFilters($request);

        return $this->reportResponse($from, $to, $filters, $reports->orderStatusSummary($request->user()->id, $from, $to, $filters));
    }

    /**
     * GET /api/seller/reports/product-performance?page=&per_page=
     */
    public function productPerformance(Request $request, SellerReportService $reports): JsonResponse
    {
        $range = $this->resolveRange($request);
        if ($range instanceof JsonResponse) {
            return $range;
        }
        [$from, $to] = $range;
        $filters = $this->reportFilters($request);
        [$page, $perPage] = $this->reportPage($request);

        $result = $reports->productPerformance($request->user()->id, $from, $to, $filters, $page, $perPage);

        return $this->reportResponse($from, $to, $filters, $result['rows'], $result['meta']);
    }

    /**
     * GET /api/seller/reports/inventory?page=&per_page=
     *
     * Current stock + period movement totals from inventory_movements.
     */
    public function inventory(Request $request, SellerReportService $reports): JsonResponse
    {
        $range = $this->resolveRange($request);
        if ($range instanceof JsonResponse) {
            return $range;
        }
        [$from, $to] = $range;
        $filters = $this->reportFilters($request);
        [$page, $perPage] = $this->reportPage($request);

        $result = $reports->inventoryReport($request->user()->id, $from, $to, $filters, $page, $perPage);

        return $this->reportResponse($from, $to, $filters, $result['rows'], $result['meta']);
    }

    /**
     * GET /api/seller/reports/returns?page=&per_page=
     *
     * Return / refund report — kept separate from cancellations.
     */
    public function returns(Request $request, SellerReportService $reports): JsonResponse
    {
        $range = $this->resolveRange($request);
        if ($range instanceof JsonResponse) {
            return $range;
        }
        [$from, $to] = $range;
        $filters = $this->reportFilters($request);
        [$page, $perPage] = $this->reportPage($request);

        $result = $reports->returnsReport($request->user()->id, $from, $to, $filters, $page, $perPage);

        return $this->reportResponse($from, $to, $filters, [
            'summary' => $result['summary'],
            'reasons' => $result['reasons'],
            'rows' => $result['rows'],
        ], $result['meta']);
    }

    /**
     * GET /api/seller/reports/download?type=&format=csv|pdf
     *
     * type: sales | orders | products | inventory | returns
     *
     * Every export is generated here from DB records (never from
     * frontend-calculated totals), carries the required header block
     * (store, title, range, applied filters, generated-at, currency)
     * and the summary totals. `csv` streams a file; `pdf` returns a
     * print-ready HTML document (all totals server-computed) that the
     * client opens and prints to PDF — swap the final return for a
     * dompdf stream to get a true .pdf with no other change.
     */
    public function download(Request $request, SellerReportService $reports): Response|JsonResponse
    {
        $range = $this->resolveRange($request);
        if ($range instanceof JsonResponse) {
            return $range;
        }
        [$from, $to] = $range;

        $type = $request->string('type')->toString() ?: 'sales';
        $format = $request->string('format')->toString() ?: 'csv';

        if (! in_array($type, ['sales', 'orders', 'products', 'inventory', 'returns'], true)) {
            return response()->json(['message' => 'Unknown report type.'], 422);
        }
        if (! in_array($format, ['csv', 'pdf'], true)) {
            return response()->json(['message' => 'Format must be csv or pdf.'], 422);
        }

        $seller = $request->user();
        $filters = $this->reportFilters($request);
        $storeName = $seller->sellerDetail?->business_name ?? $seller->full_name ?? 'Seller';

        [$title, $columns, $rows, $totals] = $this->reportDataset($type, $reports, $seller->id, $from, $to, $filters);

        $meta = [
            'store' => $storeName,
            'title' => $title,
            'range' => $from->toDateString().' to '.$to->toDateString(),
            'filters' => $this->describeFilters($filters),
            'generatedAt' => now(config('app.timezone'))->format('Y-m-d H:i T'),
            'currency' => 'PHP (₱)',
        ];

        $slug = str_replace('_', '-', $type);
        $stamp = $from->format('Y-m') === $to->format('Y-m') ? $from->format('Y-m') : $from->toDateString();
        $filename = "{$slug}-report-{$stamp}.{$format}";

        if ($format === 'csv') {
            return $this->csvDocument($filename, $meta, $columns, $rows, $totals);
        }

        return response($this->pdfHtmlDocument($meta, $columns, $rows, $totals), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

    // ---------------------------------------------------------------
    // Report helpers
    // ---------------------------------------------------------------

    /** @return array{status?:string, payment_status?:string, product_id?:string, return_status?:string} */
    private function reportFilters(Request $request): array
    {
        return array_filter([
            'status' => $request->string('status')->toString() ?: null,
            'payment_status' => $request->string('payment_status')->toString() ?: null,
            'product_id' => $request->string('product_id')->toString() ?: null,
            'return_status' => $request->string('return_status')->toString() ?: null,
        ]);
    }

    /** @return array{0:int,1:int} */
    private function reportPage(Request $request): array
    {
        return [
            max(1, (int) $request->integer('page') ?: 1),
            min(100, max(5, (int) $request->integer('per_page') ?: 20)),
        ];
    }

    private function reportResponse(CarbonImmutable $from, CarbonImmutable $to, array $filters, mixed $data, ?array $meta = null): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => array_merge([
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'appliedFilters' => $this->describeFilters($filters),
                'timezone' => config('app.timezone'),
                'currency' => 'PHP',
                'generatedAt' => now()->toIso8601String(),
            ], $meta ?? []),
        ]);
    }

    /** @return array<string,string> */
    private function describeFilters(array $filters): array
    {
        $labels = [
            'status' => 'Order status',
            'payment_status' => 'Payment status',
            'product_id' => 'Product',
            'return_status' => 'Return status',
        ];

        $out = [];
        foreach ($filters as $key => $value) {
            $out[$labels[$key] ?? $key] = (string) $value;
        }

        return $out;
    }

    /**
     * @return array{0:string, 1:array<int,string>, 2:array<int,array>, 3:array<string,mixed>}
     */
    private function reportDataset(string $type, SellerReportService $reports, string $sellerId, CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        return match ($type) {
            'sales' => (function () use ($reports, $sellerId, $from, $to, $filters) {
                $s = $reports->salesSummary($sellerId, $from, $to, $filters);

                return [
                    'Sales Report',
                    ['Metric', 'Value'],
                    [
                        ['Gross sales', $s['grossSales']],
                        ['Discounts', $s['discounts']],
                        ['Refunds', $s['refunds']],
                        ['Net sales', $s['netSales']],
                        ['Orders', $s['orderCount']],
                        ['Units sold', $s['unitsSold']],
                        ['Average order value', $s['averageOrderValue']],
                    ],
                    $s,
                ];
            })(),
            'orders' => (function () use ($reports, $sellerId, $from, $to, $filters) {
                $s = $reports->orderStatusSummary($sellerId, $from, $to, $filters);

                return [
                    'Order Summary',
                    ['Status', 'Orders'],
                    collect($s['rows'])->map(fn ($r) => [$r['label'], $r['count']])->all(),
                    ['Total orders' => $s['total']],
                ];
            })(),
            'products' => (function () use ($reports, $sellerId, $from, $to, $filters) {
                $r = $reports->productPerformance($sellerId, $from, $to, $filters, 1, 1000);

                return [
                    'Product Performance',
                    ['Product', 'SKU', 'Units sold', 'Orders', 'Revenue', 'Return qty', 'Return rate %', 'Avg rating', 'Current stock'],
                    collect($r['rows'])->map(fn ($p) => [
                        $p['name'], $p['sku'], $p['unitsSold'], $p['orders'], $p['revenue'],
                        $p['returnQty'], $p['returnRate'], $p['avgRating'], $p['currentStock'],
                    ])->all(),
                    ['Products' => $r['meta']['total']],
                ];
            })(),
            'inventory' => (function () use ($reports, $sellerId, $from, $to, $filters) {
                $r = $reports->inventoryReport($sellerId, $from, $to, $filters, 1, 1000);

                return [
                    'Inventory Report',
                    ['Product', 'SKU', 'Current stock', 'Status', 'Stock added', 'Stock removed', 'Damaged', 'Lost', 'Adjustments'],
                    collect($r['rows'])->map(fn ($p) => [
                        $p['name'], $p['sku'], $p['currentStock'], $p['stockStatus'],
                        $p['stockAdded'], $p['stockRemoved'], $p['damaged'], $p['lost'], $p['adjustments'],
                    ])->all(),
                    ['Products' => $r['meta']['total']],
                ];
            })(),
            'returns' => (function () use ($reports, $sellerId, $from, $to, $filters) {
                $r = $reports->returnsReport($sellerId, $from, $to, $filters, 1, 1000);

                return [
                    'Return & Refund Report',
                    ['Product', 'Returned qty', 'Delivered qty', 'Return rate %'],
                    collect($r['rows'])->map(fn ($p) => [$p['name'], $p['returnedQty'], $p['deliveredQty'], $p['returnRate']])->all(),
                    $r['summary'],
                ];
            })(),
            default => ['Report', [], [], []],
        };
    }

    private function csvDocument(string $filename, array $meta, array $columns, array $rows, array $totals): Response
    {
        $handle = fopen('php://temp', 'w+');

        fputcsv($handle, [$this->csvSafe($meta['store'])]);
        fputcsv($handle, [$this->csvSafe($meta['title'])]);
        fputcsv($handle, ['Date range', $this->csvSafe($meta['range'])]);
        foreach ($meta['filters'] as $k => $v) {
            fputcsv($handle, ['Filter: '.$this->csvSafe($k), $this->csvSafe($v)]);
        }
        fputcsv($handle, ['Generated', $this->csvSafe($meta['generatedAt'])]);
        fputcsv($handle, ['Currency', $this->csvSafe($meta['currency'])]);
        fputcsv($handle, []);

        fputcsv($handle, array_map([$this, 'csvSafe'], $columns));
        foreach ($rows as $row) {
            fputcsv($handle, array_map([$this, 'csvSafe'], $row));
        }

        fputcsv($handle, []);
        fputcsv($handle, ['Summary totals']);
        foreach ($totals as $k => $v) {
            fputcsv($handle, [$this->csvSafe(ucfirst((string) $k)), $this->csvSafe($v)]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function pdfHtmlDocument(array $meta, array $columns, array $rows, array $totals): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $filterLines = '';
        foreach ($meta['filters'] as $k => $v) {
            $filterLines .= '<div>'.$e($k).': <strong>'.$e($v).'</strong></div>';
        }
        if ($filterLines === '') {
            $filterLines = '<div>No filters applied</div>';
        }

        $head = '<tr>'.collect($columns)->map(fn ($c) => '<th>'.$e($c).'</th>')->implode('').'</tr>';
        $body = collect($rows)->map(
            fn ($row) => '<tr>'.collect($row)->map(fn ($cell) => '<td>'.$e($cell).'</td>')->implode('').'</tr>'
        )->implode('');
        $totalRows = collect($totals)->map(
            fn ($v, $k) => '<tr><td>'.$e(ucfirst((string) $k)).'</td><td>'.$e($v).'</td></tr>'
        )->implode('');

        return <<<HTML
        <!doctype html><html><head><meta charset="utf-8"><title>{$e($meta['title'])}</title>
        <style>
            @page { margin: 16mm; @bottom-right { content: "Page " counter(page) " of " counter(pages); } }
            body { font: 12px/1.5 -apple-system, "Segoe UI", Roboto, sans-serif; color: #111; }
            h1 { font-size: 18px; margin: 0 0 2px; }
            .meta { color: #555; font-size: 11px; margin-bottom: 14px; }
            .meta strong { color: #111; }
            table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            th { text-align: left; background: #f1f5f9; padding: 6px 8px; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; }
            td { padding: 6px 8px; border-bottom: 1px solid #eee; }
            .totals { margin-top: 16px; width: 320px; }
            .totals th { background: none; }
            @media print { .noprint { display: none; } }
        </style></head><body onload="window.focus();window.print()">
            <div class="noprint" style="text-align:right;margin-bottom:8px">
                <button onclick="window.print()">Print / Save as PDF</button>
            </div>
            <h1>{$e($meta['title'])}</h1>
            <div class="meta">
                <div><strong>{$e($meta['store'])}</strong></div>
                <div>Date range: <strong>{$e($meta['range'])}</strong></div>
                {$filterLines}
                <div>Generated: {$e($meta['generatedAt'])} · Currency: {$e($meta['currency'])}</div>
            </div>
            <table><thead>{$head}</thead><tbody>{$body}</tbody></table>
            <table class="totals"><thead><tr><th>Summary</th><th></th></tr></thead><tbody>{$totalRows}</tbody></table>
        </body></html>
        HTML;
    }

    // ---------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------

    /**
     * Parses/validates `from`/`to` query params (Y-m-d). Defaults to the
     * last DEFAULT_RANGE_DAYS days when omitted. Returns a 422
     * JsonResponse (for the controller to return directly) on invalid
     * input — end before start, unparseable dates, or a range longer
     * than MAX_RANGE_DAYS (guards the top-products/export queries and
     * the chart bucket count against an unbounded request).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|JsonResponse
     */
    private function resolveRange(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid date range.', 'errors' => $validator->errors()], 422);
        }

        $tz = config('app.timezone');
        $to = $request->filled('to')
            ? CarbonImmutable::createFromFormat('Y-m-d', $request->string('to')->toString(), $tz)->endOfDay()
            : CarbonImmutable::now($tz)->endOfDay();

        $from = $request->filled('from')
            ? CarbonImmutable::createFromFormat('Y-m-d', $request->string('from')->toString(), $tz)->startOfDay()
            : $to->copy()->subDays(self::DEFAULT_RANGE_DAYS - 1)->startOfDay();

        if ($to->lessThan($from)) {
            return response()->json(['message' => 'The end date must be on or after the start date.'], 422);
        }

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            return response()->json(['message' => 'Date range is too large. Please select '.self::MAX_RANGE_DAYS.' days or fewer.'], 422);
        }

        return [$from, $to];
    }

    /**
     * The immediately preceding period of equal length to [from, to].
     * Always returns a range (there's always "before" a date), but
     * callers only use it as a real comparison when there's actual
     * data in it — see computeMetrics()/bucketedRevenue() callers,
     * which end up with null trends when the period is empty.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function previousPeriod(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $lengthInSeconds = $from->diffInSeconds($to);

        return [
            $from->subSeconds($lengthInSeconds + 1),
            $from->subSecond(),
        ];
    }

    /**
     * Which granularities make sense for a given range length, and
     * which one to default to — avoids e.g. a daily chart with 300+
     * points, or a monthly chart with only 2 buckets.
     */
    private function allowedGranularities(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = $from->diffInDays($to) + 1;

        return match (true) {
            $days <= 31 => ['options' => ['day'], 'default' => 'day'],
            $days <= 120 => ['options' => ['day', 'week'], 'default' => 'week'],
            $days <= 366 => ['options' => ['week', 'month'], 'default' => 'month'],
            default => ['options' => ['month'], 'default' => 'month'],
        };
    }

    /**
     * Core KPI set for a single period. Shared by summary() for both
     * the current and (when present) comparison period, so the two are
     * guaranteed to be computed identically.
     */
    private function computeMetrics(string $sellerId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $statusCounts = Order::where('seller_id', $sellerId)
            ->whereBetween('placed_at', [$from, $to])
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $delivered = (int) ($statusCounts['Delivered'] ?? 0);
        $cancelled = (int) ($statusCounts['Cancelled'] ?? 0);
        $outcomeDenominator = $delivered + $cancelled;

        $deliveredOrders = Order::where('seller_id', $sellerId)
            ->where('status', 'Delivered')
            ->whereBetween('placed_at', [$from, $to]);

        $grossRevenue = (float) (clone $deliveredOrders)->sum('total');
        $refundedRevenue = (float) (clone $deliveredOrders)->where('payment_status', 'Refunded')->sum('total');
        $refundedCount = (int) (clone $deliveredOrders)->where('payment_status', 'Refunded')->count();

        $ratingQuery = Review::where('seller_id', $sellerId)->whereBetween('created_at', [$from, $to]);
        $ratingCount = (clone $ratingQuery)->count();

        return [
            'grossRevenue' => round($grossRevenue, 2),
            'netRevenue' => round($grossRevenue - $refundedRevenue, 2),
            'deliveredOrders' => $delivered,
            'averageOrderValue' => $delivered > 0 ? round($grossRevenue / $delivered, 2) : null,
            'fulfillmentRate' => $outcomeDenominator > 0 ? round(($delivered / $outcomeDenominator) * 100, 1) : null,
            'cancellationRate' => $outcomeDenominator > 0 ? round(($cancelled / $outcomeDenominator) * 100, 1) : null,
            'refundRate' => $delivered > 0 ? round(($refundedCount / $delivered) * 100, 1) : null,
            'averageRating' => $ratingCount > 0 ? round($ratingQuery->avg('rating'), 2) : null,
            'ratingCount' => $ratingCount,
        ];
    }

    /**
     * Wraps a current value with its trend vs. the prior value. Trend
     * is omitted (null) whenever either side is null/zero-denominator —
     * a metric that couldn't be computed this period, or has nothing
     * to compare against — rather than showing a fabricated 0%/∞%.
     */
    private function metricWithTrend(mixed $value, mixed $priorValue): array
    {
        $trend = null;

        if ($value !== null && $priorValue !== null && $priorValue != 0) {
            $trend = round((($value - $priorValue) / abs($priorValue)) * 100, 1);
        }

        return ['value' => $value, 'trend' => $trend];
    }

    /**
     * Revenue grouped into continuous buckets across [from, to] at the
     * given granularity. Every bucket is present (zero-filled) even
     * when a day/week/month had no delivered-order revenue, so chart
     * x-axes never skip a period.
     */
    private function bucketedRevenue(string $sellerId, CarbonImmutable $from, CarbonImmutable $to, string $granularity): array
    {
        $truncUnit = match ($granularity) {
            'week' => 'week',
            'month' => 'month',
            default => 'day',
        };

        $rows = Order::where('seller_id', $sellerId)
            ->where('status', 'Delivered')
            ->whereBetween('placed_at', [$from, $to])
            ->select(
                DB::raw("date_trunc('{$truncUnit}', placed_at) as bucket"),
                DB::raw('sum(total) as revenue'),
                DB::raw('count(*) as order_count'),
            )
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->bucket)->toDateString());

        $buckets = [];
        $cursor = $from->startOfDay();
        $step = match ($truncUnit) {
            'week' => fn (CarbonImmutable $d) => $d->addWeek(),
            'month' => fn (CarbonImmutable $d) => $d->addMonthNoOverflow(),
            default => fn (CarbonImmutable $d) => $d->addDay(),
        };

        // Align the cursor to the same bucket start Postgres' date_trunc
        // would produce, so zero-filled buckets line up with real ones.
        $cursor = match ($truncUnit) {
            'week' => $cursor->startOfWeek(UnitValue::MONDAY),
            'month' => $cursor->startOfMonth(),
            default => $cursor,
        };

        while ($cursor->lessThanOrEqualTo($to)) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);

            $buckets[] = [
                'date' => $key,
                'revenue' => $row ? round((float) $row->revenue, 2) : 0.0,
                'orderCount' => $row ? (int) $row->order_count : 0,
            ];

            $cursor = $step($cursor);
        }

        return $buckets;
    }

    /**
     * Per-product aggregates (units, order count, revenue) for
     * Delivered orders in range, grouped by product_id so variant
     * sales roll up under their parent product.
     *
     * @return Collection<string, array>
     */
    private function productAggregates(string $sellerId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.seller_id', $sellerId)
            ->where('orders.status', 'Delivered')
            ->whereBetween('orders.placed_at', [$from, $to])
            ->whereNotNull('order_items.product_id')
            ->select(
                'order_items.product_id',
                DB::raw('max(order_items.product_name) as product_name'),
                DB::raw('max(order_items.sku) as sku'),
                DB::raw('sum(order_items.quantity) as units'),
                DB::raw('count(distinct order_items.order_id) as order_count'),
                DB::raw('sum(order_items.subtotal) as revenue'),
            )
            ->groupBy('order_items.product_id')
            ->get()
            ->keyBy('product_id')
            ->map(fn ($row) => [
                'productId' => $row->product_id,
                'name' => $row->product_name,
                'sku' => $row->sku,
                'units' => (int) $row->units,
                'orderCount' => (int) $row->order_count,
                'revenue' => (float) $row->revenue,
            ]);
    }

    /**
     * Prevents spreadsheet formula injection: a leading =, +, -, or @
     * is how a formula/DDE payload starts in Excel/Sheets/LibreOffice,
     * so a leading single quote forces those apps to treat the cell as
     * plain text instead of evaluating it.
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
