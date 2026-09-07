<?php

namespace App\Services;

use App\Models\CourierApplication;
use App\Models\LogisticsCompany;
use App\Models\LogisticsDeliveryArea;
use App\Models\Order;
use App\Models\ParcelAssignment;
use App\Models\Profile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Auto assign" on the Parcel sorting page — routes one parcel without
 * staff picking an area or a rider by hand:
 *
 *   delivery address -> matching area -> available riders -> round-robin
 *
 * The area match is the *same* strict province+municipality lookup intake
 * already uses (ParcelIntakeService::matchingArea) — exact on both parts,
 * never a nearest-area guess. A parcel whose address no area covers is
 * left exactly as it was for staff to handle manually; it is never pushed
 * into an approximate area just to get it moving.
 *
 * Riders rotate per area, and the rotation survives across sweeps via
 * logistics_delivery_areas.last_auto_assigned_rider_profile_id. Riders
 * who are off shift, no longer accepted by the company, or already at
 * their parcel quota drop out of the rotation entirely rather than being
 * assigned and skipped later.
 *
 * The frontend calls this one parcel at a time so it can report progress
 * ("4 of 5 parcels assigned"), which is also why every call is
 * independently guarded: re-running it over a parcel that already has a
 * rider is a no-op, so a double-clicked sweep can't double-assign.
 */
class ParcelAutoAssignService
{
    /** A rider was matched and the parcel is now assigned to them. */
    public const OUTCOME_ASSIGNED = 'assigned';

    /** No area covers this address — area and rider both left alone. */
    public const OUTCOME_NO_AREA = 'no_area';

    /** Area matched and saved, but nobody in it can take the parcel. */
    public const OUTCOME_NO_RIDER = 'no_rider';

    /** Already assigned, or past the point where dispatch acts on it. */
    public const OUTCOME_SKIPPED = 'skipped';

    public function __construct(private readonly ParcelIntakeService $parcelIntake) {}

