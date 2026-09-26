<?php

use App\Models\EscrowTransaction;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Services\Payments\MockPaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const PAY_SELLER = '70000000-0000-0000-0000-000000000007';
const PAY_BUYER = '80000000-0000-0000-0000-000000000008';
const PAY_ORIGIN = '90000000-0000-0000-0000-000000000001';
const PAY_LASTMILE = '90000000-0000-0000-0000-000000000002';

function payOrder(string $status = 'Delivered', array $companies = [PAY_ORIGIN, PAY_LASTMILE]): string
{
    $id = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $id,
        'order_number' => 'SN-'.Str::random(6),
        'seller_id' => PAY_SELLER,
        'buyer_profile_id' => PAY_BUYER,
        'recipient_name' => 'Buyer',
        'status' => $status,
        'subtotal' => 150, 'shipping_fee' => 16, 'tax' => 0, 'discount' => 0, 'total' => 166,
        'placed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($companies as $i => $company) {
        DB::table('parcel_assignments')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $id,
            'logistics_company_id' => $company,
            'status' => 'delivered',
            'received_at' => now(),
            'created_at' => now()->addMinutes($i),
            'updated_at' => now(),
        ]);
    }

    return $id;
}

function ledgerBalanced(): bool
{
    return (int) LedgerEntry::sum('debit_cents') === (int) LedgerEntry::sum('credit_cents');
}

beforeEach(fn () => $this->pay = new MockPaymentService);

it('funds escrow and is idempotent on retry', function () {
    $order = payOrder();

    $first = $this->pay->chargeBuyer($order, '166.00');
    $retry = $this->pay->chargeBuyer($order, '166.00');

    expect($retry->id)->toBe($first->id)
        ->and(LedgerEntry::count())->toBe(2)
        ->and(LedgerEntry::balance('escrow'))->toBe(16600)
        ->and(Order::find($order)->escrow_status)->toBe('funded');
});

it('rejects a charge that does not match the order total', function () {
    $this->pay->chargeBuyer(payOrder(), '100.00');
})->throws(InvalidArgumentException::class);

it('releases escrow into the four parties exactly once', function () {
    $order = payOrder();
    $this->pay->chargeBuyer($order, 166);

    $tx = $this->pay->releaseEscrow($order);
    $this->pay->releaseEscrow($order); // retry

    expect(EscrowTransaction::where('type', 'release')->count())->toBe(1)
        ->and($tx->entries()->where('credit_cents', '>', 0)->count())->toBe(4)
        ->and(LedgerEntry::balance('seller', PAY_SELLER))->toBe(14250)
        ->and(LedgerEntry::balance('origin_logistics', PAY_ORIGIN))->toBe(912)
        ->and(LedgerEntry::balance('last_mile_logistics', PAY_LASTMILE))->toBe(608)
        ->and(LedgerEntry::balance('platform'))->toBe(830)
        ->and(LedgerEntry::balance('escrow'))->toBe(0)
        ->and(ledgerBalanced())->toBeTrue();
});

it('refuses to release before the order is delivered', function () {
    $order = payOrder('In Transit');
    $this->pay->chargeBuyer($order, 166);
    $this->pay->releaseEscrow($order);
})->throws(LogicException::class);

it('refunds from escrow before release and releases only the remainder', function () {
    $order = payOrder();
    $this->pay->chargeBuyer($order, 166);
    $this->pay->refundBuyer($order, '66.40', 'rr-1'); // 40%

    $this->pay->releaseEscrow($order);

    expect(LedgerEntry::balance('seller', PAY_SELLER))->toBe(8550)
        ->and(LedgerEntry::balance('origin_logistics', PAY_ORIGIN))->toBe(547)
        ->and(LedgerEntry::balance('last_mile_logistics', PAY_LASTMILE))->toBe(365)
        ->and(LedgerEntry::balance('platform'))->toBe(498)
        ->and(LedgerEntry::balance('escrow'))->toBe(0)
        ->and(ledgerBalanced())->toBeTrue();
});

it('claws back all four splits proportionally after release', function () {
    $order = payOrder();
    $this->pay->chargeBuyer($order, 166);
    $this->pay->releaseEscrow($order);

    $this->pay->refundBuyer($order, '83.00', 'rr-1');
    $this->pay->refundBuyer($order, '83.00', 'rr-1'); // retry: no-op
    $this->pay->refundBuyer($order, '83.00', 'rr-2');

    expect(LedgerEntry::balance('seller', PAY_SELLER))->toBe(0)
        ->and(LedgerEntry::balance('origin_logistics', PAY_ORIGIN))->toBe(0)
        ->and(LedgerEntry::balance('last_mile_logistics', PAY_LASTMILE))->toBe(0)
        ->and(LedgerEntry::balance('platform'))->toBe(0)
        ->and(LedgerEntry::balance('buyer', PAY_BUYER))->toBe(0)
        ->and(Order::find($order)->escrow_status)->toBe('refunded')
        ->and(Order::find($order)->payment_status)->toBe('Refunded')
        ->and(ledgerBalanced())->toBeTrue();
});

it('keeps exact totals across many odd partial refunds', function () {
    $order = payOrder();
    $this->pay->chargeBuyer($order, 166);
    $this->pay->releaseEscrow($order);

    foreach (['0.01', '33.33', '0.07', '50.00', '82.59'] as $i => $amount) {
        $this->pay->refundBuyer($order, $amount, "odd-{$i}");
    }

    expect(LedgerEntry::whereNot('account', 'buyer')->selectRaw('SUM(credit_cents) - SUM(debit_cents) AS b')->value('b'))->toEqual(0)
        ->and(ledgerBalanced())->toBeTrue();
});

it('rejects refunds beyond the order total and reused keys with different amounts', function () {
    $order = payOrder();
    $this->pay->chargeBuyer($order, 166);
    $this->pay->refundBuyer($order, '100.00', 'k1');

    expect(fn () => $this->pay->refundBuyer($order, '70.00', 'k2'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->pay->refundBuyer($order, '10.00', 'k1'))->toThrow(LogicException::class);
});

it('prevents ledger entries from being edited', function () {
    $order = payOrder();
    $this->pay->chargeBuyer($order, 166);

    LedgerEntry::first()->update(['debit_cents' => 1]);
})->throws(LogicException::class);
