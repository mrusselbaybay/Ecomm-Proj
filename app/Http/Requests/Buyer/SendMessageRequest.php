<?php

namespace App\Http\Requests\Buyer;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
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
            'body' => ['nullable', 'required_without:attachment_ids', 'string', 'max:4000'],
            'attachment_ids' => ['nullable', 'required_without:body', 'array', 'max:5'],
            'attachment_ids.*' => ['uuid', 'distinct'],
        ];
    }
}
