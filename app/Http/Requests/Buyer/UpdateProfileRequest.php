<?php

namespace App\Http\Requests\Buyer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Whitelists the only fields a buyer may change on their own profile.
 * `role`, `status`, `account_status`, `email`, `id` are deliberately
 * absent — see Buyer\AccountController for why this endpoint exists
 * (the old path wrote to public.profiles straight from the browser,
 * bypassing Profile::booted()'s role-escalation guard).
 */
class UpdateProfileRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'middle_initial' => ['nullable', 'string', 'max:1'],
            'sex' => ['nullable', Rule::in(['Male', 'Female', 'Prefer not to say'])],
            'contact_no' => ['nullable', 'string', 'max:30'],
            'birthday' => ['nullable', 'date', 'before:today'],
        ];
    }
}
