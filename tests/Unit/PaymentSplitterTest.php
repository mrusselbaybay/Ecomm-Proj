<?php

use App\Services\Payments\Money;
use App\Services\Payments\PaymentSplitter;

function splitMap(array $shares): array
{
    return collect($shares)->mapWithKeys(fn ($s) => [$s['account'].':'.$s['party_id'] => $s['cents']])->all();
}

it('splits the spec example exactly (goods 150, shipping 16)', function () {
    $shares = PaymentSplitter::split(15000, 1600, 'seller', ['origin', 'lastmile']);

    expect(splitMap($shares))->toBe([
        'seller:seller' => 14250,
        'origin_logistics:origin' => 912,
        'last_mile_logistics:lastmile' => 608,
        'platform:' => 830,
    ])->and(array_sum(array_column($shares, 'cents')))->toBe(16600);
});

it('gives one company the full 95% of shipping', function () {
    expect(splitMap(PaymentSplitter::split(15000, 1600, 's', ['solo', 'solo'])))
        ->toMatchArray(['origin_logistics:solo' => 1520]);
});

it('uses the rate card for three or more companies', function () {
    $shares = splitMap(PaymentSplitter::split(10000, 3000, 's', ['a', 'b', 'c'], ['a' => 1000, 'b' => 1200, 'c' => 800]));

    expect($shares)->toMatchArray([
        'origin_logistics:a' => 950,
        'leg_logistics:b' => 1140,
        'last_mile_logistics:c' => 760,
        'platform:' => 650,
    ])->and(array_sum($shares))->toBe(13000);
});

it('rejects a rate card that does not match the shipping fee', function () {
    PaymentSplitter::split(10000, 3000, 's', ['a', 'b', 'c'], ['a' => 1000, 'b' => 1000, 'c' => 800]);
})->throws(InvalidArgumentException::class);

it('always sums exactly for awkward centavo amounts', function (int $goods, int $shipping) {
    $shares = PaymentSplitter::split($goods, $shipping, 's', ['o', 'l']);

    expect(array_sum(array_column($shares, 'cents')))->toBe($goods + $shipping)
        ->and(min(array_column($shares, 'cents')))->toBeGreaterThanOrEqual(0);
})->with([[1, 1], [9999, 333], [12345, 6789], [0, 1], [99, 0]]);

it('parses pesos without floats and allocates exactly', function () {
    expect(Money::toCents('166.00'))->toBe(16600)
        ->and(Money::toCents('0.1'))->toBe(10)
        ->and(Money::toCents(19.99))->toBe(1999)
        ->and(Money::allocate(100, [1, 1, 1]))->toBe([34, 33, 33]);
});
