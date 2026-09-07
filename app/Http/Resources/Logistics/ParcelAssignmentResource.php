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
            // False for a row the seller's handover created but that
            // hasn't been physically scanned in at the sorting center
            // yet — see App\Services\ParcelIntakeService.
            'is_scanned' => (bool) $this->scanned_at,
            'order' => [
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
            ],
            'delivery_area' => $this->whenLoaded('deliveryArea', fn (): ?array => $this->deliveryArea ? [
                'id' => $this->deliveryArea->id,
                'name' => $this->deliveryArea->name,
            ] : null),
            'rider' => $this->whenLoaded('rider', fn (): ?array => $this->rider ? [
                'id' => $this->rider->id,
                'first_name' => $this->rider->first_name,
                'last_name' => $this->rider->last_name,
                'contact_no' => $this->rider->contact_no,
            ] : null),
        ];
    }
}
