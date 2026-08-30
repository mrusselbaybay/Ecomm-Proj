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
            'received_at' => $this->received_at?->toISOString(),
            'assigned_at' => $this->assigned_at?->toISOString(),
            'handed_off_at' => $this->handed_off_at?->toISOString(),
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
