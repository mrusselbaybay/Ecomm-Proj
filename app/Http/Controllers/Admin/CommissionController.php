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
                $query->where('order_number', 'ilike', "%{$search}%")
                    ->orWhereHas('seller', function (Builder $sellerQuery) use ($search): void {
                        $sellerQuery->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%");
                    });
            });
        }

        $summaryQuery = clone $query;
        $grossSales = (float) (clone $summaryQuery)->sum('subtotal');
        $discounts = (float) (clone $summaryQuery)->sum('discount');
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
                'seller' => $this->sellerData($order->seller),
            ]);

        return response()->json([
            'orders' => $orders,
            'rate' => CommissionCalculator::RATE,
            'summary' => [
                'eligible_orders' => (clone $summaryQuery)->count(),
                'gross_sales' => round($grossSales, 2),
                'commission_basis' => $commissionBasis,
                'platform_commission' => CommissionCalculator::commission($grossSales, $discounts),
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
