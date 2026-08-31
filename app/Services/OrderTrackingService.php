<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\ParcelLocation;
use App\Support\PhilippineGeo;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the payload for the shared order tracking map
 * (resources/js/shared/OrderJourneyMap.vue), used by both the buyer and
 * seller Order Details screens.
 *
 * Two modes, chosen per request:
 *
 *   - LIVE: if parcel_locations has a recent ping for a moving order, the
 *     marker sits on that real coordinate and a breadcrumb trail is drawn.
 *     Pings come from `tracking:simulate` today, or a real courier client
 *     POSTing to /api/logistics/orders/{n}/location once one exists.
 *   - ESTIMATED (fallback): no recent ping -> the marker is INTERPOLATED
 *     between origin and destination from how far the order has moved
 *     through its status workflow. The payload says so (`estimated: true`,
 *     `live: false`, plus `disclaimer`).
 *
 * Milestone rows + times always come straight from order_status_history
 * and are exact in both modes. Origin/destination pins are the seller's
 * and buyer's city/province at an approximate centroid (PhilippineGeo).
 */
class OrderTrackingService
{
    /** A ping older than this (minutes) no longer counts as "live". */
    private const LIVE_WINDOW_MINUTES = 15;

    /** Trail pings older than this (hours) are not drawn. */
    private const TRAIL_WINDOW_HOURS = 24;

    private const TRAIL_MAX_POINTS = 60;

    /**
     * Ordered journey phases mapped onto Order::STATUSES. Each carries the
     * fraction of the origin->destination route the parcel is assumed to
     * have covered once the order reaches that status.
     */
    private const PHASES = [
        ['key' => 'placed', 'label' => 'Order placed', 'status' => 'New', 'progress' => 0.02],
        ['key' => 'processing', 'label' => 'Packed by seller', 'status' => 'Processing', 'progress' => 0.14],
        ['key' => 'in_transit', 'label' => 'In transit', 'status' => 'In Transit', 'progress' => 0.62],
        ['key' => 'delivered', 'label' => 'Delivered', 'status' => 'Delivered', 'progress' => 1.0],
    ];

    public function journey(Order $order): array
    {
        $history = $order->relationLoaded('statusHistory')
            ? $order->statusHistory
            : $order->statusHistory()->orderBy('created_at')->get();

        $reachedAt = [];
        foreach ($history as $entry) {
            /** @var OrderStatusHistory $entry */
            $reachedAt[$entry->status] ??= optional($entry->created_at)->toIso8601String();
        }

        $currentStatus = $order->status;
        $cancelled = $currentStatus === 'Cancelled';

        // Where the workflow currently sits (ignoring Cancelled, which is
        // off to the side of the New->Delivered line).
        $currentIndex = $this->indexOfStatus($cancelled ? $this->lastReachedStatus($history) : $currentStatus);

        $phases = [];
        foreach (self::PHASES as $i => $phase) {
            $phases[] = [
                'key' => $phase['key'],
                'label' => $phase['label'],
                'status' => $phase['status'],
                'reached' => $i <= $currentIndex,
                'current' => $i === $currentIndex && ! $cancelled,
                'at' => $reachedAt[$phase['status']] ?? null,
            ];
        }

        $progress = $currentIndex >= 0 ? self::PHASES[$currentIndex]['progress'] : 0.0;

        $origin = $this->resolveOrigin($order);
        $destination = $this->resolveDestination($order);
        $mappable = $origin !== null && $destination !== null;

        // Real GPS pings, if any. A ping counts as "live" only while the
        // order is actually moving and the ping is recent; otherwise we
        // fall back to the status-derived estimate but still draw the
        // trail we have.
        $pings = $this->recentPings($order);
        $latest = $pings->first();
        $movingStatus = in_array($currentStatus, ['Processing', 'In Transit'], true);
        $live = $latest !== null
            && $movingStatus
            && $latest->recorded_at->gt(now()->subMinutes(self::LIVE_WINDOW_MINUTES));

        $estimatedParcel = $mappable
            ? $this->interpolate($origin, $destination, $cancelled ? min($progress, 0.62) : $progress)
            : null;

        $parcel = $live
            ? ['lat' => round($latest->lat, 6), 'lng' => round($latest->lng, 6)]
            : $estimatedParcel;

        return [
            'currentStatus' => $currentStatus,
            'cancelled' => $cancelled,
            'delivered' => $currentStatus === 'Delivered',
            'progress' => round($progress, 3),
            'live' => $live,
            // False only when the dot is a real recent ping, or is simply
            // sitting on the origin (pre-dispatch) / destination (delivered).
            'estimated' => ! $live && ! in_array($currentStatus, ['New', 'Delivered', 'Cancelled'], true),
            'lastPingAt' => optional($latest?->recorded_at)->toIso8601String(),
            'pingSource' => $live ? $latest->source : null,
            'speedKph' => $live ? $latest->speed_kph : null,
            'trail' => $pings->reverse()->values()->map(fn (ParcelLocation $p) => [
                'lat' => round($p->lat, 6),
                'lng' => round($p->lng, 6),
                'at' => optional($p->recorded_at)->toIso8601String(),
            ])->all(),
            'phases' => $phases,
            'origin' => $origin,
            'destination' => $destination,
            'mappable' => $mappable,
            'parcel' => $parcel,
            'trackingNumber' => $order->tracking_number,
            'carrier' => $order->shipping_carrier,
            'disclaimer' => $live
                ? 'Live location from the courier. Milestone times are exact.'
                : 'Location is estimated from the order\'s progress — no live signal right now. Milestone times are exact.',
        ];
    }

