<?php

namespace App\Support;

/**
 * The one definition of how buyers can receive and pay for an order.
 * CheckoutService charges these fees and CheckoutRequest accepts only
 * these payment methods, and the public API (GET /api/checkout/options
 * and the store page's `fulfillment`) serves the same values, so a store
 * page can never quote something checkout won't honour.
 *
 * Shipping and payment are platform-wide: every seller ships its own
 * parcel at these flat fees, charged once per seller order.
 */
class CheckoutOptions
{
    /**
     * @var array<string, array{name: string, short_name: string, fee: float, eta: string}>
     */
    public const SHIPPING = [
        'standard' => [
            'name' => 'Standard Delivery',
            'short_name' => 'Standard',
            'fee' => 60.0,
            'eta' => '3-5 days',
        ],
        'express' => [
            'name' => 'Express Delivery',
            'short_name' => 'Express',
            'fee' => 120.0,
            'eta' => '1-2 days',
        ],
    ];

    public const DEFAULT_SHIPPING = 'standard';

    /**
     * Payment methods checkout can actually complete. There is no payment
     * gateway, so only cash on delivery; listing a method here enables it
     * in checkout validation, so add one only once it really works.
     *
     * @var array<string, array{name: string, description: string}>
     */
    public const PAYMENT_METHODS = [
        'cod' => [
            'name' => 'Cash on Delivery',
            'description' => 'Pay the courier in cash when your parcel arrives.',
        ],
    ];

    public static function shippingFee(?string $method): float
    {
        return (self::SHIPPING[$method] ?? self::SHIPPING[self::DEFAULT_SHIPPING])['fee'];
    }

    /**
     * @return list<string>
     */
    public static function paymentMethodIds(): array
    {
        return array_keys(self::PAYMENT_METHODS);
    }

    /**
     * @return array{shipping: list<array{id: string, name: string, shortName: string, fee: float, eta: string}>, shippingChargedPer: string, payment: list<array{id: string, name: string, description: string}>}
     */
    public static function toArray(): array
    {
        return [
            'shipping' => collect(self::SHIPPING)
                ->map(fn (array $option, string $id) => [
                    'id' => $id,
                    'name' => $option['name'],
                    'shortName' => $option['short_name'],
                    'fee' => $option['fee'],
                    'eta' => $option['eta'],
                ])
                ->values()
                ->all(),
            'shippingChargedPer' => 'seller_order',
            'payment' => collect(self::PAYMENT_METHODS)
                ->map(fn (array $method, string $id) => [
                    'id' => $id,
                    'name' => $method['name'],
                    'description' => $method['description'],
                ])
                ->values()
                ->all(),
        ];
    }
}
