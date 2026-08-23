<?php

use App\Support\CommissionCalculator;

it('reconciles commission and seller proceeds to net merchandise', function () {
    $net = CommissionCalculator::basis(1250, 50);
    $commission = CommissionCalculator::commission(1250, 50);
    $sellerProceeds = round($net - $commission, 2);

    expect($net)->toBe(1200.0)
        ->and($commission)->toBe(120.0)
        ->and($sellerProceeds)->toBe(1080.0)
        ->and($commission + $sellerProceeds)->toBe($net);
});
