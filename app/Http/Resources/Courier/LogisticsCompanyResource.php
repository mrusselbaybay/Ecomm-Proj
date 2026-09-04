<?php

namespace App\Http\Resources\Courier;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LogisticsCompanyResource extends JsonResource
{
    /**
     * Transform the resource into the courier-facing API shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_name' => $this->company_name,
            'company_email' => $this->company_email,
            'company_contact_no' => $this->company_contact_no,
            'region' => $this->region,
            'status' => $this->status,
            'account_status' => $this->account_status,
            // Courier-recruitment fields the logistics company edits from
            // its portal Account Settings page. `is_hiring` is already
            // filtered to true by LogisticsCompanyController, but it is
            // returned anyway so the client can label the listing.
            'description' => $this->description,
            'monthly_salary' => $this->monthly_salary !== null ? (float) $this->monthly_salary : null,
            'is_hiring' => (bool) $this->is_hiring,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
