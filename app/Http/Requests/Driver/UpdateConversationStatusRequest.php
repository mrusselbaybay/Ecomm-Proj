<?php

namespace App\Http\Requests\Driver;

use App\Models\Conversation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/driver/messages/conversations/{id}/status
 */
class UpdateConversationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['driver', 'courier'], true);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(Conversation::STATUS_REQUEST_VALUES)],
        ];
    }
}
