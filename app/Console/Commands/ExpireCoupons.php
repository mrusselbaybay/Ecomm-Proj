<?php

namespace App\Console\Commands;

use App\Services\Coupons\CouponService;
use Illuminate\Console\Command;

class ExpireCoupons extends Command
{
    protected $signature = 'coupons:expire';

    protected $description = 'Mark past-expiry or used-up coupons expired, and the wallet copies of them (idempotent).';

    public function handle(CouponService $coupons): int
    {
        $result = $coupons->expireStale();

        $this->info("Expired {$result['coupons']} coupon(s) and {$result['wallet']} wallet entr(ies).");

        return self::SUCCESS;
    }
}
