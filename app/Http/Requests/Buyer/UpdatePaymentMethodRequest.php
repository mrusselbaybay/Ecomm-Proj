<?php

namespace App\Http\Requests\Buyer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A saved card's number can't be edited (real vaults make you remove +
 * re-add) — only the holder / expiry / label / primary flag.
 */
class UpdatePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'buyer';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'holder' => ['sometimes', 'required', 'string', 'max:120'],
            'exp_month' => ['sometimes', 'nullable', 'regex:/^(0[1-9]|1[0-2])$/'],
            'exp_year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'label' => ['nullable', 'string', 'max:60'],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
