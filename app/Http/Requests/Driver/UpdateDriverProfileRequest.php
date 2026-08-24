<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Self-service "Settings" edit for a driver/courier: name/sex/birthday/
 * contact fields on public.profiles, plus their single on-file address
 * (owner_kind = 'profile') — mirrors Buyer\UpdateBuyerProfileRequest.
 *
 * Email is intentionally absent — never editable here. Vehicle/plate/
 * license number are also absent: those live on driver_details /
 * courier_details and require re-verification to change, so they're
 * read-only in the Settings screen (contact support to update them).
 */
class UpdateDriverProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['driver', 'courier'], true);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'alpha', 'min:2', 'max:50'],
            'last_name' => ['required', 'string', 'alpha', 'min:2', 'max:50'],
            'middle_initial' => ['nullable', 'string', 'alpha', 'size:1'],
            'sex' => ['required', 'string', 'in:Male,Female'],
            'birthday' => ['required', 'date', 'before:today'],
            'contact_no' => ['required', 'string', 'regex:/^09\d{9}$/'],

            'province_code' => ['required', 'string', 'max:20'],
            'province_name' => ['required', 'string', 'max:255'],
            'municipality_code' => ['required', 'string', 'max:20'],
            'municipality_name' => ['required', 'string', 'max:255'],
            'barangay' => ['required', 'string', 'max:255'],
            'street' => ['required', 'string', 'max:255'],
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
            'sex.in' => 'Please select a valid sex.',
            'birthday.before' => 'Birthday cannot be in the future.',
            'contact_no.regex' => 'Enter a valid 11-digit number starting with 09.',
            'province_code.required' => 'Please select a province.',
            'municipality_code.required' => 'Please select a municipality/city.',
            'barangay.required' => 'Please select a barangay.',
            'street.required' => 'Please enter your street.',
        ];
    }
}
