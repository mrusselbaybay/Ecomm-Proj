<?php

namespace App\Http\Requests\Buyer;

use Illuminate\Foundation\Http\FormRequest;

class StartCourierConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'buyer';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_number' => ['required', 'string', 'max:64'],
            // Nullable: "Message Courier" now opens/creates the thread
            // straight into the conversation screen with no typed message
            // required.
            'body' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
