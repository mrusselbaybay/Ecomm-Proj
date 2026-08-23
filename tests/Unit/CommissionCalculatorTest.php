<?php

use App\Support\CommissionCalculator;

it('calculates ten percent of merchandise after discounts', function () {
    expect(CommissionCalculator::basis(1000, 100))->toBe(900.0)
        ->and(CommissionCalculator::commission(1000, 100))->toBe(90.0);
});

it('never calculates commission from a negative basis', function () {
    expect(CommissionCalculator::basis(100, 150))->toBe(0.0)
        ->and(CommissionCalculator::commission(100, 150))->toBe(0.0);
});

it('rounds currency values to two decimal places', function () {
    expect(CommissionCalculator::commission(99.99, 0))->toBe(10.0);
});
