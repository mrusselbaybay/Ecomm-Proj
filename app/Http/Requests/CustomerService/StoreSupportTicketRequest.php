<?php

namespace App\Http\Requests\CustomerService;

use App\Models\SupportTicket;
use App\Policies\SupportTicketPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupportTicketRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(SupportTicketPolicy::class)->create($this->user());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(SupportTicket::CATEGORIES)],
            'subject' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:6000'],
            'order_id' => ['nullable', 'uuid', 'exists:orders,id'],
            'assigned_admin_id' => ['prohibited'],
            'priority' => ['prohibited'],
            'status' => ['prohibited'],
            'resolution_summary' => ['prohibited'],
        ];
    }
}
