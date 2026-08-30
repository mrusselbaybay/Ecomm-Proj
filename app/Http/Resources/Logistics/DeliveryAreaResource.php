<?php

namespace App\Http\Resources\Logistics;

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
            'municipality_name' => $this->municipality_name,
            'barangay' => $this->barangay,
            'is_active' => $this->is_active,
            'rider' => $this->whenLoaded('rider', function (): ?array {
                if (! $this->rider) {
                    return null;
                }

                return [
                    'id' => $this->rider->id,
                    'first_name' => $this->rider->first_name,
                    'last_name' => $this->rider->last_name,
                    'email' => $this->rider->email,
                    'contact_no' => $this->rider->contact_no,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
