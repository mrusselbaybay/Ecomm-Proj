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
            'house_no' => ['nullable', 'string', 'max:50'],
            'line1' => ['sometimes', 'required', 'string', 'max:500'],
            'region_name' => ['sometimes', 'required', Rule::in(BuyerAddress::REGIONS)],
            'province_code' => ['sometimes', 'required', 'string', 'max:20'],
            'province' => ['sometimes', 'required', 'string', 'max:255'],
            'municipality_code' => ['sometimes', 'required', 'string', 'max:20'],
            'city' => ['sometimes', 'required', 'string', 'max:255'],
            'barangay' => ['sometimes', 'required', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'label' => ['nullable', Rule::in(BuyerAddress::LABELS)],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
