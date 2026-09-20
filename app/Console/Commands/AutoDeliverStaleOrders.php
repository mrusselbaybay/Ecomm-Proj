<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Services\SellerNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Safety net for the one real gap left once a seller can no longer mark
 * their own order Delivered (see Order::SELLER_SETTABLE_STATUSES and
 * SellerOrderController::updateStatus) and there is not yet a buyer-side
 * "confirm delivery" action wired up on this branch (that UI lives on
 * feature/buyer): without something eventually closing an order out,
 * every shipment would sit "In Transit" forever.
 *
 * There is no real "the rider physically delivered this" signal
 * anywhere in this schema today — public.parcel_assignments has a
 * delivered_at column, but nothing in this codebase (seller, logistics,
 * or otherwise) ever writes to it, so treating it as a precondition
 * here would check a column that can never be true. Rather than fake
 * that signal, this uses the one real, always-populated field that
 * already means "hasn't changed in a while" elsewhere in this app
 * (Courier Handover's history table, the Prepare Orders queue sort):
 * updated_at. An order sitting at status 'In Transit' with no update
 * in DAYS_BEFORE_AUTO_DELIVER days is treated as delivered.
 *
 *   php artisan orders:auto-deliver            # apply
 *   php artisan orders:auto-deliver --dry-run  # list what would change, apply nothing
 *
 * Scheduled daily — see routes/console.php.
 */
class AutoDeliverStaleOrders extends Command
{
    private const DAYS_BEFORE_AUTO_DELIVER = 7;

    protected $signature = 'orders:auto-deliver {--dry-run : List affected orders without changing anything}';

    protected $description = 'Marks orders Delivered once they have sat In Transit for '.self::DAYS_BEFORE_AUTO_DELIVER.'+ days with no confirmation.';

    public function handle(): int
    {
        $cutoff = now()->subDays(self::DAYS_BEFORE_AUTO_DELIVER);

        $stale = Order::where('status', 'In Transit')
            ->where('updated_at', '<=', $cutoff)
            ->get(['id', 'order_number', 'seller_id', 'status', 'updated_at']);

        if ($stale->isEmpty()) {
            $this->info('No orders have been In Transit for '.self::DAYS_BEFORE_AUTO_DELIVER.'+ days. Nothing to do.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['Order #', 'In Transit since (last update)'],
                $stale->map(fn (Order $o) => [$o->order_number, optional($o->updated_at)->toDateTimeString()])->all(),
            );
            $this->info($stale->count().' order(s) would be auto-delivered.');

            return self::SUCCESS;
        }

        $delivered = 0;

        foreach ($stale as $order) {
            $wasDelivered = DB::transaction(function () use ($order) {
                // Lock + re-check: guards against a buyer/seller action
                // (or a previous run) changing this order between the
                // query above and this write.
                $fresh = Order::whereKey($order->id)->lockForUpdate()->first();

                if (! $fresh || $fresh->status !== 'In Transit') {
                    return false;
                }

                $fresh->status = 'Delivered';
                $fresh->save();

                OrderStatusHistory::create([
                    'order_id' => $fresh->id,
                    'status' => 'Delivered',
                    'previous_status' => 'In Transit',
                    'note' => 'Automatically marked Delivered — in transit for '.self::DAYS_BEFORE_AUTO_DELIVER.'+ days with no confirmation.',
                    'changed_by' => null,
                ]);

                return true;
            });

            if ($wasDelivered) {
                $delivered++;
                app(SellerNotifier::class)->orderStatusChanged(
                    $order->fresh(),
                    'In Transit',
                    'Delivered',
                    'the system (in transit '.self::DAYS_BEFORE_AUTO_DELIVER.'+ days, auto-confirmed)',
                );
            }
        }

        $this->info("{$delivered} order(s) auto-delivered.");

        return self::SUCCESS;
    }
}
