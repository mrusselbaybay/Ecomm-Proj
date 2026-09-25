<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, \App\Models\Profile::ADMIN_ROLES, true);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['nullable', 'required_if:status,rejected', 'string', 'max:500'],
        ];
    }
}