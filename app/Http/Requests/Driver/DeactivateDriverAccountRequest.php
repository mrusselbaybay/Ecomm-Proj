<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Backend-side gate for the driver/courier Settings "Danger Zone" self-
 * deactivation flow. Mirrors Buyer\DeactivateBuyerAccountRequest: the
 * frontend enforces its own two-step UX (typed "DEACTIVATE" phrase), but
 * the server independently requires both the exact phrase and the
 * caller's current password — a client-only check is not a security
 * boundary.
 */
class DeactivateDriverAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['driver', 'courier'], true);
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