    /**
     * Route a single parcel. Everything happens under one transaction with
     * the parcel and area rows locked, so two staff members sweeping at
     * once can't hand the same parcel to two riders or read the same
     * rotation cursor twice.
     *
     * @return array{outcome: string, parcel: ParcelAssignment, message: string}
     */
    public function assign(
        ParcelAssignment $parcel,
        LogisticsCompany $company,
        string $actorProfileId,
    ): array {
        return DB::transaction(function () use ($parcel, $company, $actorProfileId): array {
            /** @var ParcelAssignment $locked */
            $locked = ParcelAssignment::query()
                ->whereKey($parcel->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->awaitsAutoAssignment($locked)) {
                return $this->result($locked, self::OUTCOME_SKIPPED, 'Already routed — nothing to do.');
            }

            $order = $locked->order;

            if (! $order) {
                return $this->result($locked, self::OUTCOME_NO_AREA, 'This parcel has no order on file to read an address from.');
            }

            // Re-matched from the address rather than trusting whatever
            // intake recorded: an area created (or re-scoped) after the
            // parcel arrived should now be found.
            $area = $this->parcelIntake->matchingArea($company, $order);

            if (! $area) {
                return $this->result(
                    $locked,
                    self::OUTCOME_NO_AREA,
                    $this->noAreaMessage($order),
                );
            }

            // Locked for the same reason the parcel is: the rotation
            // cursor is read and written below, and two concurrent sweeps
            // reading the same cursor would both pick the same rider.
            /** @var LogisticsDeliveryArea $area */
            $area = LogisticsDeliveryArea::query()
                ->whereKey($area->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->update(['delivery_area_id' => $area->id]);

            $riders = $this->eligibleRiders($area, $company);

            if ($riders->isEmpty()) {
                return $this->result(
                    $locked,
                    self::OUTCOME_NO_RIDER,
                    "Matched {$area->name}, but no rider there is available right now.",
                );
            }

            $rider = $this->nextRider($area, $riders);

            // Mirrors Api\Logistics\ParcelAssignmentController::assign: a
            // parcel already collected by the pickup courier stays
            // 'handed_off' (this is its delivery rider), everything else
            // moves to 'assigned' (this is its pickup courier).
            $isDeliveryDispatch = $locked->status === ParcelAssignment::STATUS_HANDED_OFF;

            $locked->update([
                'rider_profile_id' => $rider->id,
                'status' => $isDeliveryDispatch
                    ? ParcelAssignment::STATUS_HANDED_OFF
                    : ParcelAssignment::STATUS_ASSIGNED,
                'assigned_by' => $actorProfileId,
                'sorted_at' => $locked->sorted_at ?? now(),
                'assigned_at' => now(),
            ]);

            $area->update(['last_auto_assigned_rider_profile_id' => $rider->id]);

            return $this->result(
                $locked,
                self::OUTCOME_ASSIGNED,
                trim("{$rider->first_name} {$rider->last_name}")." assigned via {$area->name}.",
            );
        });
    }

    /**
     * Parcels auto-assignment will touch: still on this desk, and with no
     * rider on them yet.
     *
     * The "no rider yet" half is what makes a repeated sweep safe — a
     * parcel assigned by the previous click (or by hand) simply isn't a
     * candidate any more. Rows past this desk entirely (out for delivery,
     * offered to or handed to another company) are never candidates.
     */
    public function awaitsAutoAssignment(ParcelAssignment $parcel): bool
    {
        if (in_array($parcel->status, [
            ParcelAssignment::STATUS_TRANSFERRED,
            ParcelAssignment::STATUS_TRANSFER_PENDING,
        ], true)) {
            return false;
        }

        return $parcel->rider_profile_id === null;
    }

    /**
     * The area's appointed riders who can actually take a parcel right
     * now, oldest appointment first so the rotation order is stable
     * between sweeps.
     *
     * Three independent gates, all of which must pass:
     *   - still accepted by this company (a fired rider keeps their old
     *     area rows until someone tidies them up)
     *   - on shift (courier_details/driver_details.delivery_status)
     *   - under the parcel quota
     *
     * @return Collection<int, Profile>
     */
    public function eligibleRiders(LogisticsDeliveryArea $area, LogisticsCompany $company): Collection
    {
        $riders = $area->riders()
            // Area riders are appointed through a courier application, so
            // courier_details is the shift flag that matters here.
            // Profile::isAvailableForDelivery() still reads driver_details
            // for a 'driver'-role profile, just without preloading it.
            ->with('courierDetail')
            ->whereExists(function ($query) use ($company): void {
                $query->select(DB::raw('1'))
                    ->from('courier_applications')
                    ->whereColumn('courier_applications.courier_profile_id', 'profiles.id')
                    ->where('courier_applications.logistics_company_id', $company->id)
                    ->where('courier_applications.status', CourierApplication::STATUS_ACCEPTED);
            })
            ->orderBy('logistics_delivery_area_riders.created_at')
            ->orderBy('profiles.id')
            ->get();

        if ($riders->isEmpty()) {
            return $riders;
        }

        // One grouped query for the whole roster — this runs once per
        // parcel during a sweep, so a per-rider count would be N*M.
        $active = ParcelAssignment::activeCountsFor($riders->pluck('id')->all());

        return $riders
            ->filter(fn (Profile $rider): bool => $rider->isAvailableForDelivery())
            ->filter(fn (Profile $rider): bool => ($active[$rider->id] ?? 0) < ParcelAssignment::COURIER_QUOTA)
            ->values();
    }

    /**
     * The next rider in this area's rotation: whoever follows the last
     * one auto-assigned here, wrapping at the end.
     *
     * A cursor pointing at somebody no longer eligible (off shift, at
     * quota, left the area) isn't found in the list, and the rotation
     * restarts from the top — which is also the first-ever-run case.
     *
     * @param  Collection<int, Profile>  $riders
     */
    private function nextRider(LogisticsDeliveryArea $area, Collection $riders): Profile
    {
        $cursor = $area->last_auto_assigned_rider_profile_id;
        $position = $riders->search(fn (Profile $rider): bool => $rider->id === $cursor);

        $next = $position === false
            ? 0
            : ($position + 1) % $riders->count();

        return $riders[$next];
    }

    /**
     * Says which part of the address had no coverage, so staff can fix
     * the area list instead of guessing why the parcel was passed over.
     */
    private function noAreaMessage(Order $order): string
    {
        $where = collect([$order->shipping_municipality_name, $order->shipping_province_name])
            ->filter()
            ->implode(', ');

        if (! filled($where)) {
            return 'No logistics area is available for this address — the order has no province or municipality on file.';
        }

        return "No logistics area is available for this address ({$where}).";
    }

    /**
     * @return array{outcome: string, parcel: ParcelAssignment, message: string}
     */
    private function result(ParcelAssignment $parcel, string $outcome, string $message): array
    {
        return [
            'outcome' => $outcome,
            'parcel' => $parcel,
            'message' => $message,
        ];
    }
}
