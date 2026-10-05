<?php

namespace App\Services;

use App\Models\Address;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\ParcelAssignment;
use App\Models\ParcelLocation;
use App\Support\MapPin;
use App\Support\PhilippineGeo;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
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
 * and are exact in both modes.
 *
 * STOPS: the parcel's route is seller -> each logistics hub that held it ->
 * buyer (reversed for a return), read from the parcel_assignments chain.
 * Every stop sits on that party's pinned address (snapshotted on the order
 * for seller/buyer) or, without a pin, its town centroid (PhilippineGeo,
 * `exact: false`). With no live ping the parcel is drawn AT the last stop
 * it reached - never guessed mid-road - and `activeLeg` marks the leg it
 * is travelling on.
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

        $route = $this->route($order, $cancelled);
        $stops = $route['stops'];
        $origin = $stops[0] ?? null;
        $destination = count($stops) > 1 ? $stops[count($stops) - 1] : null;
        $mappable = ($origin['role'] ?? null) === 'origin' && ($destination['role'] ?? null) === 'destination';

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

        // No custody chain yet on an order already marked In Transit
        // (legacy/simulated data): keep the old status-progress
        // interpolation. Otherwise the parcel sits on the last stop reached.
        $interpolated = $mappable && ! $route['hasChain'] && $currentStatus === 'In Transit';
        $reachedStop = $stops[$route['reachedIndex']] ?? null;

        $parcel = match (true) {
            $live => ['lat' => round($latest->lat, 6), 'lng' => round($latest->lng, 6)],
            ! $mappable => null,
            $interpolated => $this->interpolate($origin, $destination, $progress),
            default => ['lat' => $reachedStop['lat'], 'lng' => $reachedStop['lng']],
        };

        return [
            'currentStatus' => $currentStatus,
            'cancelled' => $cancelled,
            'delivered' => $currentStatus === 'Delivered',
            'progress' => round($progress, 3),
            'live' => $live,
            // False only when the dot is a real recent ping, or is simply
            // sitting on the origin (pre-dispatch) / destination (delivered).
            // True when the dot isn't the parcel's real position: an
            // interpolated guess, or "last stop reached" while it's
            // actually on the road to the next one.
            'estimated' => ! $live && ($interpolated || $route['activeLeg'] !== null),
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
            'stops' => $stops,
            'reachedIndex' => $route['reachedIndex'],
            // Leg i runs stops[i] -> stops[i + 1]; null when not moving.
            'activeLeg' => $route['activeLeg'],
            'isReturn' => $route['isReturn'],
            'statusLabel' => $cancelled ? 'Cancelled' : $route['label'],
            'trackingNumber' => $order->tracking_number,
            'carrier' => $order->shipping_carrier,
            'disclaimer' => match (true) {
                $live => 'Live location from the courier. Milestone times are exact.',
                $interpolated => 'Location is estimated from the order\'s progress — no live signal right now. Milestone times are exact.',
                $route['allExact'] => 'Stops are pinned addresses; the parcel is shown at the last stop it reached. Milestone times are exact.',
                default => 'Stops without a map pin are shown at their town centre. Milestone times are exact.',
            },
        ];
    }

    /**
     * Memoised once true, so we stop probing the schema — but a process
     * that started before the migration still picks the table up.
     */
    private static bool $pingsTableExists = false;

    private bool $hubAddresses = true;

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

    /**
     * @return array{stops: list<array<string, mixed>>, reachedIndex: int, activeLeg: ?int,
     *               isReturn: bool, hasChain: bool, allExact: bool, label: string}
     */
    private function route(Order $order, bool $cancelled): array
    {
        $query = fn (array $with) => ParcelAssignment::query()
            ->where('order_id', $order->id)
            ->with($with)
            ->orderBy('created_at')
            ->get();

        // Hub addresses need addresses.logistics_company_id; an older schema
        // (e.g. the hand-made test tables) without it maps hubs by nothing
        // rather than failing the whole Order Details page.
        try {
            $rows = $query(['logisticsCompany.address', 'transferToCompany.address']);
            $this->hubAddresses = true;
        } catch (QueryException) {
            $rows = $query(['logisticsCompany', 'transferToCompany']);
            $this->hubAddresses = false;
        }

        // A return, once started, is the journey that matters now.
        $returnRows = $rows->filter(fn (ParcelAssignment $r) => $r->isReturn())->values();
        $isReturn = $returnRows->isNotEmpty();
        $chain = $isReturn ? $returnRows : $rows->reject(fn (ParcelAssignment $r) => $r->isReturn())->values();

        $seller = $this->sellerPoint($order);
        $buyer = $this->buyerPoint($order);
        [$from, $to] = $isReturn ? [$buyer, $seller] : [$seller, $buyer];

        $stops = [];
        $push = function (?array $point, string $role, ?CarbonInterface $at) use (&$stops): void {
            if ($point) {
                $stops[] = [...$point, 'role' => $role, 'at' => $at?->toIso8601String()];
            }
        };

        $push($from, 'origin', ($isReturn ? $chain->first()->created_at : $order->placed_at) ?? $order->created_at);

        $lastCompanyId = null;
        foreach ($chain as $row) {
            if ($row->logistics_company_id !== $lastCompanyId) {
                $push($this->hubPoint($row->logisticsCompany), 'hub', $this->hubArrival($row));
                $lastCompanyId = $row->logistics_company_id;
            }
        }

        // Transfer agreed but the receiving hub has no row yet -> show it as the next stop.
        $last = $chain->last();
        if ($last && $last->transfer_to_company_id && $last->transfer_to_company_id !== $lastCompanyId
            && in_array($last->status, [
                ParcelAssignment::STATUS_TRANSFER_ONGOING,
                ParcelAssignment::STATUS_TRANSFER_ASSIGNED,
                ParcelAssignment::STATUS_READY_TO_TRANSFER,
            ], true)) {
            $push($this->hubPoint($last->transferToCompany), 'hub', null);
        }

        $deliveredAt = $chain->pluck('delivered_at')->filter()->last()
            ?? (! $isReturn && $order->status === 'Delivered' ? ($order->received_at ?? $order->updated_at) : null);
        $push($to, 'destination', $deliveredAt);

        // Stops are reached in order - a later timestamp can't skip an unreached hub.
        $reachedIndex = 0;
        foreach ($stops as $i => $stop) {
            if ($stop['at'] === null) {
                break;
            }
            $reachedIndex = $i;
        }

        $activeLeg = null;
        $next = $stops[$reachedIndex + 1] ?? null;
        if (! $cancelled && $next && $this->isMoving($chain, $stops[$reachedIndex], $next)) {
            $activeLeg = $reachedIndex;
        }

        return [
            'stops' => $stops,
            'reachedIndex' => $reachedIndex,
            'activeLeg' => $activeLeg,
            'isReturn' => $isReturn,
            'hasChain' => $chain->isNotEmpty(),
            'allExact' => collect($stops)->every(fn (array $s) => $s['exact']),
            'label' => $this->label($stops, $reachedIndex, $activeLeg, $isReturn, $chain->isNotEmpty()),
        ];
    }

    /** When this company physically had the parcel at its hub. */
    private function hubArrival(ParcelAssignment $row): ?CarbonInterface
    {
        return $row->inventory_scanned_at
            ?? ($row->inventory_origin === ParcelAssignment::INVENTORY_ORIGIN_TRANSFER_RECEIPT
                ? ($row->for_inventory_at ?? $row->created_at)
                : null)
            ?? $row->transferred_at;
    }

    /** Is the parcel on the road from $at to $next right now? */
    private function isMoving(Collection $chain, array $at, array $next): bool
    {
        $first = $chain->first();
        $last = $chain->last();

        if (! $last) {
            return false;
        }

        if ($at['role'] === 'origin') {
            // Collected from the sender, not yet scanned in at the first hub.
            return $first->for_inventory_at !== null || $first->handed_off_at !== null;
        }

        if ($next['role'] === 'hub') {
            // Transfer courier carrying it to the next company's hub.
            return $last->status === ParcelAssignment::STATUS_READY_TO_TRANSFER;
        }

        // Last-mile rider dispatched after the parcel was scanned in.
        $arrived = $this->hubArrival($last);

        return $last->rider_profile_id !== null
            && $last->assigned_at !== null
            && $arrived !== null
            && $last->assigned_at->gte($arrived)
            && in_array($last->status, [ParcelAssignment::STATUS_ASSIGNED, ParcelAssignment::STATUS_HANDED_OFF], true);
    }

    private function label(array $stops, int $reached, ?int $activeLeg, bool $isReturn, bool $hasChain): string
    {
        $here = $stops[$reached] ?? null;

        if (! $here) {
            return 'Tracking unavailable';
        }

        if ($here['role'] === 'destination') {
            return $isReturn ? 'Returned to seller' : 'Delivered';
        }

        if ($activeLeg !== null) {
            $next = $stops[$activeLeg + 1];

            return match (true) {
                $next['role'] === 'destination' => $isReturn ? 'On the way back to the seller' : 'Out for delivery',
                $here['role'] === 'origin' => 'Picked up — heading to '.$next['name'],
                default => 'In transfer to '.$next['name'],
            };
        }

        if ($here['role'] === 'hub') {
            return 'At '.$here['name'];
        }

        return match (true) {
            $isReturn => 'Waiting for return pickup',
            $hasChain => 'Waiting for courier pickup',
            default => 'Preparing order',
        };
    }

    /** Seller pickup point: pin snapshotted at checkout, else the seller's current pin, else town centre. */
    private function sellerPoint(Order $order): ?array
    {
        $address = $order->seller?->address;

        return $this->point(
            MapPin::from($order->pickup_latitude, $order->pickup_longitude) ?? $address?->mapPin(),
            $order->pickup_municipality_name ?: $address?->municipality_name,
            $order->pickup_province_name ?: $address?->province_name,
            $order->seller?->sellerDetail?->business_name ?: 'Seller',
        );
    }

    private function buyerPoint(Order $order): ?array
    {
        $area = trim(($order->shipping_municipality_name ?? '').', '.($order->shipping_province_name ?? ''), ', ');

        return $this->point(
            MapPin::from($order->shipping_latitude, $order->shipping_longitude),
            $order->shipping_municipality_name,
            $order->shipping_province_name,
            $area ?: ($order->recipient_name ?? 'Destination'),
        );
    }

    private function hubPoint(?LogisticsCompany $company): ?array
    {
        if (! $company) {
            return null;
        }

        /** @var Address|null $address */
        $address = $this->hubAddresses ? $company->address : null;

        return $this->point(
            $address?->mapPin(),
            $address?->municipality_name,
            $address?->province_name,
            $company->company_name ?: 'Logistics hub',
        );
    }

    private function point(?array $pin, ?string $municipality, ?string $province, string $name): ?array
    {
        $exact = $pin !== null;
        $pin ??= PhilippineGeo::locate((string) $municipality, (string) $province);

        if (! $pin) {
            return null;
        }

        return ['lat' => (float) $pin['lat'], 'lng' => (float) $pin['lng'], 'name' => $name, 'exact' => $exact];
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
