<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\ParcelAssignment;
use App\Models\Profile;
use App\Services\SellerNotifier;
use App\Services\SupabaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Backs the "Deliveries" tab of the Flutter driver app
 * (driver_deliveries_screen.dart) — parcels the logistics team has
 * assigned to the signed-in rider/courier via
 * Api\Logistics\ParcelAssignmentController::assign, plus the rider's own
 * "Mark as Delivered" action once they have it in hand. Deliberately
 * separate from a "browse and accept" queue: every row here already
 * carries this profile's id as `rider_profile_id`, pushed down by dispatch
 * rather than pulled from an open pool — and likewise, going from
 * "assigned" to "picked up" (handed_off) is dispatch's action, not the
 * rider's (see Api\Logistics\ParcelAssignmentController::handoff), so the
 * only rider-initiated transition here is the final delivery.
 */
class DriverDeliveryController extends Controller
{
    public function __construct(private readonly SupabaseStorageService $supabaseStorage)
    {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();

        $assignments = ParcelAssignment::query()
            ->with(['order.items', 'deliveryArea', 'logisticsCompany'])
            ->where('rider_profile_id', $profile->id)
            // 'received'/'sorted' assignments have no rider yet (the
            // whereIn is belt-and-braces, not strictly required given the
            // rider_profile_id filter above) — only a row dispatch has
            // actually handed to this rider belongs on their Deliveries
            // list.
            ->whereIn('status', [ParcelAssignment::STATUS_ASSIGNED, ParcelAssignment::STATUS_HANDED_OFF])
            ->orderByDesc('assigned_at')
            ->get();

        return response()->json([
            'data' => $assignments->map(fn (ParcelAssignment $assignment): array => $this->present($assignment))->values(),
        ]);
    }

