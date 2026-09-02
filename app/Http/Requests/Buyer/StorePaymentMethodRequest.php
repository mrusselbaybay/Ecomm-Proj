<?php

namespace App\Http\Requests\Buyer;

use App\Models\BuyerPaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The browser derives brand / last 4 / expiry from the full card number
 * (Luhn + brand check in useBuyerPayments.js) and sends only those safe
 * fields — the full PAN and CVV never reach the server. These rules
 * reject anything that looks like a full number in `last4`.
 */
class StorePaymentMethodRequest extends FormRequest
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
            'type' => ['required', Rule::in(BuyerPaymentMethod::TYPES)],

            'brand' => ['required_if:type,card', 'nullable', 'string', 'max:40'],
            'last4' => ['required_if:type,card', 'nullable', 'digits:4'],
            'holder' => ['required_if:type,card', 'nullable', 'string', 'max:120'],
            'exp_month' => ['required_if:type,card', 'nullable', 'regex:/^(0[1-9]|1[0-2])$/'],
            'exp_year' => ['required_if:type,card', 'nullable', 'integer', 'min:2000', 'max:2100'],

            'provider' => ['required_if:type,wallet', 'nullable', Rule::in(BuyerPaymentMethod::WALLET_PROVIDERS)],
            'phone_masked' => ['required_if:type,wallet', 'nullable', 'string', 'max:40'],

            'label' => ['nullable', 'string', 'max:60'],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
