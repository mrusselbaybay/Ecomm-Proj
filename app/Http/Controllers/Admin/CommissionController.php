<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\CommissionCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->eligibleOrders();

        if ($from = $request->date('from')) {
            $query->whereDate('placed_at', '>=', $from);
        }

        if ($to = $request->date('to')) {
            $query->whereDate('placed_at', '<=', $to);
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function (Builder $query) use ($search): void {
                $query->whereLike('order_number', "%{$search}%")
                    ->orWhereHas('seller', function (Builder $sellerQuery) use ($search): void {
                        $sellerQuery->whereLike('first_name', "%{$search}%")
                            ->orWhereLike('last_name', "%{$search}%")
                            ->orWhereLike('email', "%{$search}%");
                    });
            });
        }

        // One aggregate query (count + both sums) replaces three separate
        // round trips over the same eligible-orders set.
        $summaryRow = (clone $query)
            ->selectRaw('count(*) as orders_count, coalesce(sum(subtotal), 0) as gross_sales, coalesce(sum(discount), 0) as discounts, coalesce(sum(platform_discount), 0) as platform_vouchers')
            ->first();
        $grossSales = (float) $summaryRow->gross_sales;
        $discounts = (float) $summaryRow->discounts;
        $commissionBasis = CommissionCalculator::basis($grossSales, $discounts);

        $orders = $query
            ->with('seller:id,first_name,last_name,email')
            ->latest('placed_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Order $order): array => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'placed_at' => $order->placed_at?->toIso8601String(),
                'payment_status' => $order->payment_status,
                'subtotal' => (float) $order->subtotal,
                'discount' => (float) $order->discount,
                'commission_basis' => CommissionCalculator::basis((float) $order->subtotal, (float) $order->discount),
                'commission' => CommissionCalculator::commission((float) $order->subtotal, (float) $order->discount),
                'platform_discount' => (float) $order->platform_discount,
                'seller' => $this->sellerData($order->seller),
            ]);

        return response()->json([
            'orders' => $orders,
            'rate' => CommissionCalculator::RATE,
            'summary' => [
                'eligible_orders' => (int) $summaryRow->orders_count,
                'gross_sales' => round($grossSales, 2),
                'commission_basis' => $commissionBasis,
                'platform_commission' => CommissionCalculator::commission($grossSales, $discounts),
                // Platform-funded vouchers on these orders (seller payouts unaffected).
                'platform_vouchers' => round((float) $summaryRow->platform_vouchers, 2),
                'net_after_vouchers' => round(CommissionCalculator::commission($grossSales, $discounts) - (float) $summaryRow->platform_vouchers, 2),
            ],
        ]);
    }

    /** @return Builder<Order> */
    private function eligibleOrders(): Builder
    {
        return Order::query()
            ->where('status', 'Delivered')
            ->where('payment_status', '!=', 'Refunded');
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
