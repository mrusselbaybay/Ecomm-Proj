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
            // A "pure card" message (the "Inquire about a certain product"
            // picker) has neither body nor attachments — order_id is
            // content enough on its own, so it counts alongside
            // attachment_ids as satisfying the other.
            'body' => ['nullable', 'required_without_all:attachment_ids,order_id', 'string', 'max:4000'],
            'attachment_ids' => ['nullable', 'required_without_all:body,order_id', 'array', 'max:5'],
            'attachment_ids.*' => ['uuid', 'distinct'],
            'order_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
        ];
    }
}
