<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\ParcelLocation;
use App\Support\PhilippineGeo;
use Illuminate\Console\Command;

/**
 * Feeds fake GPS pings into parcel_locations for orders that are "In
 * Transit", so the live tracking map (OrderJourneyMap.vue) has something
 * real to poll and animate before an actual courier client exists.
 *
 *   php artisan tracking:simulate            # loop, one ping / order every 20s
 *   php artisan tracking:simulate --once     # single pass (use from the scheduler)
 *   php artisan tracking:simulate --order=SN-10231 --speed=60
 *
 * Position is time-based: distance covered = (minutes since the order went
 * In Transit) x speed, as a fraction of the origin->destination distance,
 * plus a little wander so it doesn't look robotic. Pings are marked
 * source = 'simulator' — OrderTrackingService treats them like any other
 * ping, and `estimated` in the payload flips back to false while they're
 * fresh.
 */
class SimulateParcelTracking extends Command
{
    protected $signature = 'tracking:simulate
        {--once : Do a single pass and exit}
        {--interval=20 : Seconds between passes when looping}
        {--speed=45 : Assumed courier speed in km/h}
        {--order= : Limit to one order number}';

    protected $description = 'Emit simulated courier GPS pings for in-transit orders';

    public function handle(): int
    {
        $interval = max(3, (int) $this->option('interval'));
        $speed = max(5, (float) $this->option('speed'));

        do {
            $count = $this->pass($speed);
            $this->line(now()->format('H:i:s').'  '.$count.' ping(s) written');

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            sleep($interval);
        } while (true);
    }

    private function pass(float $speedKph): int
    {
        $orders = Order::with(['seller.address', 'statusHistory'])
            ->where('status', 'In Transit')
            ->when($this->option('order'), fn ($q) => $q->where('order_number', ltrim((string) $this->option('order'), '#')))
            ->get();

        $written = 0;

        foreach ($orders as $order) {
            $origin = PhilippineGeo::locate(
                $order->seller?->address?->municipality_name ?? '',
                $order->seller?->address?->province_name ?? '',
            );
            $dest = PhilippineGeo::locate(
                (string) $order->shipping_municipality_name,
                (string) $order->shipping_province_name,
            );

            if (! $origin || ! $dest) {
                $this->warn("  {$order->order_number}: origin/destination not on the map — skipped");

                continue;
            }

            $startedAt = $order->statusHistory
                ->firstWhere('status', 'In Transit')?->created_at
                ?? $order->updated_at
                ?? now()->subMinutes(20);

            $minutes = max(0, now()->diffInMinutes($startedAt));
            $distanceKm = $this->haversineKm($origin, $dest);
            $frac = $distanceKm > 0 ? min(0.985, ($minutes / 60 * $speedKph) / $distanceKm) : 0.985;

            // Linear interpolation + a small sideways wander that fades out
            // near both ends so the marker still lands on the pins.
            $wander = 0.012 * sin($minutes / 3) * (1 - abs(2 * $frac - 1));
            $lat = $origin['lat'] + ($dest['lat'] - $origin['lat']) * $frac - $wander;
            $lng = $origin['lng'] + ($dest['lng'] - $origin['lng']) * $frac + $wander;

            ParcelLocation::create([
                'order_id' => $order->id,
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
                'recorded_at' => now(),
                'source' => 'simulator',
                'speed_kph' => round($speedKph + sin($minutes) * 6, 1),
                'heading' => (int) round($this->bearing($origin, $dest)) % 360,
                'note' => 'simulated',
            ]);

            $written++;
            $this->line(sprintf('  %s: %.0f%% of %.0f km', $order->order_number, $frac * 100, $distanceKm));
        }

        return $written;
    }

    private function haversineKm(array $a, array $b): float
    {
        $r = 6371;
        $dLat = deg2rad($b['lat'] - $a['lat']);
        $dLng = deg2rad($b['lng'] - $a['lng']);
        $s = sin($dLat / 2) ** 2
            + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($s), sqrt(1 - $s));
    }

    private function bearing(array $a, array $b): float
    {
        $y = sin(deg2rad($b['lng'] - $a['lng'])) * cos(deg2rad($b['lat']));
        $x = cos(deg2rad($a['lat'])) * sin(deg2rad($b['lat']))
            - sin(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * cos(deg2rad($b['lng'] - $a['lng']));

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }
}
