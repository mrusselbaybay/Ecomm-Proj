<?php

namespace App\Http\Requests\Seller;

use App\Rules\SupportedChatTemplate;
use Illuminate\Foundation\Http\FormRequest;

class UpdateQuickReplyResponseRequest extends FormRequest
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
            'response' => ['required', 'string', 'max:1000', app(SupportedChatTemplate::class)],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
