<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class BulkCreateBarangayAssignmentsRequest extends FormRequest
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
            'barangays' => ['required', 'array', 'min:1', 'max:1000'],
            'barangays.*' => ['required', 'string', 'max:150'],
        ];
    }
}
