<?php

namespace App\Http\Requests\Buyer;

use App\Support\CheckoutOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/buyer/checkout (place the order) and, with isQuote(),
 * POST /api/buyer/checkout/quote (price it, change nothing).
 *
 * Product and variant ids aren't checked for existence here: a product
 * that was deleted or hidden is reported by CheckoutService as "no longer
 * available" on that line, which the page can show next to the item.
 */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'buyer';
    }

    public function rules(): array
    {
        $shipping = Rule::in(array_keys(CheckoutOptions::SHIPPING));

        $rules = [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.variant_id' => ['nullable', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.variation' => ['nullable', 'string', 'max:100'],

            // Shipping is chosen per seller: { "<seller uuid>": "standard" }.
            // shipping_method is the fallback for sellers not listed.
            'shipping_methods' => ['nullable', 'array'],
            'shipping_methods.*' => ['string', $shipping],
            'shipping_method' => ['nullable', 'string', $shipping],
            'payment_method' => ['nullable', 'string', Rule::in(CheckoutOptions::paymentMethodIds())],
        ];

        if ($this->isQuote()) {
            return $rules;
        }

        return [
            ...$rules,
            'delivery_address' => ['required', 'array'],
            'delivery_address.recipient_name' => ['required', 'string', 'max:255'],
            'delivery_address.contact_number' => ['required', 'string', 'regex:/^09\d{9}$/'],
            'delivery_address.address' => ['required', 'string', 'max:500'],
            'delivery_address.city' => ['nullable', 'string', 'max:120'],
            'delivery_address.province' => ['nullable', 'string', 'max:120'],

            'payment_method' => ['required', 'string', Rule::in(CheckoutOptions::paymentMethodIds())],
            'voucher_code' => ['nullable', 'string', 'max:100'],

            // The total from the quote the buyer confirmed: if the server's
            // total differs, nothing is ordered and the new quote comes
            // back (409) for the buyer to review.
            'expected_total' => ['nullable', 'numeric', 'min:0'],
            // One key per checkout attempt: repeating a request with the
            // same key returns the orders already created instead of
            // creating them twice.
            'idempotency_key' => ['nullable', 'uuid'],
        ];
    }

    public function isQuote(): bool
    {
        return $this->routeIs('api.buyer.checkout.quote');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_method.required' => 'Choose how you want to pay.',
            'payment_method.in' => 'That payment method isn\'t available. Please choose Cash on Delivery.',
            'delivery_address.recipient_name.required' => 'Add the name of the person receiving the parcel.',
            'delivery_address.contact_number.required' => 'Add a mobile number so the courier can reach you.',
            'delivery_address.contact_number.regex' => 'Use an 11-digit mobile number starting with 09.',
            'delivery_address.address.required' => 'Add a complete delivery address.',
        ];
    }
}
