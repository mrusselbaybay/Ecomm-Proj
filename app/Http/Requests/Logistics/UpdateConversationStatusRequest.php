<?php

namespace App\Http\Requests\Logistics;

use App\Models\Conversation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/logistics/messages/conversations/{id}/status
 */
class UpdateConversationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'logistics';
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(Conversation::STATUS_REQUEST_VALUES)],
        ];
    }
}
