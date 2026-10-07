<?php

namespace Database\Seeders;

use App\Models\CourierDetail;
use App\Models\CourierEarning;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ParcelAssignment;
use App\Models\Profile;
use App\Services\CourierEarningService;
use App\Services\Payments\MockPaymentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoCourierEarningsSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \LogicException('Demo earnings cannot be seeded in production.');
        }
        $buyer = Profile::where('role', 'buyer')->first();
        $seller = Profile::where('role', 'seller')->first();
        if (! $buyer || ! $seller) {
            $this->command?->warn('Create demo buyer and seller accounts first.');

            return;
        }
        $couriers = CourierDetail::with(['profile', 'logisticsCompany'])->whereNotNull('logistics_company_id')
            ->whereHas('profile', fn ($q) => $q->where(fn ($q) => $q->where('email', 'like', 'dummy.courier.%')->orWhere('email', 'like', '%demo%')))->limit(10)->get();
        foreach ($couriers as $courier) {
            $company = $courier->logisticsCompany;
            if (! $company) {
                continue;
            }
            DB::transaction(function () use ($courier, $company, $buyer, $seller) {
                $service = app(CourierEarningService::class);
                $payments = app(MockPaymentService::class);
                for ($day = 0; $day < 7; $day++) {
                    $number = 'DEMO-EARN-'.substr(str_replace('-', '', $courier->profile_id), 0, 12).'-'.$day;
                    if (Order::where('order_number', $number)->exists()) {
                        continue;
                    }
                    $order = Order::create(['order_number' => $number, 'seller_id' => $seller->id, 'buyer_profile_id' => $buyer->id,
                        'recipient_name' => $buyer->first_name.' '.$buyer->last_name, 'status' => 'Delivered', 'subtotal' => '100.00',
                        'shipping_fee' => '60.00', 'total' => '160.00', 'payment_method' => $day === 0 ? 'COD' : 'GCash', 'placed_at' => now()->subDays($day)]);
                    OrderItem::create(['order_id' => $order->id, 'product_name' => 'Demo parcel', 'quantity' => 1, 'unit_price' => '100.00', 'subtotal' => '100.00']);
                    $leg = ParcelAssignment::create(['order_id' => $order->id, 'logistics_company_id' => $company->id,
                        'rider_profile_id' => $courier->profile_id, 'picked_up_by' => $courier->profile_id, 'status' => 'handed_off',
                        'received_at' => now()->subDays($day), 'delivered_at' => now()->subDays($day), 'pickup_photo_path' => 'demo/pickup.jpg', 'delivery_photo_path' => 'demo/delivery.jpg']);
                    $service->record($leg, $courier->profile_id, 'pickup', 'demo/pickup.jpg');
                    $service->record($leg, $courier->profile_id, 'delivery', 'demo/delivery.jpg');
                    $payments->chargeBuyer($order->id, $order->total);
                    if ($day !== 1) {
                        $payments->releaseEscrow($order->id);
                    }
                    if ($day === 0) {
                        $service->collectCod($leg, $courier->profile_id);
                    }
                }
                if (! CourierEarning::where('courier_id', $courier->profile_id)->where('reason', 'Demo weekly incentive')->exists()) {
                    $service->adjustment($company, $courier->profile_id, 'bonus', 5000, 'Demo weekly incentive', $company->owner_profile_id);
                    $service->adjustment($company, $courier->profile_id, 'tip', 2000, 'Demo customer tip', $company->owner_profile_id);
                    $service->adjustment($company, $courier->profile_id, 'deduction', 1000, 'Demo equipment replacement', $company->owner_profile_id);
                    $service->remit($company, $courier->profile_id, 10000, $company->owner_profile_id, 'DEMO-CASH-HANDOVER');
                    $payout = $service->statement($company, $courier->profile_id, $company->owner_profile_id, now('Asia/Manila')->subDays(6)->toDateString(), now('Asia/Manila')->toDateString());
                    $service->payoutAction($payout, 'approve', $company->owner_profile_id);
                    $service->payoutAction($payout, 'pay', $company->owner_profile_id, 'DEMO-BANK-'.substr($courier->profile_id, 0, 8));
                    $service->adjustment($company, $courier->profile_id, 'bonus', 1500, 'Demo available incentive', $company->owner_profile_id);
                }
            });
        }
        $this->command?->info('Demo courier earnings, COD and payouts seeded.');
    }
}
