<?php

namespace App\Services;

use App\Models\CourierApplication;
use App\Models\LogisticsBarangayAssignment;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\ParcelAssignment;
use App\Models\Profile;
use App\Support\VehicleCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Auto assign" on the Parcel sorting page — routes one parcel without
 * staff picking a rider by hand:
 *
 *   delivery address -> barangay's assigned rider (direct)
 *                     -> or, if nobody's assigned there / they're not
 *                        eligible right now, the company-wide pool
 *                        (round robin)
 *
 * The barangay match is the *same* strict municipality+barangay lookup
 * intake already uses (ParcelIntakeService::matchingBarangayAssignment) —
 * exact on both parts, never a nearest-barangay guess. A parcel whose
 * address no assignment covers still isn't stuck — it just skips straight
 * to the company-wide pool, same as a barangay with nobody assigned.
 *
 * A parcel flagged with a required vehicle type (crosses a municipality,
 * province, or region boundary — see TransferTriggerService) only
 * considers riders whose declared vehicle can carry it, on both the direct
 * and fallback paths.
 *
 * The company-wide pool rotates via
 * logistics_companies.last_auto_assigned_rider_profile_id, a single
 * cursor shared by every barangay that falls through to it (unlike the
 * old per-area cursor, there's no per-barangay rotation to maintain since
 * each barangay has at most one assigned rider).
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

    /** The order has no usable address at all — nothing to match against. */
    public const OUTCOME_NO_AREA = 'no_area';

    /** An address was matched (or not) but nobody eligible can take it. */
    public const OUTCOME_NO_RIDER = 'no_rider';

    /** Already assigned, or past the point where dispatch acts on it. */
    public const OUTCOME_SKIPPED = 'skipped';

    public function __construct(private readonly ParcelIntakeService $parcelIntake) {}

    /**
     * Route a single parcel. Everything happens under one transaction with
     * the parcel (and, when the fallback pool is used, the company) rows
     * locked, so two staff members sweeping at once can't hand the same
     * parcel to two riders or read the same rotation cursor twice.
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

            if (! filled($order->shipping_municipality_name) || ! filled($order->shipping_barangay)) {
                return $this->result($locked, self::OUTCOME_NO_AREA, $this->noAddressMessage($order));
            }

            // Re-matched from the address rather than trusting whatever
            // intake recorded: an assignment created (or re-scoped) after
            // the parcel arrived should now be found.
            $assignment = $this->parcelIntake->matchingBarangayAssignment($company, $order);

            $locked->update(['barangay_assignment_id' => $assignment?->id]);

            $rider = $assignment ? $this->eligibleDirectRider($assignment, $company, $locked) : null;
            $viaPool = false;

            if (! $rider) {
                $viaPool = true;

                // Locked for the same reason the parcel is: the rotation
                // cursor is read and written below, and two concurrent
                // sweeps reading the same cursor would both pick the same
                // rider.
                /** @var LogisticsCompany $company */
                $company = LogisticsCompany::query()
                    ->whereKey($company->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $pool = $this->eligibleCompanyRiders($company, $locked);

                if ($pool->isEmpty()) {
                    return $this->result(
                        $locked,
                        self::OUTCOME_NO_RIDER,
                        $assignment
                            ? "No rider is assigned to {$assignment->barangay}, {$assignment->municipality_name}, and nobody else is available right now."
                            : 'No barangay assignment covers this address, and nobody is available right now.',
                    );
                }

                $rider = $this->nextCompanyRider($company, $pool);
                $company->update(['last_auto_assigned_rider_profile_id' => $rider->id]);
            }

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

            $riderName = trim("{$rider->first_name} {$rider->last_name}");

            return $this->result(
                $locked,
                self::OUTCOME_ASSIGNED,
                $viaPool
                    ? "{$riderName} assigned from the company-wide pool."
                    : "{$riderName} assigned via {$assignment->barangay}, {$assignment->municipality_name}.",
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
     * The barangay's sole assigned rider, if they can actually take this
     * parcel right now: still accepted by the company, on shift, under
     * quota, and — when the parcel needs a specific vehicle — driving one
     * that qualifies. Any failed gate falls through to the company-wide
     * pool rather than leaving the parcel stuck, per the class docblock.
     */
    private function eligibleDirectRider(
        LogisticsBarangayAssignment $assignment,
        LogisticsCompany $company,
        ParcelAssignment $parcel,
    ): ?Profile {
        if (! $assignment->rider_profile_id) {
            return null;
        }

        $rider = Profile::query()
            ->with('courierDetail')
            ->whereExists(function ($query) use ($company): void {
                $query->select(DB::raw('1'))
                    ->from('courier_applications')
                    ->whereColumn('courier_applications.courier_profile_id', 'profiles.id')
                    ->where('courier_applications.logistics_company_id', $company->id)
                    ->where('courier_applications.status', CourierApplication::STATUS_ACCEPTED);
            })
            ->find($assignment->rider_profile_id);

        if (! $rider || ! $rider->isAvailableForDelivery()) {
            return null;
        }

        if (ParcelAssignment::activeCountFor($rider->id) >= ParcelAssignment::COURIER_QUOTA) {
            return null;
        }

        if (! VehicleCategory::satisfies($parcel->required_vehicle_type, $rider->vehicleLabel())) {
            return null;
        }

        return $rider;
    }

    /**
     * Every rider of this company who can actually take a parcel right
     * now, ordered stably (by profile id — there's no more per-area
     * appointment order once assignment is one rider per barangay) so
     * the rotation is predictable between sweeps.
     *
     * Same three gates the direct path uses, plus the vehicle filter when
     * the parcel needs one.
     *
     * @return Collection<int, Profile>
     */
    public function eligibleCompanyRiders(LogisticsCompany $company, ParcelAssignment $parcel): Collection
    {
        $riders = Profile::query()
            ->with('courierDetail')
            ->whereExists(function ($query) use ($company): void {
                $query->select(DB::raw('1'))
                    ->from('courier_applications')
                    ->whereColumn('courier_applications.courier_profile_id', 'profiles.id')
                    ->where('courier_applications.logistics_company_id', $company->id)
                    ->where('courier_applications.status', CourierApplication::STATUS_ACCEPTED);
            })
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
            ->filter(fn (Profile $rider): bool => VehicleCategory::satisfies($parcel->required_vehicle_type, $rider->vehicleLabel()))
            ->values();
    }

    /**
     * The next rider in the company-wide rotation: whoever follows the
     * last one auto-assigned via this pool, wrapping at the end.
     *
     * A cursor pointing at somebody no longer eligible (off shift, at
     * quota, no longer accepted) isn't found in the list, and the
     * rotation restarts from the top — which is also the first-ever-run
     * case.
     *
     * @param  Collection<int, Profile>  $riders
     */
    private function nextCompanyRider(LogisticsCompany $company, Collection $riders): Profile
    {
        $cursor = $company->last_auto_assigned_rider_profile_id;
        $position = $riders->search(fn (Profile $rider): bool => $rider->id === $cursor);

        $next = $position === false
            ? 0
            : ($position + 1) % $riders->count();

        return $riders[$next];
    }

    /**
     * Says why nothing could be matched, so staff can fix the order's
     * address instead of guessing why the parcel was passed over.
     */
    private function noAddressMessage(Order $order): string
    {
        $where = collect([$order->shipping_barangay, $order->shipping_municipality_name, $order->shipping_province_name])
            ->filter()
            ->implode(', ');

        if (! filled($where)) {
            return 'This order has no barangay or municipality on file to route by.';
        }

        return "This order's address ({$where}) is missing a barangay or municipality — check the buyer's saved address.";
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
