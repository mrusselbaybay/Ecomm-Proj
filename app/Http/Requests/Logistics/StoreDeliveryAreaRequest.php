<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeliveryAreaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === 'logistics';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'province_name' => ['required', 'string', 'max:150'],
            'municipalities' => ['required', 'array', 'min:1'],
            'municipalities.*.name' => ['required', 'string', 'max:150'],
            'municipalities.*.code' => ['nullable', 'string', 'max:20'],
            'municipalities.*.barangay' => ['nullable', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
