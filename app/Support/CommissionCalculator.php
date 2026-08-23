<?php

namespace App\Support;

class CommissionCalculator
{
    public const RATE = 0.10;

    public static function basis(float $subtotal, float $discount): float
    {
        return round(max($subtotal - $discount, 0), 2);
    }

    public static function commission(float $subtotal, float $discount): float
    {
        return round(self::basis($subtotal, $discount) * self::RATE, 2);
    }
}
