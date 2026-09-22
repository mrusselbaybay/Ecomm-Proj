<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seller-scoped report aggregation. ONE place for the calculation rules
 * so the on-screen report, the CSV export and the print/PDF export can
 * never disagree (spec: "Ensure the on-screen report, CSV export, and
 * PDF export use the same calculation rules").
 *
 * Definitions (this project's established financial contract — final
 * sales are recognised at DELIVERY, matching SellerReportController's
 * existing computeMetrics()):
 *
 *   valid order      = status = 'Delivered' in the range
 *   gross sales      = SUM(order_items.subtotal) of valid orders
 *   discounts        = SUM(orders.discount) of valid orders
 *   refunds          = SUM(orders.total) of valid orders whose
 *                      payment_status = 'Refunded'
 *   net sales        = gross - discounts - refunds
 *   units sold       = SUM(order_items.quantity) of valid orders
 *   average order    = net sales / count(valid orders)
 *   return rate (%)  = returned qty / delivered qty * 100
 *
 * Every query is filtered by seller_id; a seller can only ever see their
 * own products / order items / movements / returns.
 *
 * All money stays as SQL numeric (SUM on decimal columns) and is only
 * cast to float at the very end for JSON — no float arithmetic on the
 * way.
 */
class SellerReportService
{
    /**
     * @param  array{status?:string, payment_status?:string, product_id?:string}  $filters
     */
    public function salesSummary(string $sellerId, CarbonInterface $from, CarbonInterface $to, array $filters = []): array
    {
        $validOrders = Order::where('seller_id', $sellerId)
            ->where('status', 'Delivered')
            ->whereBetween('placed_at', [$from, $to]);

        if (! empty($filters['payment_status'])) {
            $validOrders->where('payment_status', $filters['payment_status']);
        }

        $orderCount = (clone $validOrders)->count();
        $discounts = (float) (clone $validOrders)->sum('discount');
        $refunds = (float) (clone $validOrders)->where('payment_status', 'Refunded')->sum('total');

        $itemAgg = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.seller_id', $sellerId)
            ->where('orders.status', 'Delivered')
            ->whereBetween('orders.placed_at', [$from, $to])
            ->when(! empty($filters['payment_status']), fn ($q) => $q->where('orders.payment_status', $filters['payment_status']))
            ->when(! empty($filters['product_id']), fn ($q) => $q->where('order_items.product_id', $filters['product_id']))
            ->selectRaw('COALESCE(SUM(order_items.subtotal),0) as gross, COALESCE(SUM(order_items.quantity),0) as units')
            ->first();

        $gross = (float) ($itemAgg->gross ?? 0);
        $units = (int) ($itemAgg->units ?? 0);
        $net = $gross - $discounts - $refunds;

        return [
            'grossSales' => round($gross, 2),
            'discounts' => round($discounts, 2),
            'refunds' => round($refunds, 2),
            'netSales' => round($net, 2),
            'orderCount' => $orderCount,
            'unitsSold' => $units,
            'averageOrderValue' => $orderCount > 0 ? round($net / $orderCount, 2) : 0.0,
        ];
    }

    /**
     * Order counts by every stored fulfilment status, plus the two
     * money-side pseudo-statuses the spec asks for (Returned / Refunded),
     * which live on other columns.
     */
    public function orderStatusSummary(string $sellerId, CarbonInterface $from, CarbonInterface $to, array $filters = []): array
    {
        $base = Order::where('seller_id', $sellerId)->whereBetween('placed_at', [$from, $to]);

        if (! empty($filters['payment_status'])) {
            $base->where('payment_status', $filters['payment_status']);
        }

        $counts = (clone $base)
            ->select('status', DB::raw('count(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');

        $rows = collect(Order::STATUSES)->map(fn (string $s) => [
            'status' => $s,
            'label' => Order::labelFor($s),
            'count' => (int) ($counts[$s] ?? 0),
        ])->values()->all();

        $refunded = (int) (clone $base)->where('payment_status', 'Refunded')->count();

        $returned = 0;
        if (Schema::hasTable('order_return_requests')) {
            $returned = (int) DB::table('order_return_requests')
                ->where('seller_id', $sellerId)
                ->whereIn('status', ['approved', 'completed'])
                ->whereBetween('created_at', [$from, $to])
                ->distinct('order_id')
                ->count('order_id');
        }

        $rows[] = ['status' => 'Returned', 'label' => 'Returned', 'count' => $returned];
        $rows[] = ['status' => 'Refunded', 'label' => 'Refunded', 'count' => $refunded];

        return [
            'total' => (int) (clone $base)->count(),
            'rows' => $rows,
        ];
    }

    /**
     * Per-product performance: units, revenue, orders, return qty,
     * average rating, current stock. Grouped by product_id so variant
     * sales roll up under the parent.
     *
     * @return array{rows: array, meta: array}
     */
    public function productPerformance(string $sellerId, CarbonInterface $from, CarbonInterface $to, array $filters, int $page, int $perPage): array
    {
        $agg = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.seller_id', $sellerId)
            ->where('orders.status', 'Delivered')
            ->whereBetween('orders.placed_at', [$from, $to])
            ->whereNotNull('order_items.product_id')
            ->when(! empty($filters['product_id']), fn ($q) => $q->where('order_items.product_id', $filters['product_id']))
            ->groupBy('order_items.product_id')
            ->select(
                'order_items.product_id',
                DB::raw('max(order_items.product_name) as name'),
                DB::raw('max(order_items.sku) as sku'),
                DB::raw('sum(order_items.quantity) as units'),
                DB::raw('count(distinct order_items.order_id) as orders'),
                DB::raw('sum(order_items.subtotal) as revenue'),
            )
            ->get();

        $productIds = $agg->pluck('product_id')->all();

        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        $ratings = DB::table('reviews')
            ->select('product_id', DB::raw('avg(rating) as avg_rating'))
            ->where('seller_id', $sellerId)
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->pluck('avg_rating', 'product_id');

        $returnQty = collect();
        if (Schema::hasTable('order_return_requests') && $productIds) {
            $returnQty = DB::table('order_return_requests as r')
                ->join('order_items as oi', 'oi.id', '=', 'r.order_item_id')
                ->where('r.seller_id', $sellerId)
                ->whereIn('r.status', ['approved', 'completed'])
                ->whereIn('oi.product_id', $productIds)
                ->groupBy('oi.product_id')
                ->select('oi.product_id', DB::raw('sum(r.quantity) as qty'))
                ->pluck('qty', 'oi.product_id');
        }

        $rows = $agg->map(function ($row) use ($products, $ratings, $returnQty) {
            $product = $products->get($row->product_id);
            $delivered = (int) $row->units;
            $returned = (int) ($returnQty[$row->product_id] ?? 0);

            return [
                'productId' => $row->product_id,
                'name' => $row->name,
                'sku' => $row->sku,
                'unitsSold' => $delivered,
                'orders' => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
                'returnQty' => $returned,
                'returnRate' => $delivered > 0 ? round(($returned / $delivered) * 100, 1) : 0.0,
                'avgRating' => isset($ratings[$row->product_id]) ? round((float) $ratings[$row->product_id], 2) : null,
                'currentStock' => $product ? $product->effectiveStock() : null,
                'stockStatus' => $product?->stockStatus(),
            ];
        })
            ->sortByDesc('revenue')
            ->values();

        return $this->paginateCollection($rows, $page, $perPage);
    }

    /**
     * Inventory report — current stock + period stock movement totals,
     * straight from inventory_movements.
     */
    public function inventoryReport(string $sellerId, CarbonInterface $from, CarbonInterface $to, array $filters, int $page, int $perPage): array
    {
        $products = Product::with('variants')
            ->where('seller_id', $sellerId)
            ->when(! empty($filters['product_id']), fn ($q) => $q->whereKey($filters['product_id']))
            ->orderBy('name')
            ->get();

        $movementsByProduct = collect();
        if (Schema::hasTable('inventory_movements')) {
            $movementsByProduct = DB::table('inventory_movements')
                ->where('seller_id', $sellerId)
                ->whereBetween('created_at', [$from, $to])
                ->groupBy('product_id')
                ->select(
                    'product_id',
                    DB::raw("sum(case when quantity_change > 0 and movement_type <> 'return_restock' then quantity_change else 0 end) as added"),
                    DB::raw('sum(case when quantity_change < 0 then -quantity_change else 0 end) as removed'),
                    DB::raw("sum(case when movement_type = 'damaged' then -quantity_change else 0 end) as damaged"),
                    DB::raw("sum(case when movement_type = 'lost_item' then -quantity_change else 0 end) as lost"),
                    DB::raw("sum(case when movement_type in ('manual_increase','manual_decrease','incorrect_count','other','form_edit') then quantity_change else 0 end) as adjustments"),
                )
                ->get()
                ->keyBy('product_id');
        }

        $rows = $products->map(function (Product $p) use ($movementsByProduct) {
            $m = $movementsByProduct->get($p->id);

            return [
                'productId' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'hasVariants' => (bool) $p->has_variants,
                'currentStock' => $p->effectiveStock(),
                'lowStockThreshold' => $p->lowStockThreshold(),
                'stockStatus' => $p->stockStatus(),
                'isLowStock' => $p->stockStatus() === 'low_stock',
                'isOutOfStock' => $p->isOutOfStock(),
                'stockAdded' => (int) ($m->added ?? 0),
                'stockRemoved' => (int) ($m->removed ?? 0),
                'damaged' => (int) ($m->damaged ?? 0),
                'lost' => (int) ($m->lost ?? 0),
                'adjustments' => (int) ($m->adjustments ?? 0),
                'variants' => $p->has_variants
                    ? $p->variants->map(fn ($v) => [
                        'id' => $v->id,
                        'sku' => $v->sku,
                        'stock' => (int) $v->stock,
                        'stockStatus' => $v->setRelation('product', $p)->stockStatus(),
                    ])->all()
                    : [],
            ];
        });

        return $this->paginateCollection($rows->values(), $page, $perPage);
    }

    /**
     * Return / refund report — kept strictly separate from cancellations
     * (spec). Reads order_return_requests only.
     */
    public function returnsReport(string $sellerId, CarbonInterface $from, CarbonInterface $to, array $filters, int $page, int $perPage): array
    {
        if (! Schema::hasTable('order_return_requests')) {
            return [
                'summary' => ['total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0, 'returnedQty' => 0, 'refundAmount' => 0.0],
                'reasons' => [],
                'rows' => [], 'meta' => ['currentPage' => 1, 'lastPage' => 1, 'total' => 0],
            ];
        }

        $base = DB::table('order_return_requests')
            ->where('seller_id', $sellerId)
            ->whereBetween('created_at', [$from, $to])
            ->when(! empty($filters['return_status']), fn ($q) => $q->where('status', $filters['return_status']))
            ->when(! empty($filters['product_id']), function ($q) use ($filters) {
                $q->whereIn('order_item_id', DB::table('order_items')->where('product_id', $filters['product_id'])->pluck('id'));
            });

        $statusCounts = (clone $base)->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');
        $approvedLike = (clone $base)->whereIn('status', ['approved', 'completed']);

        $summary = [
            'total' => (int) (clone $base)->count(),
            'approved' => (int) ($statusCounts['approved'] ?? 0) + (int) ($statusCounts['completed'] ?? 0),
            'rejected' => (int) ($statusCounts['rejected'] ?? 0),
            'pending' => (int) ($statusCounts['pending'] ?? 0),
            'returnedQty' => (int) (clone $approvedLike)->sum('quantity'),
            'refundAmount' => round((float) (clone $approvedLike)->sum('estimated_amount'), 2),
        ];

        $reasons = (clone $base)
            ->select('reason', DB::raw('count(*) as c'))
            ->groupBy('reason')
            ->orderByDesc('c')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['reason' => $r->reason, 'count' => (int) $r->c])
            ->all();

        // Highest return-rate products: returned qty / delivered qty.
        $delivered = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.seller_id', $sellerId)
            ->where('orders.status', 'Delivered')
            ->whereBetween('orders.placed_at', [$from, $to])
            ->groupBy('order_items.product_id')
            ->select('order_items.product_id', DB::raw('sum(order_items.quantity) as qty'), DB::raw('max(order_items.product_name) as name'))
            ->get()->keyBy('product_id');

        $returnedByProduct = DB::table('order_return_requests as r')
            ->join('order_items as oi', 'oi.id', '=', 'r.order_item_id')
            ->where('r.seller_id', $sellerId)
            ->whereIn('r.status', ['approved', 'completed'])
            ->whereBetween('r.created_at', [$from, $to])
            ->groupBy('oi.product_id')
            ->select('oi.product_id', DB::raw('sum(r.quantity) as qty'))
            ->pluck('qty', 'oi.product_id');

        $productRows = collect($returnedByProduct)->map(function ($qty, $productId) use ($delivered) {
            $deliveredQty = (int) ($delivered[$productId]->qty ?? 0);

            return [
                'productId' => $productId,
                'name' => $delivered[$productId]->name ?? 'Product',
                'returnedQty' => (int) $qty,
                'deliveredQty' => $deliveredQty,
                'returnRate' => $deliveredQty > 0 ? round(((int) $qty / $deliveredQty) * 100, 1) : null,
            ];
        })->sortByDesc('returnRate')->values();

        $paged = $this->paginateCollection($productRows, $page, $perPage);

        return [
            'summary' => $summary,
            'reasons' => $reasons,
            'rows' => $paged['rows'],
            'meta' => $paged['meta'],
        ];
    }

    /**
     * @param  Collection  $rows
     */
    private function paginateCollection($rows, int $page, int $perPage): array
    {
        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));

        return [
            'rows' => $rows->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'meta' => ['currentPage' => $page, 'lastPage' => $lastPage, 'total' => $total, 'perPage' => $perPage],
        ];
    }
}
