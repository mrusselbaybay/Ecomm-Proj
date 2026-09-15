<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class StoreBarangayAssignmentRequest extends FormRequest
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
            'province_name' => ['required', 'string', 'max:150'],
            'municipality_code' => ['nullable', 'string', 'max:20'],
            'municipality_name' => ['required', 'string', 'max:150'],
            'barangay' => ['required', 'string', 'max:150'],
            'rider_profile_id' => ['nullable', 'uuid'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
