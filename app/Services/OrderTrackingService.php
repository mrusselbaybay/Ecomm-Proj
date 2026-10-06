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

    /** Pings read for the whole-journey trail (hard cap on the query). */
    private const TRAIL_FETCH_MAX = 3000;

    /** Points actually sent: the full trail, evenly thinned to this. */
    private const TRAIL_MAX_POINTS = 300;

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

    /**
     * @param  'seller'|'buyer'  $viewer  A buyer only sees the seller's pickup
     *                                    point at town level (it may be a home).
     */
    public function journey(Order $order, string $viewer = 'seller'): array
    {
        $this->viewer = $viewer;

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
            // Forward delivery done and no return under way (during a return
            // the order stays "Delivered", but the parcel is moving again).
            'delivered' => $currentStatus === 'Delivered' && ! $route['isReturn'],
            'returned' => $route['isReturn'] && $route['reachedIndex'] === count($stops) - 1,
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
            'trail' => $this->thin($pings->reverse()->values())->map(fn (ParcelLocation $p) => [
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
            // The original delivery (seller -> buyer), shown as history during a return.
            'previousStops' => $route['previousStops'],
            'reachedIndex' => $route['reachedIndex'],
            // Leg i runs stops[i] -> stops[i + 1]; null when not moving.
            'activeLeg' => $route['activeLeg'],
            'isReturn' => $route['isReturn'],
            'statusLabel' => $cancelled ? 'Cancelled' : $route['label'],
            // Worth polling: the parcel can still change stop (incl. a
            // return in progress after delivery).
            'active' => ! $cancelled && $mappable && $route['reachedIndex'] < count($stops) - 1
                && ($route['hasChain'] || in_array($currentStatus, ['Confirmed', 'Processing', 'Packed', 'Ready for Pickup', 'In Transit'], true)),
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

    private string $viewer = 'seller';

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
                ->take(self::TRAIL_FETCH_MAX);
        }

        if (! self::$pingsTableExists && ! Schema::hasTable('parcel_locations')) {
            return collect();
        }

        self::$pingsTableExists = true;

        // The whole journey, not just the last day: the trail is the
        // history of where the parcel actually went.
        return $order->parcelLocations()
            ->limit(self::TRAIL_FETCH_MAX)
            ->get(['lat', 'lng', 'recorded_at', 'source', 'speed_kph']);
    }

    /**
     * Evenly sample a long oldest-first trail down to TRAIL_MAX_POINTS,
     * always keeping the first and last ping so the line's ends are exact.
     */
    private function thin(Collection $pings): Collection
    {
        $count = $pings->count();

        if ($count <= self::TRAIL_MAX_POINTS) {
            return $pings;
        }

        $step = ($count - 1) / (self::TRAIL_MAX_POINTS - 1);

        return collect(range(0, self::TRAIL_MAX_POINTS - 1))
            ->map(fn (int $i) => $pings[(int) round($i * $step)]);
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

        // Several returns on one order (e.g. items sent back separately) each
        // have their own legs — follow only the latest one, never a mix.
        if ($returnRows->isNotEmpty()) {
            $latestReturnId = $returnRows->last()->return_request_id;
            $returnRows = $returnRows->where('return_request_id', $latestReturnId)->values();
        }

        $isReturn = $returnRows->isNotEmpty();
        $chain = $isReturn ? $returnRows : $rows->reject(fn (ParcelAssignment $r) => $r->isReturn())->values();

        $stops = $this->buildStops($order, $chain, $isReturn);

        // During a return, the original delivery stays on the map as
        // history (drawn faded underneath the return route).
        $previousStops = $isReturn
            // A return only exists once the original delivery happened.
            ? $this->buildStops($order, $rows->reject(fn (ParcelAssignment $r) => $r->isReturn())->values(), false, delivered: true)
            : [];

        // The furthest stop with a timestamp is where the parcel is; every
        // stop before it was necessarily passed, even if one of them never
        // got its own timestamp (so one gap can't freeze the marker).
        $reachedIndex = 0;
        foreach ($stops as $i => $stop) {
            if ($stop['at'] !== null) {
                $reachedIndex = $i;
            }
        }

        $activeLeg = null;
        $next = $stops[$reachedIndex + 1] ?? null;
        if (! $cancelled && $next && $this->isMoving($chain, $stops[$reachedIndex], $next)) {
            $activeLeg = $reachedIndex;
        }

        return [
            'stops' => $stops,
            'previousStops' => count($previousStops) > 1 ? $previousStops : [],
            'reachedIndex' => $reachedIndex,
            'activeLeg' => $activeLeg,
            'isReturn' => $isReturn,
            'hasChain' => $chain->isNotEmpty(),
            'allExact' => collect($stops)->every(fn (array $s) => $s['exact']),
            'label' => $this->label($stops, $reachedIndex, $activeLeg, $isReturn, $chain),
        ];
    }

    /**
     * Sender -> each hub that held the parcel -> receiver, each stamped with
     * when the parcel got there (null = not yet).
     *
     * @return list<array<string, mixed>>
     */
    private function buildStops(Order $order, Collection $chain, bool $isReturn, bool $delivered = false): array
    {
        $seller = $this->sellerPoint($order);
        $buyer = $this->buyerPoint($order);
        [$from, $to] = $isReturn ? [$buyer, $seller] : [$seller, $buyer];

        $stops = [];
        $push = function (?array $point, string $role, ?CarbonInterface $at) use (&$stops): void {
            if ($point) {
                $stops[] = [...$point, 'role' => $role, 'at' => $at?->toIso8601String()];
            }
        };

        $push($from, 'origin', ($isReturn ? $chain->first()?->created_at : $order->placed_at) ?? $order->created_at);

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
            ?? (! $isReturn && ($delivered || $order->status === 'Delivered')
                ? ($order->received_at ?? $order->updated_at)
                : null);
        $push($to, 'destination', $deliveredAt);

        return $stops;
    }

    /**
     * When this company had the parcel in its custody (shown at its hub).
     *
     * A return skips the For Inventory scan entirely (see
     * ParcelAutoAssignService::returnPickupBypass): collected from the buyer
     * it goes straight to handed_off, and a return transfer receipt is born
     * past the scan too. So for returns, custody = collected from the buyer
     * (first leg) or the receipt row existing (later legs).
     */
    private function hubArrival(ParcelAssignment $row): ?CarbonInterface
    {
        $isReceipt = $row->previous_assignment_id !== null
            || $row->inventory_origin === ParcelAssignment::INVENTORY_ORIGIN_TRANSFER_RECEIPT;

        return $row->inventory_scanned_at
            ?? ($isReceipt ? ($row->for_inventory_at ?? $row->created_at) : null)
            ?? ($row->isReturn() ? $row->handed_off_at : null)
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

    private function label(array $stops, int $reached, ?int $activeLeg, bool $isReturn, Collection $chain): string
    {
        $last = $chain->last();

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
            // Physically here (transfer receipt) but not scanned into inventory yet.
            return $last?->status === ParcelAssignment::STATUS_FOR_INVENTORY && ! $last->inventory_scanned_at
                ? 'Arrived at '.$here['name'].' — being checked in'
                : 'At '.$here['name'];
        }

        // Still at the sender: a courier dispatched to collect it?
        $first = $chain->first();
        $courierDispatched = $first?->status === ParcelAssignment::STATUS_ASSIGNED && $first->rider_profile_id !== null;

        return match (true) {
            $courierDispatched => $isReturn ? 'Courier on the way to collect your return' : 'Courier on the way to pickup',
            $isReturn => 'Waiting for return pickup',
            $first !== null => 'Waiting for courier pickup',
            default => 'Preparing order',
        };
    }

    /** Seller pickup point: pin snapshotted at checkout, else the seller's current pin, else town centre. */
    private function sellerPoint(Order $order): ?array
    {
        $address = $order->seller?->address;

        return $this->point(
            $this->viewer === 'buyer'
                ? null
                : (MapPin::from($order->pickup_latitude, $order->pickup_longitude) ?? $address?->mapPin()),
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
