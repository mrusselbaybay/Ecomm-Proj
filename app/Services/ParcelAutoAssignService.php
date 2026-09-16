<?php

namespace App\Services;

use App\Models\CourierApplication;
use App\Models\LogisticsBarangayAssignment;
use App\Models\LogisticsCompany;
use App\Models\LogisticsProvincialAssignment;
use App\Models\LogisticsRegionalAssignment;
use App\Models\Order;
use App\Models\ParcelAssignment;
use App\Models\Profile;
use App\Support\VehicleCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Routes a parcel to a rider without staff picking one by hand. Runs
 * automatically the moment a parcel enters the queue (see
 * ParcelIntakeService::intake()) — there's no manual "Auto assign" button
 * any more, so this has to get it right the first time:
 *
 *   1. Barangay — the exact municipality+barangay match's assigned rider,
 *      if they're eligible right now (ParcelIntakeService::
 *      matchingBarangayAssignmentFor, exact on both parts, never a
 *      nearest-barangay guess).
 *   2. Provincial pool — the order ships within the company's own
 *      province (see App\Services\ServiceAreaProvisioner) but no barangay
 *      covers the exact municipality, or its rider isn't eligible right
 *      now. Car/van/truck riders only.
 *   3. Regional pool — the order ships outside the company's own region
 *      entirely. Van/truck riders only.
 *   4. Company-wide pool — everyone else, the final catch-all, unchanged
 *      from before this tiering existed.
 *
 * Every tier is independently gated the same way: still accepted by the
 * company, on shift, under quota, and — when the parcel needs a specific
 * vehicle (TransferTriggerService's municipality/province/region
 * boundary triggers) — driving one that qualifies. A failed gate falls
 * through to the next tier rather than leaving the parcel stuck.
 *
 * Each pool rotates independently via its own
 * last_auto_assigned_rider_profile_id cursor.
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
     * Route a single parcel already in the queue. Everything happens
     * under one transaction with the parcel (and, when a pool is used,
     * that pool's row) locked, so two staff members sweeping at once
     * can't hand the same parcel to two riders or read the same rotation
     * cursor twice.
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

            // Mirrors Api\Logistics\ParcelAssignmentController::assign: a
            // parcel already collected by the pickup courier stays
            // 'handed_off' (this is its delivery rider — match the
            // BUYER's address), everything else is still waiting to be
            // collected (this is its pickup courier — match the
            // SELLER's address instead). See matchRider().
            $isDeliveryDispatch = $locked->status === ParcelAssignment::STATUS_HANDED_OFF;
            $isPickupPhase = ! $isDeliveryDispatch;

            $municipality = $isPickupPhase ? $order->pickup_municipality_name : $order->shipping_municipality_name;
            $barangay = $isPickupPhase ? $order->pickup_barangay : $order->shipping_barangay;

            if (! filled($municipality) || ! filled($barangay)) {
                return $this->result($locked, self::OUTCOME_NO_AREA, $this->noAddressMessage($isPickupPhase, $order));
            }

            $match = $this->matchRider($order, $company, $locked->required_vehicle_type, $isPickupPhase);

            $locked->update(['barangay_assignment_id' => $match['barangayAssignment']?->id]);

            if (! $match['rider']) {
                return $this->result(
                    $locked,
                    self::OUTCOME_NO_RIDER,
                    $match['barangayAssignment']
                        ? "No rider is assigned to {$match['barangayAssignment']->barangay}, {$match['barangayAssignment']->municipality_name}, and nobody else is available right now."
                        : 'No barangay assignment covers this address, and nobody is available right now.',
                );
            }

            $locked->update([
                'rider_profile_id' => $match['rider']->id,
                'status' => $isDeliveryDispatch
                    ? ParcelAssignment::STATUS_HANDED_OFF
                    : ParcelAssignment::STATUS_ASSIGNED,
                'assigned_by' => $actorProfileId,
                'sorted_at' => $locked->sorted_at ?? now(),
                'assigned_at' => now(),
            ]);

            $riderName = trim("{$match['rider']->first_name} {$match['rider']->last_name}");

            return $this->result(
                $locked,
                self::OUTCOME_ASSIGNED,
                match ($match['tier']) {
                    'barangay' => "{$riderName} assigned via {$match['barangayAssignment']->barangay}, {$match['barangayAssignment']->municipality_name}.",
                    'provincial' => "{$riderName} assigned from the provincial pool.",
                    'regional' => "{$riderName} assigned from the regional pool.",
                    default => "{$riderName} assigned from the company-wide pool.",
                },
            );
        });
    }

    /**
     * The full tiered match for one order, with no side effects beyond
     * advancing whichever pool's rotation cursor was used — safe to call
     * before a ParcelAssignment row even exists (ParcelIntakeService), as
     * well as from assign() above once one does.
     *
     * $isPickupPhase decides which address is matched, whether the
     * provincial/regional pools are even in play, AND whether the
     * vehicle-category gate applies:
     *
     *   - Pickup (not yet collected from the seller): matched against the
     *     SELLER's (pickup_*) address, barangay tier only. A courier is
     *     being sent to a specific known point this company should
     *     already have direct coverage for — provincial/regional's "any
     *     barangay in the province/region" reach doesn't apply to finding
     *     one exact address, so an unmatched pickup falls straight to the
     *     company-wide pool instead. $requiredVehicleType describes the
     *     seller-to-BUYER haul (TransferTriggerService), not this local
     *     hop to the seller's own doorstep, so it's ignored here — a
     *     motorcycle courier collecting from their own barangay shouldn't
     *     lose the pickup to a car/van rider from the general pool just
     *     because the *buyer* happens to live somewhere that'll need a
     *     bigger vehicle later.
     *   - Delivery (already collected): matched against the BUYER's
     *     (shipping_*) address, and this IS the leg $requiredVehicleType
     *     describes, so it's enforced at every tier. Barangay first; then
     *     provincial (buyer's in this company's own province, just not a
     *     municipality it has a barangay rider for) and regional (buyer's
     *     outside this company's own region) — decided purely by the
     *     buyer's address vs. the company's own, independent of
     *     $requiredVehicleType (that describes the seller-to-buyer trip,
     *     not "is this within the company's reach" — a same-municipality
     *     seller/buyer pair still needs the provincial pool if that
     *     shared municipality isn't one the company has a barangay rider
     *     in). A buyer outside both — a different province AND the same
     *     region — falls to the company-wide pool, same as pickup.
     *
     * @return array{rider: ?Profile, tier: ?string, barangayAssignment: ?LogisticsBarangayAssignment}
     */
    public function matchRider(Order $order, LogisticsCompany $company, ?string $requiredVehicleType, bool $isPickupPhase): array
    {
        $vehicleGate = $isPickupPhase ? null : $requiredVehicleType;

        $assignment = $isPickupPhase
            ? $this->parcelIntake->matchingBarangayAssignmentFor($company, $order->pickup_municipality_name, $order->pickup_barangay)
            : $this->parcelIntake->matchingBarangayAssignmentFor($company, $order->shipping_municipality_name, $order->shipping_barangay);
        $rider = $assignment ? $this->eligibleDirectRider($assignment, $company, $vehicleGate) : null;

        if ($rider) {
            return ['rider' => $rider, 'tier' => 'barangay', 'barangayAssignment' => $assignment];
        }

        if (! $isPickupPhase) {
            $address = $company->address;
            // The company's own island-group region lives on
            // logistics_companies.region (Luzon/Visayas/Mindanao) — see
            // App\Services\ServiceAreaProvisioner.
            $region = $company->region;

            if ($address && $this->sameProvince($address->province_name, $order->shipping_province_name)) {
                $provincial = $this->lockedProvincialAssignment($company);
                $rider = $provincial ? $this->eligibleProvincialRider($provincial, $requiredVehicleType) : null;

                if ($rider) {
                    $provincial->update(['last_auto_assigned_rider_profile_id' => $rider->id]);

                    return ['rider' => $rider, 'tier' => 'provincial', 'barangayAssignment' => $assignment];
                }
            }

            if ($region && ! $this->sameRegion($region, $order->shipping_region_name)) {
                $regional = $this->lockedRegionalAssignment($company);
                $rider = $regional ? $this->eligibleRegionalRider($regional, $requiredVehicleType) : null;

                if ($rider) {
                    $regional->update(['last_auto_assigned_rider_profile_id' => $rider->id]);

                    return ['rider' => $rider, 'tier' => 'regional', 'barangayAssignment' => $assignment];
                }
            }
        }

        // Locked for the same reason a pool row is above: the rotation
        // cursor is read and written here, and two concurrent callers
        // reading the same cursor would both pick the same rider.
        /** @var LogisticsCompany $lockedCompany */
        $lockedCompany = LogisticsCompany::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
        $pool = $this->eligibleCompanyRiders($lockedCompany, $vehicleGate);

        if ($pool->isEmpty()) {
            return ['rider' => null, 'tier' => null, 'barangayAssignment' => $assignment];
        }

        $rider = $this->nextCompanyRider($lockedCompany, $pool);
        $lockedCompany->update(['last_auto_assigned_rider_profile_id' => $rider->id]);

        return ['rider' => $rider, 'tier' => 'pool', 'barangayAssignment' => $assignment];
    }

    /**
     * Which fallback tier a parcel WOULD route through if it had no
     * direct barangay rider right now — read-only, no locking, no rider
     * eligibility check. Purely for display (the "Area" column on a
     * parcel with no barangay_assignment_id yet), so staff see
     * "Provincial"/"Regional" instead of a bare "Needs sorting" where
     * that tier would actually apply.
     *
     * Mirrors matchRider()'s rule exactly: never during pickup (matching
     * one exact seller address, not "any barangay in the area"); during
     * delivery, purely by the buyer's address vs. the company's own.
     */
    public function expectedTierFor(LogisticsCompany $company, Order $order, bool $isPickupPhase): ?string
    {
        if ($isPickupPhase) {
            return null;
        }

        $address = $company->address;
        $region = $company->region;

        if ($address && $this->sameProvince($address->province_name, $order->shipping_province_name)) {
            return 'provincial';
        }

        if ($region && ! $this->sameRegion($region, $order->shipping_region_name)) {
            return 'regional';
        }

        return null;
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
            ParcelAssignment::STATUS_TRANSFER_ONGOING,
            ParcelAssignment::STATUS_TRANSFER_ASSIGNED,
            ParcelAssignment::STATUS_READY_TO_TRANSFER,
        ], true)) {
            return false;
        }

        return $parcel->rider_profile_id === null;
    }

    /**
     * The barangay's sole assigned rider, if they can actually take this
     * parcel right now: still accepted by the company, on shift, under
     * quota, and — when the parcel needs a specific vehicle — driving one
     * that qualifies. Any failed gate falls through to the next tier
     * rather than leaving the parcel stuck, per the class docblock.
     */
    private function eligibleDirectRider(
        LogisticsBarangayAssignment $assignment,
        LogisticsCompany $company,
        ?string $requiredVehicleType,
    ): ?Profile {
        if (! $assignment->rider_profile_id) {
            return null;
        }

        $rider = $this->acceptedRiderQuery($company)->find($assignment->rider_profile_id);

        return $this->passesCommonGates($rider, $requiredVehicleType) ? $rider : null;
    }

    /**
     * Every eligible rider in the company's provincial pool, gated the
     * same way as the direct rider, ordered stably (by profile id) so the
     * pool's rotation is predictable between calls.
     */
    private function eligibleProvincialRider(LogisticsProvincialAssignment $provincial, ?string $requiredVehicleType): ?Profile
    {
        $riders = $provincial->riders()
            ->with('courierDetail')
            ->orderBy('profiles.id')
            ->get()
            ->filter(fn (Profile $rider): bool => $this->passesCommonGates($rider, $requiredVehicleType))
            ->values();

        if ($riders->isEmpty()) {
            return null;
        }

        return $this->nextInRotation($provincial->last_auto_assigned_rider_profile_id, $riders);
    }

    /**
     * Every eligible rider in the company's regional pool — same shape as
     * eligibleProvincialRider().
     */
    private function eligibleRegionalRider(LogisticsRegionalAssignment $regional, ?string $requiredVehicleType): ?Profile
    {
        $riders = $regional->riders()
            ->with('courierDetail')
            ->orderBy('profiles.id')
            ->get()
            ->filter(fn (Profile $rider): bool => $this->passesCommonGates($rider, $requiredVehicleType))
            ->values();

        if ($riders->isEmpty()) {
            return null;
        }

        return $this->nextInRotation($regional->last_auto_assigned_rider_profile_id, $riders);
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
    public function eligibleCompanyRiders(LogisticsCompany $company, ?string $requiredVehicleType): Collection
    {
        $riders = $this->acceptedRiderQuery($company)->orderBy('profiles.id')->get();

        return $riders
            ->filter(fn (Profile $rider): bool => $this->passesCommonGates($rider, $requiredVehicleType))
            ->values();
    }

    /**
     * Accepted-by-this-company base query, shared by every tier.
     */
    private function acceptedRiderQuery(LogisticsCompany $company)
    {
        return Profile::query()
            ->with('courierDetail')
            ->whereExists(function ($query) use ($company): void {
                $query->select(DB::raw('1'))
                    ->from('courier_applications')
                    ->whereColumn('courier_applications.courier_profile_id', 'profiles.id')
                    ->where('courier_applications.logistics_company_id', $company->id)
                    ->where('courier_applications.status', CourierApplication::STATUS_ACCEPTED);
            });
    }

    /**
     * Available, under quota, and (when the parcel needs one) driving a
     * qualifying vehicle. Shared by every tier so the eligibility rule
     * can't drift between them.
     */
    private function passesCommonGates(?Profile $rider, ?string $requiredVehicleType): bool
    {
        if (! $rider || ! $rider->isAvailableForDelivery()) {
            return false;
        }

        if (ParcelAssignment::activeCountFor($rider->id) >= ParcelAssignment::COURIER_QUOTA) {
            return false;
        }

        return VehicleCategory::satisfies($requiredVehicleType, $rider->vehicleLabel());
    }

    /**
     * The next rider in a rotation: whoever follows the last one
     * auto-assigned from this pool, wrapping at the end. A cursor
     * pointing at somebody no longer eligible isn't found in the list,
     * and the rotation restarts from the top — which is also the
     * first-ever-run case.
     *
     * @param  Collection<int, Profile>  $riders
     */
    private function nextInRotation(?string $cursor, Collection $riders): Profile
    {
        $position = $riders->search(fn (Profile $rider): bool => $rider->id === $cursor);

        $next = $position === false ? 0 : ($position + 1) % $riders->count();

        return $riders[$next];
    }

    private function nextCompanyRider(LogisticsCompany $company, Collection $riders): Profile
    {
        return $this->nextInRotation($company->last_auto_assigned_rider_profile_id, $riders);
    }

    private function lockedProvincialAssignment(LogisticsCompany $company): ?LogisticsProvincialAssignment
    {
        return LogisticsProvincialAssignment::query()
            ->where('logistics_company_id', $company->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();
    }

    private function lockedRegionalAssignment(LogisticsCompany $company): ?LogisticsRegionalAssignment
    {
        return LogisticsRegionalAssignment::query()
            ->where('logistics_company_id', $company->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();
    }

    private function sameProvince(?string $a, ?string $b): bool
    {
        return filled($a) && filled($b) && mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    private function sameRegion(?string $a, ?string $b): bool
    {
        return filled($a) && filled($b) && mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    /**
     * Says why nothing could be matched, so staff can fix the order's
     * address instead of guessing why the parcel was passed over.
     */
    private function noAddressMessage(bool $isPickupPhase, Order $order): string
    {
        $who = $isPickupPhase ? "the seller's" : "the buyer's";
        $where = $isPickupPhase
            ? collect([$order->pickup_barangay, $order->pickup_municipality_name, $order->pickup_province_name])->filter()->implode(', ')
            : collect([$order->shipping_barangay, $order->shipping_municipality_name, $order->shipping_province_name])->filter()->implode(', ');

        if (! filled($where)) {
            return "This order has no {$who} barangay or municipality on file to route by.";
        }

        return "{$who} address on this order ({$where}) is missing a barangay or municipality — check it in the order's details.";
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
