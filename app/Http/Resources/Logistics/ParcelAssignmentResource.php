<?php

namespace App\Http\Resources\Logistics;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParcelAssignmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            // A seller/buyer island-group mismatch — this company can't
            // deliver it, only offer it to a company that can. See
            // Api\Logistics\ParcelAssignmentController::requestTransfer.
            'is_transfer' => (bool) $this->is_transfer,
            // Which seller-vs-buyer boundary this parcel crosses
            // ('municipality'|'province'|'region'|null) and the vehicle
            // class that implies ('car'|'van_or_truck'|null) — see
            // App\Services\TransferTriggerService. Only 'region' also
            // sets is_transfer above; the other two are normal in-company
            // deliveries that just need a bigger vehicle.
            'transfer_trigger' => $this->transfer_trigger,
            'required_vehicle_type' => $this->required_vehicle_type,
            // True for the fresh row a transfer opens at the receiving
            // company (see ParcelIntakeService::createTransferReceipt).
            // `is_transfer` above stays true here too (it's purely
            // seller-vs-buyer, not which company currently holds the
            // parcel), so the frontend needs this to tell "still needs a
            // transfer" apart from "just arrived, needs local delivery" —
            // see ParcelOperations.vue's stageOf().
            'is_transfer_receipt' => (bool) $this->previous_assignment_id,
            'transfer_to_company' => $this->whenLoaded('transferToCompany', fn (): ?array => $this->transferToCompany ? [
                'id' => $this->transferToCompany->id,
                'company_name' => $this->transferToCompany->company_name,
                'region' => $this->transferToCompany->region,
            ] : null),
            // Present only while status === 'transfer_pending': the parcel
            // has been offered to `transfer_to_company` and is waiting on
            // that company to accept or reject. Backs the "requested X
            // ago / cancel request" affordance in ParcelOperations.vue.
            'transfer_request' => $this->whenLoaded('pendingTransferRequest', fn (): ?array => $this->pendingTransferRequest ? [
                'id' => $this->pendingTransferRequest->id,
                'status' => $this->pendingTransferRequest->status,
                'requested_at' => $this->pendingTransferRequest->requested_at?->toISOString(),
            ] : null),
            'received_at' => $this->received_at?->toISOString(),
            'assigned_at' => $this->assigned_at?->toISOString(),
            'handed_off_at' => $this->handed_off_at?->toISOString(),
            'transferred_at' => $this->transferred_at?->toISOString(),
            // The For Inventory checkpoint (App\Models\ParcelAssignment::
            // STATUS_FOR_INVENTORY) — see Api\Logistics\
            // ParcelInventoryController for the scan that fills in
            // inventory_scanned_at/by.
            'for_inventory_at' => $this->for_inventory_at?->toISOString(),
            'inventory_scanned_at' => $this->inventory_scanned_at?->toISOString(),
            'inventory_origin' => $this->inventory_origin,
            'inventory_scanned_by' => $this->whenLoaded('inventoryScannedBy', fn (): ?array => $this->inventoryScannedBy ? [
                'id' => $this->inventoryScannedBy->id,
                'first_name' => $this->inventoryScannedBy->first_name,
                'last_name' => $this->inventoryScannedBy->last_name,
            ] : null),
            'picked_up_by' => $this->whenLoaded('pickedUpBy', fn (): ?array => $this->pickedUpBy ? [
                'id' => $this->pickedUpBy->id,
                'first_name' => $this->pickedUpBy->first_name,
                'last_name' => $this->pickedUpBy->last_name,
                'contact_no' => $this->pickedUpBy->contact_no,
            ] : null),
            // False for a row the seller's handover created but that
            // hasn't been physically scanned in at the sorting center
            // yet — see App\Services\ParcelIntakeService.
            'is_scanned' => (bool) $this->scanned_at,
            // Not yet collected from the seller: this rider (if any) is
            // the pickup courier, and every address on the card below
            // should be the SELLER's, not the buyer's. Once
            // handed_off_at is set the parcel is physically in hand and
            // everything switches to the buyer's shipping address — see
            // Api\Logistics\ParcelAssignmentController::assign's
            // $isDeliveryDispatch, the same "has it actually been
            // collected yet" check.
            'phase' => $this->handed_off_at ? 'delivery' : 'pickup',
            'order' => $this->handed_off_at ? [
                'id' => $this->order?->id,
                'order_number' => $this->order?->order_number,
                'tracking_number' => $this->order?->tracking_number,
                'recipient_name' => $this->order?->recipient_name,
                'recipient_contact_no' => $this->order?->recipient_contact_no,
                'address' => collect([
                    $this->order?->shipping_house_no,
                    $this->order?->shipping_street,
                    $this->order?->shipping_barangay,
                    $this->order?->shipping_municipality_name,
                    $this->order?->shipping_province_name,
                ])->filter()->implode(', '),
                'region_name' => $this->order?->shipping_region_name,
                'province_name' => $this->order?->shipping_province_name,
                'municipality_name' => $this->order?->shipping_municipality_name,
                'barangay' => $this->order?->shipping_barangay,
            ] : [
                'id' => $this->order?->id,
                'order_number' => $this->order?->order_number,
                'tracking_number' => $this->order?->tracking_number,
                'recipient_name' => $this->order?->seller
                    ? ($this->order->seller->sellerDetail?->business_name
                        ?: trim("{$this->order->seller->first_name} {$this->order->seller->last_name}"))
                    : null,
                'recipient_contact_no' => $this->order?->seller?->contact_no,
                'address' => collect([
                    $this->order?->pickup_barangay,
                    $this->order?->pickup_municipality_name,
                    $this->order?->pickup_province_name,
                ])->filter()->implode(', '),
                'region_name' => $this->order?->pickup_region_name,
                'province_name' => $this->order?->pickup_province_name,
                'municipality_name' => $this->order?->pickup_municipality_name,
                'barangay' => $this->order?->pickup_barangay,
            ],
            'barangay_assignment' => $this->whenLoaded('barangayAssignment', fn (): ?array => $this->barangayAssignment ? [
                'id' => $this->barangayAssignment->id,
                'municipality_name' => $this->barangayAssignment->municipality_name,
                'barangay' => $this->barangayAssignment->barangay,
            ] : null),
            // Set only when there's no barangay_assignment above — which
            // fallback pool this address would route through
            // ('provincial'|'regional'|null for "not in this company's
            // reach at all"). See
            // Api\Logistics\ParcelAssignmentController::index and
            // ParcelAutoAssignService::expectedTierFor.
            'area_fallback_tier' => $this->area_fallback_tier ?? null,
            'rider' => $this->whenLoaded('rider', fn (): ?array => $this->rider ? [
                'id' => $this->rider->id,
                'first_name' => $this->rider->first_name,
                'last_name' => $this->rider->last_name,
                'contact_no' => $this->rider->contact_no,
            ] : null),
        ];
    }
}
