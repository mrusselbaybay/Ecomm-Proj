<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\ParcelAssignment;
use App\Models\ParcelScanEvent;
use App\Models\Profile;
use App\Services\SellerNotifier;
use App\Services\SupabaseStorageService;
use Illuminate\Database\Eloquent\Builder;
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
 * "Mark as picked up" and "Mark as delivered" actions once it's theirs to
 * handle. Deliberately separate from a "browse and accept" queue: every
 * row here already carries this profile's id as `rider_profile_id`,
 * pushed down by dispatch rather than pulled from an open pool.
 *
 * "assigned" -> "handed_off" (picked up) can still happen from the
 * logistics side as a manual handoff
 * (Api\Logistics\ParcelAssignmentController::handoff, e.g. dispatch
 * physically handing the parcel over at the counter) — but the rider can
 * also confirm it themselves from the app by attaching a photo of the
 * parcel (see [pickup]), which is the expected path once a rider is
 * collecting straight from the sorting hub without a staff member driving
 * the handoff. Both write the same `status`/`handed_off_at` columns on the
 * same `parcel_assignments` row, so whichever happens first wins (the
 * other is rejected as no-longer-assigned) and the change is visible
 * immediately on the logistics dashboard's sorting queue either way.
 */
class DriverDeliveryController extends Controller
{
    public function __construct(private readonly SupabaseStorageService $supabaseStorage) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();

        $assignments = ParcelAssignment::query()
            ->with(['order.items.product', 'order.seller.sellerDetail', 'deliveryArea', 'logisticsCompany'])
            // Rows currently dispatched to this rider (assigned / out for
            // delivery), PLUS rows they ran the pickup leg on and have
            // since handed back to logistics — those stay on their list
            // as a read-only record (picked_up_by), never actionable.
            ->where(function (Builder $query) use ($profile): void {
                $query->where('rider_profile_id', $profile->id)
                    ->orWhere('picked_up_by', $profile->id);
            })
            ->whereIn('status', [ParcelAssignment::STATUS_ASSIGNED, ParcelAssignment::STATUS_HANDED_OFF])
            ->orderByDesc('assigned_at')
            ->get();

