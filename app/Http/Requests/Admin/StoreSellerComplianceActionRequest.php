<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSellerComplianceActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['verify', 'warn', 'remove', 'restore', 'suspend'])],
            'reason' => ['nullable', 'required_unless:action,verify,restore', 'string', 'min:5', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