    /**
     * Memoised once true, so we stop probing the schema — but a process
     * that started before the migration still picks the table up.
     */
    private static bool $pingsTableExists = false;

    /**
     * The newest pings for this order, capped and time-bounded so a stale
     * trail from days ago never renders. Newest first.
     *
     * Degrades to "no pings" (estimated mode) if the parcel_locations
     * migration hasn't been run yet, so an un-migrated environment still
     * gets a working Order Details page.
     */
    private function recentPings(Order $order)
    {
        if ($order->relationLoaded('parcelLocations')) {
            return $order->parcelLocations
                ->sortByDesc('recorded_at')
                ->values()
                ->take(self::TRAIL_MAX_POINTS);
        }

        if (! self::$pingsTableExists && ! Schema::hasTable('parcel_locations')) {
            return collect();
        }

        self::$pingsTableExists = true;

        return $order->parcelLocations()
            ->where('recorded_at', '>=', now()->subHours(self::TRAIL_WINDOW_HOURS))
            ->limit(self::TRAIL_MAX_POINTS)
            ->get();
    }

    private function resolveOrigin(Order $order): ?array
    {
        $seller = $order->seller;
        $address = $seller?->address;

        $point = PhilippineGeo::locate(
            $address->municipality_name ?? '',
            $address->province_name ?? '',
        );

        if (! $point) {
            return null;
        }

        $point['name'] = $seller?->sellerDetail?->business_name
            ?? trim(($address->municipality_name ?? '').', '.($address->province_name ?? ''), ', ')
            ?: 'Seller';
        $point['role'] = 'origin';

        return $point;
    }

    private function resolveDestination(Order $order): ?array
    {
        $point = PhilippineGeo::locate(
            (string) $order->shipping_municipality_name,
            (string) $order->shipping_province_name,
        );

        if (! $point) {
            return null;
        }

        $point['name'] = trim(
            ($order->shipping_municipality_name ?? '').', '.($order->shipping_province_name ?? ''),
            ', ',
        ) ?: ($order->recipient_name ?? 'Destination');
        $point['role'] = 'destination';

        return $point;
    }

    private function interpolate(array $from, array $to, float $t): array
    {
        $t = max(0.0, min(1.0, $t));

        return [
            'lat' => round($from['lat'] + ($to['lat'] - $from['lat']) * $t, 5),
            'lng' => round($from['lng'] + ($to['lng'] - $from['lng']) * $t, 5),
        ];
    }

    private function indexOfStatus(?string $status): int
    {
        foreach (self::PHASES as $i => $phase) {
            if ($phase['status'] === $status) {
                return $i;
            }
        }

        return $status === null ? -1 : 0;
    }

    private function lastReachedStatus($history): ?string
    {
        $order = ['New' => 0, 'Processing' => 1, 'In Transit' => 2, 'Delivered' => 3];
        $best = null;
        $bestRank = -1;

        foreach ($history as $entry) {
            $rank = $order[$entry->status] ?? -1;
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = $entry->status;
            }
        }

        return $best;
    }
}
