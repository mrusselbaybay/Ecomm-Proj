<?php

namespace App\Http\Requests\Buyer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A buyer's message: text, up to four photos, or both. `client_id` is the
 * browser's own id for the pending bubble, echoed back so a retried or
 * polled message is never shown twice. `product_id` is set when the buyer
 * is asking about a product (opened from its page): the conversation's
 * product reference moves to it.
 */
class SendMessageRequest extends FormRequest
{
    public const MAX_IMAGES = 4;

    public const MAX_IMAGE_KILOBYTES = 5120;

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
            'body' => ['nullable', 'string', 'max:4000', 'required_without:images'],
            'images' => ['nullable', 'array', 'max:'.self::MAX_IMAGES],
            'images.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.self::MAX_IMAGE_KILOBYTES],
            'client_id' => ['nullable', 'string', 'max:64'],
            'product_id' => ['nullable', 'uuid', 'exists:products,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required_without' => 'Write a message or add a photo.',
            'images.max' => 'You can send up to '.self::MAX_IMAGES.' photos at a time.',
            'images.*.mimetypes' => 'Photos must be JPEG, PNG or WebP.',
            'images.*.max' => 'Each photo must be 5 MB or smaller.',
        ];
    }
}
