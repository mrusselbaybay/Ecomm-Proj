<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Payments\OrderReceiptService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Releases escrow for delivered orders the buyer never confirmed, once
 * OrderReceiptService::AUTO_CONFIRM_DAYS have passed since delivery.
 */
class AutoConfirmOrderReceipt extends Command
{
    protected $signature = 'orders:auto-confirm-receipt';

    protected $description = 'Auto-confirms receipt (and releases escrow) for orders delivered 7+ days ago.';

    public function handle(OrderReceiptService $receipts): int
    {
        $cutoff = now()->subDays(OrderReceiptService::AUTO_CONFIRM_DAYS);
        $confirmed = 0;

        Order::where('status', 'Delivered')
            ->whereNull('received_at')
            ->whereHas('statusHistory', fn ($q) => $q->where('status', 'Delivered')->where('created_at', '<=', $cutoff))
            ->select('id')
            ->chunkById(100, function ($orders) use ($receipts, &$confirmed) {
                foreach ($orders as $order) {
                    try {
                        $receipts->confirm($order, OrderReceiptService::VIA_AUTO);
                        $confirmed++;
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });

        $this->info("{$confirmed} order(s) auto-confirmed.");

        return self::SUCCESS;
    }
}
