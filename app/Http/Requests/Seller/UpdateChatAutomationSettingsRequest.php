<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChatAutomationSettingsRequest extends FormRequest
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
            'auto_reply_enabled' => ['sometimes', 'boolean'],
            'bot_mode' => ['sometimes', Rule::in(['off', 'suggest', 'auto'])],
            'presence_mode' => ['sometimes', Rule::in(['automatic', 'away'])],
            'seller_status' => ['sometimes', Rule::in(['automatic', 'online', 'away'])],
            'generic_away_response' => ['required', 'string', 'max:1000'],
            'generic_online_response' => ['sometimes', 'string', 'max:1000'],
            'generic_reply_cooldown_minutes' => ['required', 'integer', 'min:15', 'max:1440'],
        ];
    }
}
