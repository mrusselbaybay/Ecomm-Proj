<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GenerateReportRequest;
use App\Models\Order;
use App\Support\CommissionCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(GenerateReportRequest $request): JsonResponse
    {
        $type = $request->validated('type');
        $query = $this->reportQuery($request);
        $summaryQuery = clone $query;
        $subtotal = (float) (clone $summaryQuery)->sum('subtotal');
        $discount = (float) (clone $summaryQuery)->sum('discount');
        $basis = CommissionCalculator::basis($subtotal, $discount);

        $records = $query->with('seller:id,first_name,last_name,email')
            ->latest('placed_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Order $order): array => $this->row($order));

        return response()->json([
            'type' => $type,
            'records' => $records,
            'summary' => [
                'orders' => (clone $summaryQuery)->count(),
                'gross_merchandise' => round($subtotal, 2),
                'discounts' => round($discount, 2),
                'net_merchandise' => $basis,
                'platform_commission' => CommissionCalculator::commission($subtotal, $discount),
                'seller_proceeds' => round($basis - CommissionCalculator::commission($subtotal, $discount), 2),
            ],
        ]);
    }

    public function export(GenerateReportRequest $request): StreamedResponse
    {
        $type = $request->validated('type');
        $filename = "nexmart-{$type}-report-".now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($request, $type): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fputcsv($output, $type === 'sales'
                ? ['Order', 'Seller', 'Order Date', 'Subtotal', 'Discount', 'Net Merchandise', 'Order Total']
                : ['Order', 'Seller', 'Order Date', 'Commission Basis', 'Rate', 'Platform Commission', 'Seller Proceeds']);

            $this->reportQuery($request)
                ->with('seller:id,first_name,last_name,email')
                ->orderBy('placed_at')
                ->each(function (Order $order) use ($output, $type): void {
                    $row = $this->row($order);
                    fputcsv($output, $type === 'sales'
                        ? [$row['order_number'], $row['seller']['full_name'] ?? 'Unknown', $row['placed_at'], $row['subtotal'], $row['discount'], $row['net_merchandise'], $row['total']]
                        : [$row['order_number'], $row['seller']['full_name'] ?? 'Unknown', $row['placed_at'], $row['net_merchandise'], '10%', $row['commission'], $row['seller_proceeds']]);
                });

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** @return Builder<Order> */
    private function reportQuery(GenerateReportRequest $request): Builder
    {
        $query = Order::query()
            ->where('status', 'Delivered')
            ->where('payment_status', '!=', 'Refunded');

        if ($from = $request->date('from')) {
            $query->whereDate('placed_at', '>=', $from);
        }

        if ($to = $request->date('to')) {
            $query->whereDate('placed_at', '<=', $to);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function row(Order $order): array
    {
        $net = CommissionCalculator::basis((float) $order->subtotal, (float) $order->discount);
        $commission = CommissionCalculator::commission((float) $order->subtotal, (float) $order->discount);

        return [
            'order_number' => $order->order_number,
            'placed_at' => $order->placed_at?->toIso8601String(),
            'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount,
            'net_merchandise' => $net,
            'total' => (float) $order->total,
            'commission' => $commission,
            'seller_proceeds' => round($net - $commission, 2),
            'seller' => $this->sellerData($order->seller),
        ];
    }

    /** @return array{id: mixed, full_name: mixed, email: mixed}|null */
    private function sellerData(?Model $seller): ?array
    {
        return $seller ? [
            'id' => $seller->getAttribute('id'),
            'full_name' => $seller->getAttribute('full_name'),
            'email' => $seller->getAttribute('email'),
        ] : null;
    }
}
