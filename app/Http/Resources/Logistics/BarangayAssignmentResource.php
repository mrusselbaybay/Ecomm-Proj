<?php

namespace App\Http\Resources\Logistics;

use App\Models\ParcelAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BarangayAssignmentResource extends JsonResource
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
            'province_name' => $this->province_name,
            'municipality_code' => $this->municipality_code,
            'municipality_name' => $this->municipality_name,
            'barangay' => $this->barangay,
            'is_active' => $this->is_active,
            'delivered_count' => $this->delivered_count ?? ParcelAssignment::deliveredCountsFor([$this->id])[$this->id] ?? 0,
            'rider' => $this->whenLoaded('rider', fn (): ?array => $this->rider ? [
                'id' => $this->rider->id,
                'first_name' => $this->rider->first_name,
                'last_name' => $this->rider->last_name,
                'email' => $this->rider->email,
                'contact_no' => $this->rider->contact_no,
                'vehicle' => $this->rider->courierDetail?->vehicle,
                'plate_number' => $this->rider->courierDetail?->plate_number,
                'address' => $this->rider->address?->full_address ?: null,
                'active_parcels' => ParcelAssignment::activeCountFor($this->rider->id),
                'parcel_quota' => ParcelAssignment::COURIER_QUOTA,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
