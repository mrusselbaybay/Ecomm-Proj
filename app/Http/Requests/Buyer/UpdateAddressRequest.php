<?php

namespace App\Http\Requests\Buyer;

use App\Models\BuyerAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAddressRequest extends FormRequest
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
            'recipient_name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_no' => ['sometimes', 'required', 'string', 'max:30'],
            'line1' => ['sometimes', 'required', 'string', 'max:500'],
            'city' => ['sometimes', 'required', 'string', 'max:255'],
            'province' => ['sometimes', 'required', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'label' => ['nullable', Rule::in(BuyerAddress::LABELS)],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
