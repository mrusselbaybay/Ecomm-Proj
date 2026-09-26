<?php

namespace App\Services\Payments;

use InvalidArgumentException;

/**
 * Pure split of an order total:
 *   Platform  = (Goods + Shipping) × 5%   (off the top)
 *   Logistics = Shipping × 95%            (1 co: all; 2 cos: 60/40; 3+: rate card)
 *   Seller    = remainder (= Goods × 95%, absorbs rounding so the sum is exact)
 */
final class PaymentSplitter
{
    public const PLATFORM_BPS = 500;
    public const ORIGIN_BPS = 6000;

    public const ACCOUNT_SELLER = 'seller';
    public const ACCOUNT_ORIGIN = 'origin_logistics';
    public const ACCOUNT_LAST_MILE = 'last_mile_logistics';
    public const ACCOUNT_LEG = 'leg_logistics';
    public const ACCOUNT_PLATFORM = 'platform';

    /**
     * @param  list<string>  $companyIds  logistics companies in leg order (origin first)
     * @param  array<string, int>  $rateCard  company id => leg price in centavos (3+ companies)
     * @return list<array{account: string, party_id: ?string, cents: int}>
     */
    public static function split(int $goodsCents, int $shippingCents, string $sellerId, array $companyIds, array $rateCard = []): array
    {
        if ($goodsCents < 0 || $shippingCents < 0) {
            throw new InvalidArgumentException('Amounts cannot be negative.');
        }

        $companyIds = array_values(array_unique($companyIds));
        $platform = Money::percent($goodsCents + $shippingCents, self::PLATFORM_BPS);
        $pool = $shippingCents - Money::percent($shippingCents, self::PLATFORM_BPS);

        $logistics = match (true) {
            $shippingCents === 0 => [],
            count($companyIds) === 0 => throw new InvalidArgumentException('Shipping was charged but no logistics company handled the order.'),
            count($companyIds) === 1 => [[self::ACCOUNT_ORIGIN, $companyIds[0], $pool]],
            count($companyIds) === 2 => self::twoLegs($companyIds, $pool),
            default => self::rateCardLegs($companyIds, $pool, $shippingCents, $rateCard),
        };

        $shares = [[self::ACCOUNT_SELLER, $sellerId, $goodsCents + $shippingCents - $platform - $pool], ...$logistics];
        $shares[] = [self::ACCOUNT_PLATFORM, null, $platform];

        return array_map(fn ($s) => ['account' => $s[0], 'party_id' => $s[1], 'cents' => $s[2]], $shares);
    }

    private static function twoLegs(array $ids, int $pool): array
    {
        $origin = Money::percent($pool, self::ORIGIN_BPS);

        return [
            [self::ACCOUNT_ORIGIN, $ids[0], $origin],
            [self::ACCOUNT_LAST_MILE, $ids[1], $pool - $origin],
        ];
    }

    private static function rateCardLegs(array $ids, int $pool, int $shippingCents, array $rateCard): array
    {
        $prices = [];
        foreach ($ids as $id) {
            if (! isset($rateCard[$id])) {
                throw new InvalidArgumentException("Rate card is missing a leg price for company [{$id}].");
            }
            $prices[$id] = (int) $rateCard[$id];
        }

        if (array_sum($prices) !== $shippingCents) {
            throw new InvalidArgumentException('Rate card leg prices must add up to the shipping fee.');
        }

        $last = count($ids) - 1;
        $amounts = Money::allocate($pool, $prices);

        return array_map(fn ($id, $i) => [
            match ($i) { 0 => self::ACCOUNT_ORIGIN, $last => self::ACCOUNT_LAST_MILE, default => self::ACCOUNT_LEG },
            $id,
            $amounts[$id],
        ], $ids, array_keys($ids));
    }
}
