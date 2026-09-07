<?php

namespace App\Http\Resources\Logistics;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One incoming/outgoing cross-region transfer request, as shown in the
 * "Transfer requests" inbox on the Parcel sorting page. See
 * App\Models\ParcelTransferRequest.
 */
class ParcelTransferRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'parcel_assignment_id' => $this->parcel_assignment_id,
            'resulting_assignment_id' => $this->resulting_assignment_id,
            'response_note' => $this->response_note,
            'requested_at' => $this->requested_at?->toISOString(),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'from_company' => $this->whenLoaded('fromCompany', fn (): ?array => $this->fromCompany ? [
                'id' => $this->fromCompany->id,
                'company_name' => $this->fromCompany->company_name,
                'region' => $this->fromCompany->region,
            ] : null),
            'to_company' => $this->whenLoaded('toCompany', fn (): ?array => $this->toCompany ? [
                'id' => $this->toCompany->id,
                'company_name' => $this->toCompany->company_name,
                'region' => $this->toCompany->region,
            ] : null),
            'requested_by' => $this->whenLoaded('requester', fn (): ?array => $this->requester ? [
                'id' => $this->requester->id,
                'first_name' => $this->requester->first_name,
                'last_name' => $this->requester->last_name,
            ] : null),
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
            ],
        ];
    }
}