    /**
     * Mark a handed-off parcel delivered. Requires a photo of the parcel —
     * there is no other proof-of-delivery mechanism in this schema (see
     * Seller\SellerDeliveryController's docblock), so this is the first
     * place one gets captured.
     */
    public function deliver(Request $request, string $parcelAssignment): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();

        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'photo.required' => 'Please attach a photo of the parcel before marking this delivered.',
            'photo.mimes' => 'Photo must be a JPG or PNG image.',
            'photo.max' => 'Photo must not be larger than 5MB.',
        ]);

        $assignment = ParcelAssignment::query()
            ->with(['order', 'deliveryArea', 'logisticsCompany'])
            ->where('rider_profile_id', $profile->id)
            ->whereKey($parcelAssignment)
            ->first();

        if (! $assignment) {
            return response()->json(['message' => 'Delivery not found.'], 404);
        }

        if ($assignment->status !== ParcelAssignment::STATUS_HANDED_OFF) {
            return response()->json([
                'message' => "This parcel hasn't been handed to you yet — it can't be marked delivered.",
            ], 422);
        }

        $order = $assignment->order;
        if (! $order) {
            return response()->json(['message' => 'This delivery has no linked order.'], 422);
        }

        // Idempotent: already delivered (e.g. a retried request) -> just
        // return the current state instead of erroring.
        if ($order->status === 'Delivered') {
            return response()->json(['data' => $this->present($assignment)]);
        }

        if (! $order->canTransitionTo('Delivered')) {
            return response()->json([
                'message' => "This order can't be marked delivered from its current status.",
            ], 422);
        }

        $file = $request->file('photo');
        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $photoPath = "profile/{$profile->id}/deliveries/{$assignment->id}/".(string) Str::uuid().'.'.$extension;

        try {
            $this->supabaseStorage->upload($file, $photoPath);
        } catch (\Throwable $e) {
            Log::error('Delivery photo upload to Supabase failed: '.$e->getMessage());

            return response()->json(['message' => 'Failed to upload the delivery photo. Please try again.'], 500);
        }

        $fromStatus = $order->status;

        DB::transaction(function () use ($assignment, $order, $fromStatus, $photoPath, $profile): void {
            // Lock the row so a concurrent change (e.g. the seller/admin
            // touching the same order) can't race this one — same pattern
            // as SellerOrderController::updateStatus().
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->first();
            $lockedOrder->status = 'Delivered';
            $lockedOrder->save();

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'status' => 'Delivered',
                'previous_status' => $fromStatus,
                'note' => 'Delivered by rider with photo confirmation.',
                'changed_by' => $profile->id,
            ]);

            $assignment->update([
                'delivered_at' => now(),
                'delivery_photo_path' => $photoPath,
            ]);
        });

        $assignment->refresh()->load(['order', 'deliveryArea', 'logisticsCompany']);

        // Best-effort: let the seller know their order was delivered. No
        // BuyerNotifier exists in this project yet (see SellerNotifier's
        // usage elsewhere) — not fabricating one here.
        try {
            app(SellerNotifier::class)->orderStatusChanged($assignment->order, $fromStatus, 'Delivered', 'the rider');
        } catch (\Throwable $e) {
            Log::warning('Seller notification for delivered order failed: '.$e->getMessage());
        }

        return response()->json(['data' => $this->present($assignment)]);
    }

    /**
     * Return a short-lived signed URL for the delivery photo on a given
     * assignment, scoped to the rider who delivered it.
     */
    public function photo(Request $request, string $parcelAssignment): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();

        $assignment = ParcelAssignment::query()
            ->where('rider_profile_id', $profile->id)
            ->whereKey($parcelAssignment)
            ->first();

        if (! $assignment || ! $assignment->delivery_photo_path) {
            return response()->json(['message' => 'No delivery photo on file for this parcel.'], 404);
        }

        $url = $this->supabaseStorage->signedUrl($assignment->delivery_photo_path);
        if (! $url) {
            return response()->json(['message' => 'Could not generate a link to the delivery photo right now.'], 502);
        }

        return response()->json(['url' => $url]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ParcelAssignment $assignment): array
    {
        $order = $assignment->order;

        return [
            'id' => $assignment->id,
            'order_id' => $order?->id,
            'order_number' => $order?->order_number,
            'tracking_number' => $order?->tracking_number,
            'customer_name' => $order?->recipient_name,
            'customer_contact_no' => $order?->recipient_contact_no,
            // The driver's actual pickup point is the logistics company's
            // sorting hub, not anything stored per-order — there's no
            // separate "pickup address" field on Order, only the buyer's
            // shipping (dropoff) address.
            'pickup_label' => trim(($assignment->logisticsCompany?->company_name ?: 'Logistics').' — Sorting Hub'),
            'dropoff_label' => collect([
                $order?->shipping_house_no,
                $order?->shipping_street,
                $order?->shipping_barangay,
                $order?->shipping_municipality_name,
                $order?->shipping_province_name,
            ])->filter()->implode(', ') ?: null,
            'delivery_area' => $assignment->deliveryArea?->name,
            'parcels' => (int) ($order?->items->sum('quantity') ?? 0),
            'assigned_at' => $assignment->assigned_at?->toISOString(),
            'handed_off_at' => $assignment->handed_off_at?->toISOString(),
            'delivered_at' => $assignment->delivered_at?->toISOString(),
            'has_delivery_photo' => filled($assignment->delivery_photo_path),
            'status' => $this->deliveryStatus($assignment, $order),
        ];
    }

    /**
     * Maps ParcelAssignment's dispatch-side status plus the order's own
     * fulfilment status onto the three states the driver app cares about:
     * assigned (handed to me, not yet picked up), picked_up (I have it,
     * order still In Transit), delivered (order.status flipped to
     * 'Delivered' — ParcelAssignment itself has no "delivered" status of
     * its own; 'handed_off' is terminal on that side).
     */
    private function deliveryStatus(ParcelAssignment $assignment, ?Order $order): string
    {
        if ($order?->status === 'Delivered') {
            return 'delivered';
        }

        return $assignment->status === ParcelAssignment::STATUS_HANDED_OFF ? 'picked_up' : 'assigned';
    }
}
