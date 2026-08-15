<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['activate', 'suspend', 'deactivate'])],
            // required when suspending or deactivating, so there's always a reason on record
            'reason' => ['nullable', 'required_if:action,suspend', 'required_if:action,deactivate', 'string', 'min:5', 'max:1000'],
        ];
    }
}