<?php

namespace App\Http\Requests\Buyer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The first message of a conversation (opened from a product, store or
 * order page): text, up to four photos, or both, under the same limits as
 * SendMessageRequest.
 */
class StartConversationRequest extends FormRequest
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
            'seller_id' => ['required', 'uuid', 'exists:profiles,id'],
            'order_number' => ['nullable', 'string', 'max:64'],
            'product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:4000', 'required_without:images'],
            'images' => ['nullable', 'array', 'max:'.SendMessageRequest::MAX_IMAGES],
            'images.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.SendMessageRequest::MAX_IMAGE_KILOBYTES],
            'client_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return (new SendMessageRequest)->messages();
    }
}
