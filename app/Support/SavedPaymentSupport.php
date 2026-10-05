<?php

namespace App\Support;

/**
 * Whether buyers can save a card or link an e-wallet, and if not, why.
 *
 * Saving needs a payment provider that vaults the instrument (a hosted or
 * tokenised setup flow that returns a provider reference) — BuyTheWay only
 * ever stores that reference plus display details (brand, last 4, expiry,
 * a masked wallet id). Checkout's own options (CheckoutOptions) are a
 * separate thing: a method being payable at checkout says nothing about
 * whether it can be saved.
 *
 * No provider driver is implemented yet, so this reports unsupported even
 * if PAYMENT_VAULT_PROVIDER is set; a provider becomes "enabled" only once
 * its integration registers in $drivers and its keys are configured.
 */
class SavedPaymentSupport
{
    /**
     * Provider integrations this app implements for saving methods (an
     * integration's service provider registers its name here).
     *
     * @var list<string>
     */
    public static array $drivers = [];

    public static function provider(): ?string
    {
        $provider = config('services.payment_vault.provider');

        return in_array($provider, self::$drivers, true) && filled(config('services.payment_vault.secret_key'))
            ? $provider
            : null;
    }

    public static function enabled(): bool
    {
        return self::provider() !== null;
    }

    /**
     * What's missing before cards can be saved or wallets linked.
     *
     * @return list<string>
     */
    public static function missing(): array
    {
        if (self::enabled()) {
            return [];
        }

        $configured = config('services.payment_vault.provider');

        return array_values(array_filter([
            self::$drivers === []
                ? 'No payment provider integration for saving cards or linking e-wallets is installed (for example PayMongo or Xendit card vaulting and GCash / Maya account linking).'
                : null,
            $configured && ! in_array($configured, self::$drivers, true)
                ? "PAYMENT_VAULT_PROVIDER is set to \"{$configured}\", which has no integration here."
                : null,
            blank(config('services.payment_vault.secret_key'))
                ? 'No provider API keys are configured (PAYMENT_VAULT_SECRET_KEY, kept on the server).'
                : null,
        ]));
    }

    /**
     * @return array{enabled: bool, provider: string|null, missing: list<string>}
     */
    public static function toArray(): array
    {
        return [
            'enabled' => self::enabled(),
            'provider' => self::provider(),
            'missing' => self::missing(),
        ];
    }
}
