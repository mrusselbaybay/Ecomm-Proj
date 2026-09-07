<?php

namespace App\Console\Commands;

use App\Models\Address;
use App\Models\Order;
use Illuminate\Console\Command;

/**
 * One-off repair for orders placed before CheckoutService started
 * recording the structured shipping destination.
 *
 * Those rows have a `shipping_street` and (usually) a
 * `shipping_region_name` but no `shipping_province_name` /
 * `shipping_municipality_name`, so ParcelIntakeService::matchingArea()
 * bails immediately and both intake sorting and "Auto assign" report
 * "no logistics area is available for this address" for every one of them.
 *
 * Fills the blanks from the buyer's own profile address
 * (public.addresses, owner_kind='profile') — the same source
 * CheckoutService now reads at checkout. Only ever writes fields that are
 * currently empty.
 *
 *   php artisan orders:backfill-shipping-area --dry-run
 *   php artisan orders:backfill-shipping-area
 */
class BackfillOrderShippingArea extends Command
{
    protected $signature = 'orders:backfill-shipping-area
        {--dry-run : Report what would change without writing}';

    protected $description = 'Backfill blank order shipping province/municipality from each buyer\'s profile address';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $orders = Order::query()
            ->where(fn ($q) => $q->whereNull('shipping_province_name')->orWhere('shipping_province_name', ''))
            ->orWhere(fn ($q) => $q->whereNull('shipping_municipality_name')->orWhere('shipping_municipality_name', ''))
            ->get();

        if ($orders->isEmpty()) {
            $this->info('Nothing to backfill — every order already has a province and municipality.');

            return self::SUCCESS;
        }

        $addresses = Address::query()
            ->where('owner_kind', 'profile')
            ->whereIn('profile_id', $orders->pluck('buyer_profile_id')->unique())
            ->get()
            ->keyBy('profile_id');

        $fixed = 0;
        $skipped = 0;

        foreach ($orders as $order) {
            $address = $addresses->get($order->buyer_profile_id);

            if (! $address || ! filled($address->province_name) || ! filled($address->municipality_name)) {
                $skipped++;
                $this->line("  <fg=yellow>skip</> {$order->tracking_number} — buyer has no complete profile address");

                continue;
            }

            $order->fill([
                'shipping_region_name' => $order->shipping_region_name ?: $address->region_name,
                'shipping_province_name' => $order->shipping_province_name ?: $address->province_name,
                'shipping_municipality_name' => $order->shipping_municipality_name ?: $address->municipality_name,
                'shipping_barangay' => $order->shipping_barangay ?: $address->barangay,
            ]);

            $this->line("  <fg=green>fill</> {$order->tracking_number} → {$order->shipping_municipality_name}, {$order->shipping_province_name}");

            if (! $dryRun) {
                $order->save();
            }

            $fixed++;
        }

        $verb = $dryRun ? 'would backfill' : 'backfilled';
        $this->info("{$verb} {$fixed} order(s); skipped {$skipped}.");

        if ($dryRun && $fixed > 0) {
            $this->comment('Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
