<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConversationAutomationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === 'seller';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'paused' => ['required', 'boolean'],
        ];
    }
}
