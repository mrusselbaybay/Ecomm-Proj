<?php

namespace App\Http\Requests\Seller;

use App\Models\Conversation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/seller/messages/conversations/{id}/status
 */
class UpdateConversationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'seller';
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(Conversation::STATUSES)],
        ];
    }
}
