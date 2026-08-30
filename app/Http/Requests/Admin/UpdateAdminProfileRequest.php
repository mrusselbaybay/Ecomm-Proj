<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admins may only edit their own name fields here — email, role and
 * account_status are intentionally absent from the rules below, so even
 * a caller who sends them is ignored (the controller only ever mass-
 * assigns the three keys validated here).
 */
class UpdateAdminProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'alpha', 'min:2', 'max:50'],
            'last_name' => ['required', 'string', 'alpha', 'min:2', 'max:50'],
            'middle_initial' => ['nullable', 'string', 'alpha', 'size:1'],

            'region_code' => ['nullable', 'string', 'max:20'],
            'region_name' => ['nullable', 'string', 'max:255'],
            'province_code' => ['nullable', 'string', 'max:20'],
            'province_name' => ['nullable', 'string', 'max:255'],
            'municipality_code' => ['nullable', 'string', 'max:20'],
            'municipality_name' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'house_no' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.alpha' => 'Please enter a valid first name (letters only).',
            'first_name.min' => 'Please enter a valid first name (2-50 letters).',
            'first_name.max' => 'Please enter a valid first name (2-50 letters).',
            'last_name.alpha' => 'Please enter a valid last name (letters only).',
            'last_name.min' => 'Please enter a valid last name (2-50 letters).',
            'last_name.max' => 'Please enter a valid last name (2-50 letters).',
            'middle_initial.alpha' => 'Middle initial must be a single letter.',
            'middle_initial.size' => 'Middle initial must be a single letter.',
        ];
    }
}
