<?php

namespace App\Services;

use App\Models\DeliveryAttempt;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\ParcelAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryAttemptService
{
    public const REASONS = [
        'recipient_unavailable' => 'Recipient not available',
        'incorrect_address' => 'Wrong or incomplete address',
        'refused_delivery' => 'Recipient refused delivery',
        'unreachable_by_phone' => 'Recipient unreachable by phone',
        'business_closed' => 'Business closed',
        'access_denied' => 'Access denied / gated location',
        'weather_or_roads' => 'Weather or road conditions',
        'package_damaged' => 'Package damaged',
        'others' => 'Others',
    ];

    public static function nextDayUtc(CarbonImmutable $failedAt): CarbonImmutable
    {
        return $failedAt->setTimezone('Asia/Manila')->addDay()->startOfDay()->utc();
    }

    public static function isFailed(ParcelAssignment $assignment): bool
    {
        return in_array($assignment->status, [ParcelAssignment::STATUS_FAILED_ATTEMPT, ParcelAssignment::STATUS_NEEDS_DISPATCHER_REVIEW], true);
    }

    public static function summary(?Order $order): array
    {
        $attempt = $order?->latestDeliveryAttempt;

        return [
            'attempt_count' => (int) ($order?->delivery_attempt_count ?? 0),
            'attempt_limit' => $order?->delivery_attempt_limit ?? config('delivery.attempt_limit'),
            'retry_at' => $order?->delivery_retry_at?->toISOString(),
            'return_flagged_at' => $order?->delivery_return_flagged_at?->toISOString(),
            'needs_review' => $order?->status === 'Needs Dispatcher Review',
            'can_reattempt' => $order?->status === 'Failed Delivery Attempt'
                && $order->delivery_retry_at !== null && CarbonImmutable::now('UTC')->gte($order->delivery_retry_at),
            'latest_attempt' => $attempt ? self::presentAttempt($attempt) : null,
        ];
    }

    public static function presentAttempt(DeliveryAttempt $attempt): array
    {
        return [
            'attempt_number' => $attempt->attempt_number,
            'courier_id' => $attempt->courier_id,
            'reason_code' => $attempt->reason_code,
            'reason_label' => $attempt->reason_label,
            'description' => $attempt->description,
            'failed_at' => $attempt->failed_at->toISOString(),
        ];
    }

    public function fail(string $assignmentId, string $courierId, string $reason, ?string $description): ParcelAssignment
    {
        if (! isset(self::REASONS[$reason]) || ($reason === 'others' && trim($description ?? '') === '')) {
            throw ValidationException::withMessages(['reason_code' => 'Select a reason and describe Others.']);
        }

        return $this->mutate($assignmentId, function (Order $order, ParcelAssignment $assignment) use ($courierId, $reason, $description): void {
            abort_unless($assignment->rider_profile_id === $courierId, 404);
            if ($assignment->status !== ParcelAssignment::STATUS_HANDED_OFF || $order->status !== 'In Transit'
                || $assignment->isReturn() || $assignment->transfer_to_company_id !== null
                || (! $assignment->barangay_assignment_id && $assignment->is_transfer)) {
                throw ValidationException::withMessages(['delivery' => 'Only an assigned order out for delivery can have a failed attempt.']);
            }
            $now = CarbonImmutable::now('UTC');
            $number = $order->delivery_attempt_count + 1;
            $limit = $order->delivery_attempt_limit ?? config('delivery.attempt_limit');
            DeliveryAttempt::create([
                'order_id' => $order->id, 'parcel_assignment_id' => $assignment->id,
                'courier_id' => $courierId, 'attempt_number' => $number,
                'reason_code' => $reason, 'reason_label' => self::REASONS[$reason],
                'description' => $reason === 'others' ? trim($description) : null, 'failed_at' => $now,
            ]);
            $order->delivery_attempt_count = $number;
            $order->delivery_retry_at = self::nextDayUtc($now);
            $status = $number >= $limit ? 'Needs Dispatcher Review' : 'Failed Delivery Attempt';
            $this->status($order, $status, $courierId, 'Failed delivery attempt #'.$number.': '.self::REASONS[$reason].($reason === 'others' ? ' - '.trim($description) : ''));
            $assignment->update(['status' => $number >= $limit ? ParcelAssignment::STATUS_NEEDS_DISPATCHER_REVIEW : ParcelAssignment::STATUS_FAILED_ATTEMPT]);
        });
    }

    public function reattempt(string $assignmentId, string $courierId): ParcelAssignment
    {
        return $this->mutate($assignmentId, function (Order $order, ParcelAssignment $assignment) use ($courierId): void {
            abort_unless($assignment->rider_profile_id === $courierId, 404);
            if ($assignment->status !== ParcelAssignment::STATUS_FAILED_ATTEMPT || ! self::summary($order)['can_reattempt']) {
                throw ValidationException::withMessages(['delivery' => 'Re-attempt requires dispatcher approval when under review and is available only from midnight the next Philippine calendar day.']);
            }
            $this->status($order, 'In Transit', $courierId, 'Courier explicitly started delivery re-attempt #'.($order->delivery_attempt_count + 1).'.');
            $assignment->update(['status' => ParcelAssignment::STATUS_HANDED_OFF]);
        });
    }

    public function review(string $assignmentId, string $companyId, string $actorId, ?string $riderId = null): ParcelAssignment
    {
        return $this->mutate($assignmentId, function (Order $order, ParcelAssignment $assignment) use ($companyId, $actorId, $riderId): void {
            abort_unless($assignment->logistics_company_id === $companyId, 404);
            if (! self::isFailed($assignment)) {
                throw ValidationException::withMessages(['delivery' => 'This parcel is not awaiting a delivery re-attempt.']);
            }
            if ($riderId === null && $assignment->status !== ParcelAssignment::STATUS_NEEDS_DISPATCHER_REVIEW) {
                throw ValidationException::withMessages(['delivery' => 'This order already has permission to re-attempt.']);
            }
            $note = 'Dispatcher approved one additional delivery attempt.';
            if ($riderId !== null) {
                if ($riderId === $assignment->rider_profile_id) {
                    throw ValidationException::withMessages(['rider_profile_id' => 'Select a different courier, or approve another attempt for the current courier.']);
                }
                $note = 'Dispatcher reassigned failed delivery from '.$assignment->rider_profile_id.' to '.$riderId.'.';
                $assignment->rider_profile_id = $riderId;
                $assignment->assigned_by = $actorId;
                $assignment->assigned_at = CarbonImmutable::now('UTC');
            }
            if ($order->status === 'Needs Dispatcher Review') {
                $order->delivery_attempt_limit = max($order->delivery_attempt_limit ?? config('delivery.attempt_limit'), $order->delivery_attempt_count + 1);
            }
            $order->delivery_return_flagged_at = null;
            $this->status($order, 'Failed Delivery Attempt', $actorId, $note.' The next-day waiting period is unchanged.');
            $assignment->status = ParcelAssignment::STATUS_FAILED_ATTEMPT;
            $assignment->save();
        });
    }

    public function flagReturn(string $assignmentId, string $companyId, string $actorId): ParcelAssignment
    {
        return $this->mutate($assignmentId, function (Order $order, ParcelAssignment $assignment) use ($companyId, $actorId): void {
            abort_unless($assignment->logistics_company_id === $companyId, 404);
            if ($assignment->status !== ParcelAssignment::STATUS_NEEDS_DISPATCHER_REVIEW || $order->delivery_return_flagged_at !== null) {
                throw ValidationException::withMessages(['delivery' => 'Only an unflagged order awaiting dispatcher review can be flagged for return.']);
            }
            $order->delivery_return_flagged_at = CarbonImmutable::now('UTC');
            $this->status($order, 'Needs Dispatcher Review', $actorId, 'Dispatcher flagged this order for the separate return-to-sender process. Courier re-attempts remain blocked.');
        });
    }

    private function mutate(string $id, callable $callback): ParcelAssignment
    {
        return DB::transaction(function () use ($id, $callback): ParcelAssignment {
            $candidate = ParcelAssignment::findOrFail($id);
            $order = Order::whereKey($candidate->order_id)->lockForUpdate()->firstOrFail();
            $assignment = ParcelAssignment::whereKey($id)->lockForUpdate()->firstOrFail();
            $callback($order, $assignment);

            return $assignment;
        });
    }

    private function status(Order $order, string $status, string $actorId, string $note): void
    {
        $previous = $order->status;
        $order->status = $status;
        $order->save();
        OrderStatusHistory::create(['order_id' => $order->id, 'status' => $status, 'previous_status' => $previous, 'changed_by' => $actorId, 'note' => $note]);
    }
}
