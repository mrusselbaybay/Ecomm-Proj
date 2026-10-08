<?php

namespace App\Services\Payments;

use App\Models\SellerPayout;
use Carbon\CarbonInterface;

class SellerPayoutService
{
    private const ANNUAL_THRESHOLD = 500000.00;

    private const WITHHOLDING_RATE = 0.01;

    public function record(string $sellerId, int $grossCents, ?CarbonInterface $releasedAt = null): SellerPayout
    {
        $releasedAt ??= now();
        $grossAmount = round($grossCents / 100, 2);
        $yearlyGross = (float) SellerPayout::query()
            ->where('seller_id', $sellerId)
            ->whereYear('created_at', $releasedAt->year)
            ->sum('gross_amount');
        $withholdingTax = $yearlyGross + $grossAmount > self::ANNUAL_THRESHOLD
            ? round($grossAmount * self::WITHHOLDING_RATE, 2)
            : 0.00;

        return SellerPayout::query()->create([
            'seller_id' => $sellerId,
            'gross_amount' => $grossAmount,
            'withholding_tax' => $withholdingTax,
            'net_amount' => round($grossAmount - $withholdingTax, 2),
            'period' => $releasedAt->format('Y-m'),
            'created_at' => $releasedAt,
        ]);
    }
}
