<?php

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderReturnRequest;
use App\Services\Payments\OrderReceiptService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const RR_ORIGIN = '91000000-0000-0000-0000-000000000001';

/** Delivered + received (escrow released) order with one ₱150 item and a pending request for it. */
function refundScenario($buyer, $seller, array $request = []): OrderReturnRequest
{
    $orderId = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $orderId, 'order_number' => 'SN-'.Str::upper(Str::random(6)),
        'seller_id' => $seller->id, 'buyer_profile_id' => $buyer->id, 'recipient_name' => 'Buyer',
        'status' => 'Delivered',
        'subtotal' => 150, 'shipping_fee' => 16, 'tax' => 0, 'discount' => 0, 'total' => 166,
        'placed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('parcel_assignments')->insert([
        'id' => (string) Str::uuid(), 'order_id' => $orderId, 'logistics_company_id' => RR_ORIGIN,
        'status' => 'delivered', 'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $itemId = (string) Str::uuid();
    DB::table('order_items')->insert([
        'id' => $itemId, 'order_id' => $orderId, 'product_name' => 'Mug', 'quantity' => 1,
        'unit_price' => 150, 'subtotal' => 150, 'created_at' => now(), 'updated_at' => now(),
    ]);

    app(OrderReceiptService::class)->confirm(Order::find($orderId));

    return OrderReturnRequest::create(array_merge([
        'order_id' => $orderId, 'order_item_id' => $itemId,
        'buyer_profile_id' => $buyer->id, 'seller_id' => $seller->id,
        'request_type' => 'refund_only', 'reason' => 'damaged', 'details' => 'Cracked on arrival.',
        'quantity' => 1, 'estimated_amount' => 150, 'evidence' => ['x'], 'status' => 'pending',
    ], $request));
}

it('approving claws back all four splits proportionally', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $request = refundScenario($buyer, $seller);
    actingAsSeller($seller);

    $this->postJson("/api/seller/refund-requests/{$request->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.refundedAmount', 150);

    // ₱150 of ₱166 refunded → each party keeps 16/166 of its share.
    expect(LedgerEntry::balance('buyer', $buyer->id))->toBe(-1600)
        ->and(LedgerEntry::balance('seller', $seller->id)
            + LedgerEntry::balance('origin_logistics', RR_ORIGIN)
            + LedgerEntry::balance('platform'))->toBe(1600)
        ->and(LedgerEntry::balance('seller', $seller->id))->toBe(1373)
        ->and(LedgerEntry::balance('platform'))->toBe(80)
        ->and((int) LedgerEntry::sum('debit_cents'))->toBe((int) LedgerEntry::sum('credit_cents'));
});

it('rejects with a note and moves no money', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    $request = refundScenario($buyer, $seller);
    actingAsSeller($seller);

    $this->postJson("/api/seller/refund-requests/{$request->id}/reject", ['note' => ''])->assertStatus(422);
    $this->postJson("/api/seller/refund-requests/{$request->id}/reject", ['note' => 'Item was used.'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->postJson("/api/seller/refund-requests/{$request->id}/approve")->assertStatus(422);

    expect(LedgerEntry::balance('seller', $seller->id))->toBe(14250);
});

it('approves all pending requests and lists them with counts', function () {
    $buyer = makeBuyer();
    $seller = makeSeller();
    refundScenario($buyer, $seller);
    refundScenario($buyer, $seller, ['reason' => 'other', 'other_reason' => 'Changed my mind']);
    actingAsSeller($seller);

    $this->getJson('/api/seller/refund-requests')
        ->assertOk()
        ->assertJsonPath('data.counts.pending', 2)
        ->assertJsonFragment(['reasonLabel' => 'Changed my mind']);

    $this->postJson('/api/seller/refund-requests/approve-all')
        ->assertOk()->assertJsonPath('approved', 2)->assertJsonPath('failed', []);

    expect(OrderReturnRequest::where('status', 'approved')->count())->toBe(2);
});

it('hides other sellers requests', function () {
    $request = refundScenario(makeBuyer(), makeSeller());
    actingAsSeller(makeSeller());

    $this->postJson("/api/seller/refund-requests/{$request->id}/approve")->assertNotFound();
});

it('requires a typed reason when the buyer picks other', function () {
    $buyer = makeBuyer();
    [$order, $item] = makeOrder($buyer, makeSeller(), ['status' => 'Delivered', 'received_at' => now()]);
    actingAsBuyer($buyer);

    $payload = [
        'order_item_id' => $item->id, 'request_type' => 'refund_only', 'reason' => 'other',
        'details' => 'Not what I expected at all.', 'quantity' => 1, 'evidence' => ['https://x.test/a.jpg'],
    ];

    $this->postJson('/api/buyer/returns', $payload)->assertStatus(422)->assertJsonValidationErrors('other_reason');
    $this->postJson('/api/buyer/returns', $payload + ['other_reason' => 'Changed my mind'])->assertCreated();

    expect(OrderReturnRequest::first()->other_reason)->toBe('Changed my mind');
});
