<?php

namespace App\Http\Requests\Admin;

use App\Models\SupportTicket;
use App\Policies\SupportTicketPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupportTicketStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $this->user() !== null
            && $ticket instanceof SupportTicket
            && app(SupportTicketPolicy::class)->changeStatus($this->user(), $ticket);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['open', 'waiting_for_customer', 'escalated', 'reopened'])],
        ];
    }
}
