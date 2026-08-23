<?php

namespace App\Http\Resources\Logistics;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LogisticsApplicationResource extends JsonResource
{
    /**
     * Transform an application into the logistics dashboard's API shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'courier_profile_id' => $this->courier_profile_id,
            'logistics_company_id' => $this->logistics_company_id,
            'status' => $this->status,
            'applied_at' => $this->applied_at?->toISOString(),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'interview_invited_at' => $this->interview_invited_at?->toISOString(),
            'interview_scheduled_at' => $this->interview_scheduled_at?->toISOString(),
            'cover_note' => $this->cover_note,
            'resume_original_name' => $this->resume_original_name,
            'resume_size' => $this->resume_size,
            'has_resume' => filled($this->resume_path),
            'courier' => $this->whenLoaded('courier', function (): ?array {
                return [
                    'id' => $this->courier?->id,
                    'first_name' => $this->courier?->first_name,
                    'last_name' => $this->courier?->last_name,
                    'email' => $this->courier?->email,
                    'contact_no' => $this->courier?->contact_no,
                ];
            }),
            'courier_details' => $this->whenLoaded('courier', function (): ?array {
                $details = $this->courier?->courierDetail;

                if (! $details) {
                    return null;
                }

                return [
                    'vehicle' => $details->vehicle,
                    'plate_number' => $details->plate_number,
                ];
            }),
        ];
    }
}
