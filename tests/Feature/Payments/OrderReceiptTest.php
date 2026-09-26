<?php

use App\Models\LedgerEntry;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function receiptOrder(string $buyerId, string $sellerId, string $status = 'Delivered', int $daysAgo = 0): Order
{
    $id = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $id,
        'order_number' => 'SN-'.Str::upper(Str::random(6)),
        'seller_id' => $sellerId,
        'buyer_profile_id' => $buyerId,
        'recipient_name' => 'Buyer',
        'status' => $status,
        'subtotal' => 150, 'shipping_fee' => 16, 'tax' => 0, 'discount' => 0, 'total' => 166,
        'placed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('parcel_assignments')->insert([
        'id' => (string) Str::uuid(),
        'order_id' => $id,
        'logistics_company_id' => (string) Str::uuid(),
        'status' => 'delivered',
        'received_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('order_status_history')->insert([
        'id' => (string) Str::uuid(),
        'order_id' => $id,
        'status' => $status,
        'created_at' => now()->subDays($daysAgo),
    ]);

    return Order::find($id);
}

it('lets the buyer confirm a delivered order, releasing escrow once', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $order = receiptOrder($buyer->id, $seller->id);
    actingAsBuyer($buyer);

    $this->postJson("/api/buyer/orders/{$order->order_number}/receive")
        ->assertOk()
        ->assertJsonPath('data.escrow_status', 'released');
    $this->postJson("/api/buyer/orders/{$order->order_number}/receive")->assertOk();

    expect(LedgerEntry::balance('seller', $seller->id))->toBe(14250)
        ->and(LedgerEntry::balance('platform'))->toBe(830)
        ->and($order->fresh()->received_via)->toBe('buyer');
});

it('does not allow confirming before the courier delivers', function () {
    $buyer = makeBuyer();
    $order = receiptOrder($buyer->id, makeSeller()->id, 'In Transit');
    actingAsBuyer($buyer);

    $this->postJson("/api/buyer/orders/{$order->order_number}/receive")->assertStatus(422);
    expect(LedgerEntry::count())->toBe(0);
});

it('auto-confirms orders delivered more than 7 days ago', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $stale = receiptOrder($buyer->id, $seller->id, 'Delivered', 8);
    $recent = receiptOrder($buyer->id, $seller->id, 'Delivered', 2);

    $this->artisan('orders:auto-confirm-receipt')->assertSuccessful();

    expect($stale->fresh()->received_via)->toBe('auto')
        ->and($recent->fresh()->received_at)->toBeNull();
});

it('shows the seller their cash flow', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $order = receiptOrder($buyer->id, $seller->id);
    app(\App\Services\Payments\OrderReceiptService::class)->confirm($order);
    actingAsSeller($seller);

    $this->getJson('/api/seller/reports/cash-flow')
        ->assertOk()
        ->assertJsonPath('released', '142.50')
        ->assertJsonPath('net', '142.50')
        ->assertJsonPath('entries.0.order_number', $order->order_number);
});
