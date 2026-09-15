<?php

namespace App\Http\Requests\Buyer;

use Illuminate\Foundation\Http\FormRequest;

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
            // Both optional: messaging a seller in general (no specific
            // order/product — e.g. from the seller's page) is valid, not
            // just messaging about a particular purchase.
            'seller_id' => ['required', 'uuid', 'exists:profiles,id'],
            'order_number' => ['nullable', 'string', 'max:64'],
            'product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'subject' => ['nullable', 'string', 'max:200'],
            // Nullable: clicking "Message Seller" now opens/creates the
            // thread straight into the conversation screen with no typed
            // message required — the buyer types their first message once
            // the thread is already open, same as any existing thread.
            'body' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
