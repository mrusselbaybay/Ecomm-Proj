<?php

namespace App\Http\Requests\Admin;

use App\Models\Complaint;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateComplaintRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->getAttribute('role') === 'admin';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(Complaint::STATUSES)],
            'priority' => ['required', Rule::in(Complaint::PRIORITIES)],
            'assigned_admin_id' => ['nullable', 'uuid', Rule::exists('profiles', 'id')->where('role', 'admin')],
            'notes' => ['required', 'string', 'min:5', 'max:3000'],
            'resolution' => ['nullable', 'required_if:status,resolved', 'string', 'min:5', 'max:3000'],
            'is_internal' => ['required', 'boolean'],
        ];
    }
}
