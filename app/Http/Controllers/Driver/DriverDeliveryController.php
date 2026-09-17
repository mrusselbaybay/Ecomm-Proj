<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\ParcelAssignment;
use App\Models\ParcelScanEvent;
use App\Models\ParcelTransferRequest;
use App\Models\Profile;
use App\Services\ParcelAutoAssignService;
use App\Services\ParcelIntakeService;
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
 *
 * A cross-region transfer between two logistics companies runs its own
 * parallel leg, invisible on this list until dispatch has physically
 * confirmed the handoff — see Api\Logistics\
 * ParcelAssignmentController::acceptTransferRequest (which auto-assigns
 * the courier, often the same one who ran the pickup leg, but always
 * lands on "transfer_assigned" first) and ::handoff (dispatch's counter
 * confirmation, "transfer_assigned" -> "ready_to_transfer"). Unlike the
 * assigned -> handed_off leg above, there is no rider self-serve
 * equivalent for that step — a cross-company handoff always needs
 * dispatch's own confirmation, never a tap from the app — so
 * "transfer_assigned" rows are filtered out of [index]/[verifyQr]
 * entirely and only appear here once they're already "ready_to_transfer"
 * (has it, en route to the target company's hub). From there
 * [confirmTransfer] (photo + QR, same shape as [deliver]) closes it out
 * as "transferred" and opens the receiving company's own "to be
 * delivered" row.
 */
class DriverDeliveryController extends Controller
{
    public function __construct(
        private readonly SupabaseStorageService $supabaseStorage,
        private readonly ParcelIntakeService $parcelIntake,
        private readonly ParcelAutoAssignService $autoAssign,
    ) {}

    /**
     * True for a HANDED_OFF row that's still an open "local delivery or
     * transfer?" question — no barangay committed yet, and either already
     * flagged is_transfer or auto-matched from the regional/provincial
     * pool (which, unlike is_transfer, isn't set until a transfer is
     * actually requested — see Api\Logistics\
     * ParcelAssignmentController::awaitingDispatchDecision, the staff-side
     * mirror of this same rule). A row like that shouldn't read as a real
     * job on a rider's phone yet.
     */
    private function awaitingDispatchDecision(ParcelAssignment $assignment): bool
    {
        if ($assignment->status !== ParcelAssignment::STATUS_HANDED_OFF || $assignment->barangay_assignment_id) {
            return false;
        }

        if ($assignment->is_transfer) {
            return true;
        }

        if (! $assignment->order || ! $assignment->logisticsCompany) {
            return false;
        }

        return in_array(
            $this->autoAssign->expectedTierFor($assignment->logisticsCompany, $assignment->order, false),
            ['regional', 'provincial'],
            true,
        );
    }

    public function index(Request $request): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();

        $assignments = ParcelAssignment::query()
            ->with(['order.items.product', 'order.seller.sellerDetail', 'barangayAssignment', 'logisticsCompany', 'transferToCompany'])
            // Rows currently dispatched to this rider — assigned, out for
            // delivery, or carrying a cross-region transfer — PLUS rows
            // they ran the pickup leg on and have since handed back to
            // logistics with nobody dispatched yet, which stay on their
            // list purely as a read-only record (picked_up_by), never
            // actionable. The picked_up_by branch is deliberately scoped
            // to STATUS_HANDED_OFF only: once that row moves on to a
            // transfer leg it belongs to whichever rider is carrying it
            // now, not this rider's history, even if they're the one who
            // originally picked it up (rider_profile_id already covers
            // that case if it's still them).
            //
            // STATUS_TRANSFER_ASSIGNED deliberately excluded even when
            // rider_profile_id is already this rider (e.g. the same
            // courier who ran the pickup leg, auto-reused on accept — see
            // Api\Logistics\ParcelAssignmentController::acceptTransferRequest):
            // a transfer leg only appears here once dispatch has
            // physically confirmed the handoff (handoff() ->
            // STATUS_READY_TO_TRANSFER), never before, so this list can't
            // be used to jump the gun on that confirmation. See [pickup]'s
            // docblock for the other half of this.
            //
            // A HANDED_OFF row also stays hidden while awaitingDispatchDecision()
            // above says it's still an open "local delivery or transfer?"
            // question — filtered in PHP after the query below rather than
            // in SQL, since a regional/provincial pool match (unlike
            // is_transfer) needs comparing the company's own address
            // against the order's, not just a column check.
            ->where(function (Builder $query) use ($profile): void {
                $query->where('rider_profile_id', $profile->id)
                    ->whereIn('status', [
                        ParcelAssignment::STATUS_ASSIGNED,
                        ParcelAssignment::STATUS_HANDED_OFF,
                        ParcelAssignment::STATUS_READY_TO_TRANSFER,
                    ]);
            })->orWhere(function (Builder $q) use ($profile): void {
                $q->where('picked_up_by', $profile->id)
                    ->where('status', ParcelAssignment::STATUS_HANDED_OFF);
            })
            ->orderByDesc('assigned_at')
            ->get()
            ->reject(fn (ParcelAssignment $assignment): bool => $assignment->rider_profile_id === $profile->id
                && $this->awaitingDispatchDecision($assignment))
            ->values();

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
            ->with(['order.items.product', 'order.seller.sellerDetail', 'barangayAssignment', 'logisticsCompany', 'transferToCompany'])
            ->where('order_id', $order->id)
            ->where('rider_profile_id', $profile->id)
            // Same exclusion as [index] — a transfer leg a scan can't
            // surface until dispatch has confirmed the handoff.
            ->where('status', '!=', ParcelAssignment::STATUS_TRANSFER_ASSIGNED)
            ->first();

        // Same "still an open local-delivery-or-transfer decision" check
        // as [index] — needs the company relation, so it runs after the
        // fetch rather than as a SQL condition above.
        if (! $assignment || $this->awaitingDispatchDecision($assignment)) {
            return $notFound;
        }

        $this->recordScan($assignment, ParcelScanEvent::CHECKPOINT_VERIFY, $profile);

        return response()->json([
            'data' => $this->present($assignment, $profile),
            'next_action' => $this->nextAction($assignment, $assignment->order, $profile),
        ]);
    }

    /**
     * Rider-initiated pickup confirmation. Handles two different legs that
     * share the same "I now physically have this parcel" shape:
     *
     * - 'assigned' -> 'handed_off', *releasing the rider*
     *   (rider_profile_id -> null). Pickup and delivery are two separate
     *   legs run by two different people: once the pickup courier has the
     *   parcel and has confirmed it, the parcel goes back to the logistics
     *   sorting queue tagged "To be delivered", where dispatch assigns a
     *   delivery rider (Api\Logistics\ParcelAssignmentController::assign,
     *   which keeps it 'handed_off' — the delivery rider goes straight to
     *   "mark delivered", not through pickup again). It does NOT stay on
     *   the pickup courier's plate as their delivery.
     * A transfer leg's 'transfer_assigned' -> 'ready_to_transfer' step is
     * deliberately NOT handled here, unlike the local leg above — a
     * cross-company handoff always needs dispatch's own counter
     * confirmation (Api\Logistics\ParcelAssignmentController::handoff),
     * never a rider self-serve tap, so that row stays out of this rider's
     * list entirely (see [index]) until dispatch has confirmed it and it
     * lands on 'ready_to_transfer'.
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

        // Excludes STATUS_TRANSFER_ASSIGNED on purpose — that leg is
        // staff-confirm-only now (see this method's docblock), so it's
        // invisible here even to the rider it's earmarked for, the same
        // way [index] hides it from their list.
        $assignment = ParcelAssignment::query()
            ->with(['order.items.product', 'order.seller.sellerDetail', 'barangayAssignment', 'logisticsCompany', 'transferToCompany'])
            ->where('rider_profile_id', $profile->id)
            ->whereIn('status', [
                ParcelAssignment::STATUS_ASSIGNED,
                ParcelAssignment::STATUS_HANDED_OFF,
                ParcelAssignment::STATUS_READY_TO_TRANSFER,
            ])
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

        // Idempotent: already moved on (e.g. dispatch beat the rider to
        // it, or a retried request) -> just return the current state
        // instead of erroring.
        if ($assignment->status === ParcelAssignment::STATUS_HANDED_OFF
            || $assignment->status === ParcelAssignment::STATUS_READY_TO_TRANSFER) {
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

            // The parcel is now in this company's hands, not the
            // seller's — every match from here on is against the BUYER's
            // address instead, same tiered rule intake() used for the
            // seller's (see ParcelAutoAssignService::matchRider's
            // $isPickupPhase). No manual "Auto assign" step any more, so
            // this has to land on the right delivery rider right here —
            // stamping the *seller's* barangay_assignment_id here would
            // silently leave the parcel routed by the wrong address.
            $order = $lockedAssignment->order;
            $company = $lockedAssignment->logisticsCompany;
            $match = $order && $company
                ? app(ParcelAutoAssignService::class)->matchRider(
                    $order,
                    $company,
                    $lockedAssignment->required_vehicle_type,
                    false,
                )
                : ['rider' => null, 'barangayAssignment' => null];

            $lockedAssignment->update([
                'status' => ParcelAssignment::STATUS_HANDED_OFF,
                'handed_off_at' => now(),
                'pickup_photo_path' => $photoPath,
                'barangay_assignment_id' => $match['barangayAssignment']?->id,
                // Set only when a delivery rider was actually matched —
                // null means "handed off, still needs a delivery rider",
                // the same "awaiting dispatch decision" state the rest of
                // the app already checks for (see
                // Api\Logistics\ParcelAssignmentController::awaitingDispatchDecision).
                'rider_profile_id' => $match['rider']?->id,
                'assigned_at' => $match['rider'] ? now() : null,
                'picked_up_by' => $profile->id,
            ]);
        });

        $assignment->refresh()->load(['order.items.product', 'order.seller.sellerDetail', 'barangayAssignment', 'logisticsCompany', 'transferToCompany']);

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
            ->with(['order.items.product', 'order.seller.sellerDetail', 'barangayAssignment', 'logisticsCompany', 'transferToCompany'])
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

        $assignment->refresh()->load(['order.items.product', 'order.seller.sellerDetail', 'barangayAssignment', 'logisticsCompany', 'transferToCompany']);

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
     * Cross-region-transfer counterpart to [deliver]: confirms this rider
     * reached the target logistics company's hub instead of a buyer's
     * doorstep. Requires the same photo proof (and optional QR scan) as
     * [deliver] — moves STATUS_READY_TO_TRANSFER -> STATUS_TRANSFERRED and,
     * unlike a same-company delivery, this is also the point where the
     * receiving company's own "to be delivered" row finally opens
     * (ParcelIntakeService::createTransferReceipt) and the accepted
     * parcel_transfer_requests row gets its `resulting_assignment_id`.
     * Nothing on the target company's side existed before this call — see
     * Api\Logistics\ParcelAssignmentController::acceptTransferRequest's
     * docblock for why custody doesn't move on accept.
     */
    public function confirmTransfer(Request $request, string $parcelAssignment): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();

        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:10240'],
            'confirmation_token' => ['nullable', 'string', 'max:128'],
        ], [
            'photo.required' => 'Please attach a photo of the parcel before confirming this transfer.',
            'photo.mimes' => 'Photo must be a JPG or PNG image.',
            'photo.max' => 'Photo must not be larger than 10MB.',
        ]);

        $assignment = ParcelAssignment::query()
            ->with(['order.items.product', 'order.seller.sellerDetail', 'barangayAssignment', 'logisticsCompany', 'transferToCompany'])
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
            $this->recordScan($assignment, ParcelScanEvent::CHECKPOINT_TRANSFER, $profile);
        }

        // Idempotent: already transferred (e.g. a retried request) -> just
        // return the current state instead of erroring.
        if ($assignment->status === ParcelAssignment::STATUS_TRANSFERRED) {
            return response()->json(['data' => $this->present($assignment, $profile)]);
        }

        if ($assignment->status !== ParcelAssignment::STATUS_READY_TO_TRANSFER) {
            return response()->json([
                'message' => "This parcel isn't ready to be transferred yet.",
            ], 422);
        }

        $targetCompany = $assignment->transferToCompany;
        if (! $targetCompany) {
            return response()->json(['message' => 'No destination company is set on this transfer.'], 422);
        }

        $file = $request->file('photo');
        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $photoPath = "profile/{$profile->id}/transfers/{$assignment->id}/".(string) Str::uuid().'.'.$extension;

        try {
            $this->supabaseStorage->upload($file, $photoPath);
        } catch (\Throwable $e) {
            Log::error('Transfer photo upload to Supabase failed: '.$e->getMessage());

            return response()->json(['message' => 'Failed to upload the transfer photo. Please try again.'], 500);
        }

        DB::transaction(function () use ($assignment, $targetCompany, $photoPath): void {
            // Lock the row so a concurrent change can't race this one —
            // same pattern used by pickup()/deliver().
            $lockedAssignment = ParcelAssignment::whereKey($assignment->id)->lockForUpdate()->first();

            if ($lockedAssignment->status !== ParcelAssignment::STATUS_READY_TO_TRANSFER) {
                return;
            }

            $lockedAssignment->update([
                'status' => ParcelAssignment::STATUS_TRANSFERRED,
                'transferred_at' => now(),
                'transfer_photo_path' => $photoPath,
            ]);

            $receipt = $this->parcelIntake->createTransferReceipt($lockedAssignment, $targetCompany);

            ParcelTransferRequest::query()
                ->where('parcel_assignment_id', $lockedAssignment->id)
                ->where('status', ParcelTransferRequest::STATUS_ACCEPTED)
                ->latest('requested_at')
                ->first()
                ?->update(['resulting_assignment_id' => $receipt->id]);
        });

        $assignment->refresh()->load(['order.items.product', 'order.seller.sellerDetail', 'barangayAssignment', 'logisticsCompany', 'transferToCompany']);

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
            // A transfer leg's destination is another logistics company's
            // hub, not the buyer's doorstep — same "{company} — Sorting
            // Hub" shape as pickup_label above, just for the target
            // company instead of this one.
            'dropoff_label' => $assignment->transferToCompany
                ? trim($assignment->transferToCompany->company_name.' — Sorting Hub')
                : (collect([
                    $order?->shipping_house_no,
                    $order?->shipping_street,
                    $order?->shipping_barangay,
                    $order?->shipping_municipality_name,
                    $order?->shipping_province_name,
                ])->filter()->implode(', ') ?: null),
            'delivery_area' => collect([
                $assignment->barangayAssignment?->barangay,
                $assignment->barangayAssignment?->municipality_name,
            ])->filter()->implode(', ') ?: null,
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
            // True for a cross-region transfer leg — this parcel is being
            // ferried to another logistics company's hub, not delivered to
            // the buyer. Drives the app's transfer-specific copy/sections.
            'is_transfer' => (bool) $assignment->is_transfer,
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
            // 'transfer_assigned' deliberately has no self-serve action —
            // that leg is staff-confirm-only now (see [pickup]'s docblock)
            // — so it falls through to 'none' here too.
            'assigned' => 'pickup',
            'picked_up' => 'deliver',
            'ready_to_transfer' => 'confirm_transfer',
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
     *
     * A cross-region transfer leg maps onto its own parallel states —
     * transfer_assigned, ready_to_transfer, transferred — once dispatch has
     * accepted the request and picked a courier (see this controller's
     * class docblock and [confirmTransfer]); checked first since none of
     * them overlap the plain-delivery states above.
     */
    private function deliveryStatus(ParcelAssignment $assignment, ?Order $order, ?Profile $viewer = null): string
    {
        if ($assignment->status === ParcelAssignment::STATUS_TRANSFER_ASSIGNED) {
            return 'transfer_assigned';
        }

        if ($assignment->status === ParcelAssignment::STATUS_READY_TO_TRANSFER) {
            return 'ready_to_transfer';
        }

        if ($assignment->status === ParcelAssignment::STATUS_TRANSFERRED) {
            return 'transferred';
        }

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
