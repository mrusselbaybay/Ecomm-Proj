<?php

namespace App\Http\Requests\Buyer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Backend-side gate for the buyer account settings "Danger Zone" self-
 * deactivation flow. Mirrors DeactivateAdminAccountRequest: the frontend
 * enforces its own two-step UX (typed "DEACTIVATE" phrase, then a re-armed
 * confirm after a delay), but the server independently requires both the
 * exact phrase and the caller's current password — a client-only check is
 * not a security boundary.
 */
class DeactivateBuyerAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'buyer';
    }

    public function rules(): array
    {
        return [
            'confirmation_phrase' => ['required', 'string', 'in:DEACTIVATE'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation_phrase.in' => 'Type DEACTIVATE exactly to confirm.',
        ];
    }
}