        return response()->json([
            'data' => $assignments->map(fn (ParcelAssignment $assignment): array => $this->present($assignment, $profile))->values(),
        ]);
    }

    /**
     * Resolve a scanned parcel confirmation QR (the string the seller
     * printed on Prepare Orders — "NXP:<token>") to the delivery it belongs
     * to, for the scanning rider. Read-only: it records a 'verify' scan
     * event but changes no status. The rider then calls [pickup] or
     * [deliver] as normal, passing the same token through so that action is
     * logged as a scan too.
     *
     * A code that doesn't map to one of *this* rider's assignments returns
     * a plain 404 — it never confirms that some other rider's parcel, or an
     * order, exists.
     */
    public function verifyQr(Request $request): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();

        $data = $request->validate([
            'token' => ['required', 'string', 'max:128'],
        ], [
            'token.required' => 'Scan or enter a parcel code first.',
        ]);

        $token = Order::normalizeScannedToken($data['token']);

        $notFound = response()->json([
            'message' => "That code doesn't match a delivery assigned to you.",
        ], 404);

        if ($token === '') {
            return $notFound;
        }

        $order = Order::query()->where('confirmation_token', $token)->first();

        if (! $order) {
            return $notFound;
        }

        $assignment = ParcelAssignment::query()
            ->with(['order.items.product', 'order.seller.sellerDetail', 'deliveryArea', 'logisticsCompany'])
            ->where('order_id', $order->id)
            ->where('rider_profile_id', $profile->id)
            ->first();

        if (! $assignment) {
            return $notFound;
        }

        $this->recordScan($assignment, ParcelScanEvent::CHECKPOINT_VERIFY, $profile);

        return response()->json([
            'data' => $this->present($assignment, $profile),
            'next_action' => $this->nextAction($assignment, $assignment->order, $profile),
        ]);
    }

    /**
     * Rider-initiated pickup confirmation: moves an assignment from
     * 'assigned' ("To pick up") to 'handed_off' and *releases the rider*
     * (rider_profile_id -> null). Pickup and delivery are two separate
     * legs run by two different people: once the pickup courier has the
     * parcel and has confirmed it, the parcel goes back to the logistics
     * sorting queue tagged "To be delivered", where dispatch assigns a
     * delivery rider (Api\Logistics\ParcelAssignmentController::assign,
     * which keeps it 'handed_off' — the delivery rider goes straight to
     * "mark delivered", not through pickup again). It does NOT stay on the
     * pickup courier's plate as their delivery.
     *
     * Requires a photo of the parcel at the point of pickup, same proof
     * requirement as [deliver] on the drop-off end.
     *
     * An optional `confirmation_token` (from scanning the parcel QR) is
     * verified against this parcel and logged as a 'pickup' scan when
     * present — it's an extra audit trail, not a replacement for the photo.
     *
     * The 10MB cap (up from the original 5MB) accounts for the QR-scan
     * flow: that photo is the exact camera frame the code was decoded
     * from, captured losslessly as PNG on the device (see QrScanScreen's
     * docblock on the Flutter side) rather than a JPEG from a normal
     * photo picker, so it runs noticeably larger for the same pixel
     * dimensions.
     */
    public function pickup(Request $request, string $parcelAssignment): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();

        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:10240'],
            'confirmation_token' => ['nullable', 'string', 'max:128'],
        ], [
            'photo.required' => 'Please attach a photo of the parcel before marking this picked up.',
            'photo.mimes' => 'Photo must be a JPG or PNG image.',
            'photo.max' => 'Photo must not be larger than 10MB.',
        ]);

        $assignment = ParcelAssignment::query()
            ->with(['order.items.product', 'order.seller.sellerDetail', 'deliveryArea', 'logisticsCompany'])
            ->where('rider_profile_id', $profile->id)
            ->whereKey($parcelAssignment)
            ->first();

        if (! $assignment) {
            return response()->json(['message' => 'Delivery not found.'], 404);
        }

        if ($scanError = $this->guardScannedToken($request, $assignment)) {
            return $scanError;
        }

        // Log the pickup scan up front (if a code was scanned) so it's
        // captured even when the transition below is a no-op idempotent
        // retry — the rider was still physically at the pickup point.
        if ($request->filled('confirmation_token')) {
            $this->recordScan($assignment, ParcelScanEvent::CHECKPOINT_PICKUP, $profile);
        }

        // Idempotent: already handed off (e.g. dispatch beat the rider to
        // it, or a retried request) -> just return the current state
        // instead of erroring.
        if ($assignment->status === ParcelAssignment::STATUS_HANDED_OFF) {
            return response()->json(['data' => $this->present($assignment, $profile)]);
        }

        if ($assignment->status !== ParcelAssignment::STATUS_ASSIGNED) {
            return response()->json([
                'message' => "This parcel isn't ready to be picked up yet.",
            ], 422);
        }

        $file = $request->file('photo');
        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $photoPath = "profile/{$profile->id}/pickups/{$assignment->id}/".(string) Str::uuid().'.'.$extension;

        try {
            $this->supabaseStorage->upload($file, $photoPath);
        } catch (\Throwable $e) {
            Log::error('Pickup photo upload to Supabase failed: '.$e->getMessage());

            return response()->json(['message' => 'Failed to upload the pickup photo. Please try again.'], 500);
        }

        DB::transaction(function () use ($assignment, $photoPath, $profile): void {
            // Lock the row so a concurrent dispatch-side handoff can't race
            // this one — same pattern used by deliver()'s Order lock.
            $lockedAssignment = ParcelAssignment::whereKey($assignment->id)->lockForUpdate()->first();

            if ($lockedAssignment->status !== ParcelAssignment::STATUS_ASSIGNED) {
                return;
            }

            $lockedAssignment->update([
                'status' => ParcelAssignment::STATUS_HANDED_OFF,
                'handed_off_at' => now(),
                'pickup_photo_path' => $photoPath,
                // Hand the parcel back to logistics for delivery dispatch —
                // it's no longer this (pickup) courier's job. delivery_area_id
                // is kept so dispatch only has to pick the delivery rider;
                // picked_up_by keeps a read-only trace on the courier's list.
                'rider_profile_id' => null,
                'picked_up_by' => $profile->id,
            ]);
        });

        $assignment->refresh()->load(['order.items.product', 'order.seller.sellerDetail', 'deliveryArea', 'logisticsCompany']);

        return response()->json(['data' => $this->present($assignment, $profile)]);
    }

    /**
     * Mark a handed-off parcel delivered. Requires a photo of the parcel —
     * there is no other proof-of-delivery mechanism in this schema (see
     * Seller\SellerDeliveryController's docblock), so this is the first
     * place one gets captured.
     *
     * As with [pickup], an optional `confirmation_token` from scanning the
     * parcel QR is verified against this parcel and logged as a 'delivery'
     * scan — alongside the photo, not instead of it.
     */
    public function deliver(Request $request, string $parcelAssignment): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();

        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:10240'],
            'confirmation_token' => ['nullable', 'string', 'max:128'],
        ], [
            'photo.required' => 'Please attach a photo of the parcel before marking this delivered.',
            'photo.mimes' => 'Photo must be a JPG or PNG image.',
            'photo.max' => 'Photo must not be larger than 10MB.',
        ]);

        $assignment = ParcelAssignment::query()
            ->with(['order.items.product', 'order.seller.sellerDetail', 'deliveryArea', 'logisticsCompany'])
            ->where('rider_profile_id', $profile->id)
            ->whereKey($parcelAssignment)
            ->first();

        if (! $assignment) {
            return response()->json(['message' => 'Delivery not found.'], 404);
        }

        if ($scanError = $this->guardScannedToken($request, $assignment)) {
            return $scanError;
        }

        if ($request->filled('confirmation_token')) {
            $this->recordScan($assignment, ParcelScanEvent::CHECKPOINT_DELIVERY, $profile);
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
            return response()->json(['data' => $this->present($assignment, $profile)]);
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

        $assignment->refresh()->load(['order.items.product', 'order.seller.sellerDetail', 'deliveryArea', 'logisticsCompany']);

        // Best-effort: let the seller know their order was delivered. No
        // BuyerNotifier exists in this project yet (see SellerNotifier's
        // usage elsewhere) — not fabricating one here.
        try {
            app(SellerNotifier::class)->orderStatusChanged($assignment->order, $fromStatus, 'Delivered', 'the rider');
        } catch (\Throwable $e) {
            Log::warning('Seller notification for delivered order failed: '.$e->getMessage());
        }

        return response()->json(['data' => $this->present($assignment, $profile)]);
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
     * Return a short-lived signed URL for the pickup photo on a given
     * assignment, scoped to the rider who picked it up.
     */
    public function pickupPhoto(Request $request, string $parcelAssignment): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();

        $assignment = ParcelAssignment::query()
            ->where('rider_profile_id', $profile->id)
            ->whereKey($parcelAssignment)
            ->first();

        if (! $assignment || ! $assignment->pickup_photo_path) {
            return response()->json(['message' => 'No pickup photo on file for this parcel.'], 404);
        }

        $url = $this->supabaseStorage->signedUrl($assignment->pickup_photo_path);
        if (! $url) {
            return response()->json(['message' => 'Could not generate a link to the pickup photo right now.'], 502);
        }

        return response()->json(['url' => $url]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ParcelAssignment $assignment, ?Profile $viewer = null): array
    {
        $order = $assignment->order;

        return [
            'id' => $assignment->id,
            'order_id' => $order?->id,
            'order_number' => $order?->order_number,
            'tracking_number' => $order?->tracking_number,
            'customer_name' => $order?->recipient_name,
            'customer_contact_no' => $order?->recipient_contact_no,
            // The seller who packed/handed off this parcel — surfaced so
            // the rider can reach them directly (e.g. an address that's
            // hard to find, or a discrepancy at pickup) without going
            // through dispatch. Same first/last-name + contact_no shape
            // ParcelAssignmentResource already uses for `rider` on the
            // logistics side.
            'seller_name' => $order?->seller?->full_name,
            'seller_contact_no' => $order?->seller?->contact_no,
            // The seller's registered shop/business name (SellerDetail) —
            // shown alongside the line items on the rider's parcel screen,
            // matching what the web seller order-details view lists.
            'seller_shop_name' => $order?->seller?->sellerDetail?->business_name,
            // Line items on this order — name + variant/sku + quantity,
            // plus the product's package dimensions/weight where the seller
            // has filled them in (both live on products, not snapshotted
            // onto order_items, so they can be null for older rows).
            'items' => ($order?->items ?? collect())->map(fn ($item): array => [
                'name' => $item->product_name,
                'variant' => $item->variant ?: ($item->variant_sku ?: null),
                'sku' => $item->sku ?: $item->variant_sku,
                'quantity' => (int) $item->quantity,
                'dimensions' => $item->product?->dimensions,
                'weight' => $item->product?->weight !== null ? (float) $item->product->weight : null,
            ])->values()->all(),
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
            'has_pickup_photo' => filled($assignment->pickup_photo_path),
            'has_delivery_photo' => filled($assignment->delivery_photo_path),
            // The bare confirmation token — the rider is already authorised
            // for this parcel, so it's safe to echo for a manual-entry
            // fallback when the camera scan won't cooperate.
            'confirmation_token' => $order?->confirmation_token,
            'has_confirmation_qr' => filled($order?->confirmation_token),
            'status' => $this->deliveryStatus($assignment, $order, $viewer),
        ];
    }

    /**
     * If the request carries a scanned `confirmation_token`, require it to
     * match this parcel's order. Returns a 422 JsonResponse on mismatch, or
     * null when there's nothing to check / it checks out.
     */
    private function guardScannedToken(Request $request, ParcelAssignment $assignment): ?JsonResponse
    {
        if (! $request->filled('confirmation_token')) {
            return null;
        }

        $scanned = Order::normalizeScannedToken($request->string('confirmation_token')->toString());
        $actual = (string) $assignment->order?->confirmation_token;

        if ($actual === '' || ! hash_equals($actual, $scanned)) {
            return response()->json([
                'message' => "The scanned code doesn't match this parcel.",
            ], 422);
        }

        return null;
    }

    /**
     * Append one row to the parcel scan log. Best-effort: a failure here
     * (e.g. the table missing in a stripped-down environment) must never
     * break the pickup/deliver action it accompanies.
     */
    private function recordScan(ParcelAssignment $assignment, string $checkpoint, Profile $profile): void
    {
        if (! $assignment->order_id) {
            return;
        }

        try {
            ParcelScanEvent::create([
                'order_id' => $assignment->order_id,
                'parcel_assignment_id' => $assignment->id,
                'checkpoint' => $checkpoint,
                'scanned_by' => $profile->id,
                'scanned_by_role' => $profile->role,
                'scanned_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Parcel scan event not recorded: '.$e->getMessage());
        }
    }

    /**
     * What the rider should do next after a successful verify scan.
     */
    private function nextAction(ParcelAssignment $assignment, ?Order $order, ?Profile $viewer = null): string
    {
        return match ($this->deliveryStatus($assignment, $order, $viewer)) {
            'assigned' => 'pickup',
            'picked_up' => 'deliver',
            default => 'none',
        };
    }

    /**
     * Maps ParcelAssignment's dispatch-side status plus the order's own
     * fulfilment status onto the states the driver app cares about:
     * assigned (handed to me, not yet picked up), picked_up (I have it,
     * order still In Transit), delivered (order.status flipped to
     * 'Delivered'), and — for the courier who ran the pickup leg only —
     * handed_over (they collected it and passed it back to logistics; it's
     * a read-only record on their list now, someone else delivers it).
     * ParcelAssignment itself has no "delivered" status of its own;
     * 'handed_off' is terminal on that side.
     */
    private function deliveryStatus(ParcelAssignment $assignment, ?Order $order, ?Profile $viewer = null): string
    {
        // The pickup courier's read-only view of a parcel they've already
        // handed back: it's handed_off, they're the one who picked it up,
        // and it's no longer dispatched to them (rider cleared, or a
        // different delivery rider now assigned). Checked before
        // 'delivered' so their card doesn't suddenly read "Delivered" for
        // a drop-off someone else made.
        if ($viewer !== null
            && $assignment->status === ParcelAssignment::STATUS_HANDED_OFF
            && $assignment->picked_up_by === $viewer->id
            && $assignment->rider_profile_id !== $viewer->id) {
            return 'handed_over';
        }

        if ($order?->status === 'Delivered') {
            return 'delivered';
        }

        return $assignment->status === ParcelAssignment::STATUS_HANDED_OFF ? 'picked_up' : 'assigned';
    }
}
