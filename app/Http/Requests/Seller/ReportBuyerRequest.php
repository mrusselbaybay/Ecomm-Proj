<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/seller/messages/conversations/{id}/report
 *
 * See MessageController::report() — this is intentionally a log-only
 * acknowledgement, not a write into the (admin-owned) complaints table.
 */
class ReportBuyerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'seller';
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
