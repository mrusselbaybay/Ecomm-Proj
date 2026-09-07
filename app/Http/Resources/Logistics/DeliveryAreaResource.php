<?php

namespace App\Http\Resources\Logistics;

use App\Models\LogisticsDeliveryAreaMunicipality;
use App\Models\ParcelAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryAreaResource extends JsonResource
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
            'name' => $this->name,
            'province_name' => $this->province_name,
            'municipalities' => $this->whenLoaded('municipalities', fn () => $this->municipalities->map(
                fn (LogisticsDeliveryAreaMunicipality $m) => [
                    'id' => $m->id,
                    'code' => $m->municipality_code,
                    'name' => $m->municipality_name,
                    'barangay' => $m->barangay,
                ]
            )->values()),
            'is_active' => $this->is_active,
            'riders' => $this->whenLoaded('riders', fn () => $this->riders->map(fn ($rider) => [
                'id' => $rider->id,
                'first_name' => $rider->first_name,
                'last_name' => $rider->last_name,
                'email' => $rider->email,
                'contact_no' => $rider->contact_no,
                'vehicle' => $rider->courierDetail?->vehicle,
                'plate_number' => $rider->courierDetail?->plate_number,
                'address' => $rider->address?->full_address ?: null,
                'active_parcels' => ParcelAssignment::activeCountFor($rider->id),
                'parcel_quota' => ParcelAssignment::COURIER_QUOTA,
            ])->values()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
