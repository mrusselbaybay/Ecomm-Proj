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
            // Legacy (mobile): a claimed wallet entry (buyer_vouchers.id) per line.
            'items.*.coupon_id' => ['nullable', 'uuid'],
            // Per seller order: max one discount + one shipping voucher
            // (vouchers.id); validated in VoucherService::applySelection().
            'vouchers' => ['nullable', 'array', 'max:50'],
            'vouchers.*.seller_id' => ['required', 'uuid', 'distinct'],
            'vouchers.*.discount_voucher_id' => ['nullable', 'uuid'],
            'vouchers.*.shipping_voucher_id' => ['nullable', 'uuid'],
            // Cart-wide BuyTheWay vouchers; validated in PlatformVoucherService::applySelection().
            'platform_vouchers' => ['nullable', 'array'],
            'platform_vouchers.discount_voucher_id' => ['nullable', 'uuid'],
            'platform_vouchers.shipping_voucher_id' => ['nullable', 'uuid'],

            'delivery_address' => ['required', 'array'],
            // Saved address (buyer_addresses.id) to route the order to;
            // ownership is checked in CheckoutService::destination().
            'delivery_address.address_id' => ['nullable', 'uuid'],
            'delivery_address.recipient_name' => ['required', 'string', 'max:255'],
            'delivery_address.contact_number' => ['nullable', 'string', 'max:30'],
            'delivery_address.address' => ['required', 'string', 'max:500'],

            'shipping_method' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:100'],

            // Client-sent totals are informational only — never trusted.
            // See CheckoutService::checkout(), which recalculates
            // subtotal/shipping/discount/total from the database.
        ];
    }
}
