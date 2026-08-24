<?php

namespace App\Http\Requests\Buyer;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'buyer';
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'uuid', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.variation' => ['nullable', 'string', 'max:100'],

            'delivery_address' => ['required', 'array'],
            'delivery_address.recipient_name' => ['required', 'string', 'max:255'],
            'delivery_address.contact_number' => ['nullable', 'string', 'max:30'],
            'delivery_address.address' => ['required', 'string', 'max:500'],

            'shipping_method' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'voucher_code' => ['nullable', 'string', 'max:100'],

            // Client-sent totals are informational only — never trusted.
            // See CheckoutService::checkout(), which recalculates
            // subtotal/shipping/discount/total from the database.
        ];
    }
}