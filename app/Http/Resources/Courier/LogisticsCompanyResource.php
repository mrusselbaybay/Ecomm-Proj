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
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
