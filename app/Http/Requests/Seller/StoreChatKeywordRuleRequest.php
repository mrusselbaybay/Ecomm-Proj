<?php

namespace App\Http\Requests\Seller;

use App\Rules\SupportedChatTemplate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChatKeywordRuleRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'keyword' => [
                'required',
                'string',
                'min:2',
                'max:100',
                Rule::unique('seller_chat_rules', 'normalized_keyword')
                    ->where('seller_id', $this->user()?->id),
            ],
            'response_template' => ['required', 'string', 'max:1000', app(SupportedChatTemplate::class)],
            'is_active' => ['required', 'boolean'],
            'priority' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['keyword' => mb_strtolower(trim((string) $this->input('keyword')))]);
    }
}
