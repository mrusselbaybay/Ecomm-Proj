<?php

namespace App\Http\Requests\Seller;

use App\Services\InventoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/seller/products/{id}/stock-adjustments
 *
 * `delta` is the signed change (add / subtract), never a replacement
 * value. The server reads the real current stock itself — nothing here
 * is trusted as the "current" quantity.
 */
class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'seller';
    }

    public function rules(): array
    {
        return [
            'variant_id' => ['nullable', 'uuid'],
            'delta' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'reason' => ['required', Rule::in(InventoryService::MANUAL_REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'delta.not_in' => 'Enter a non-zero quantity to add or remove.',
            'reason.in' => 'Choose a valid reason for the adjustment.',
        ];
    }
}
