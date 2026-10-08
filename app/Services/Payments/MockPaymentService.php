<?php

namespace App\Services\Payments;

use App\Models\EscrowTransaction;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\ParcelAssignment;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Simulated escrow — no real money, no external calls. Every operation is
 * one DB transaction that writes a balanced set of append-only ledger
 * entries, keyed by a unique idempotency key so retries are no-ops.
 *
 * Accounts (balance = credits − debits):
 *   buyer  — external source of funds; goes negative when the buyer pays
 *   escrow — funds held for an order
 *   seller / *_logistics / platform — party wallets; negative = clawback owed
 */
class MockPaymentService
{
    public const ESCROW_UNFUNDED = 'unfunded';

    public const ESCROW_FUNDED = 'funded';

    public const ESCROW_RELEASED = 'released';

    public const ESCROW_REFUNDED = 'refunded';

    public function chargeBuyer(string $orderId, string|int|float $amount): EscrowTransaction
    {
        $cents = Money::toCents($amount);

        return $this->run("charge:{$orderId}", $orderId, EscrowTransaction::TYPE_CHARGE, $cents, function (Order $order) use ($cents) {
            if ($order->escrow_status !== self::ESCROW_UNFUNDED) {
                throw new LogicException('Escrow is already funded for this order.');
            }
            if ($cents !== Money::toCents($order->total)) {
                throw new InvalidArgumentException('Charge amount must equal the order total.');
            }

            $order->forceFill(['escrow_status' => self::ESCROW_FUNDED, 'payment_status' => 'Paid'])->save();

            return [[
                ['account' => 'buyer', 'party_id' => $order->buyer_profile_id, 'debit' => $cents],
                ['account' => 'escrow', 'party_id' => null, 'credit' => $cents],
            ], null];
        });
    }

    /**
     * @param  array<string, string|int|float>  $rateCard  company id => leg price (pesos); only for 3+ companies
     */
    public function releaseEscrow(string $orderId, array $rateCard = []): EscrowTransaction
    {
        return $this->run("release:{$orderId}", $orderId, EscrowTransaction::TYPE_RELEASE, null, function (Order $order) use ($rateCard) {
            if ($order->escrow_status !== self::ESCROW_FUNDED) {
                throw new LogicException("Escrow cannot be released from status [{$order->escrow_status}].");
            }
            if ($order->status !== 'Delivered') {
                throw new LogicException('Escrow releases only after the order is confirmed delivered.');
            }

            $total = Money::toCents($order->total);
            $shipping = Money::toCents($order->shipping_fee);
            $discount = Money::toCents($order->discount ?? 0);
            $shares = PaymentSplitter::split(
                $total - $shipping + $discount,
                $shipping,
                $order->seller_id,
                $this->logisticsChain($order->id),
                array_map([Money::class, 'toCents'], $rateCard),
                $discount,
            );

            // Pre-release partial refunds already left escrow; each party
            // gets its full share minus its proportional part of them.
            $alreadyRefunded = Money::allocate($this->refundedCents($order->id), array_column($shares, 'cents'));

            $lines = [];
            $released = 0;
            foreach ($shares as $i => $share) {
                $payout = $share['cents'] - $alreadyRefunded[$i];
                $released += $payout;
                $lines[] = ['account' => $share['account'], 'party_id' => $share['party_id'], 'credit' => $payout];
            }
            array_unshift($lines, ['account' => 'escrow', 'party_id' => null, 'debit' => $released]);

            $order->forceFill(['escrow_status' => self::ESCROW_RELEASED])->save();

            return [$lines, ['shares' => $shares], $released];
        });
    }

    /** Proportional refund; after release it claws back from every party. */
    public function refundBuyer(string $orderId, string|int|float $amount, string $idempotencyKey): EscrowTransaction
    {
        $cents = Money::toCents($amount);

        return $this->run("refund:{$idempotencyKey}", $orderId, EscrowTransaction::TYPE_REFUND, $cents, function (Order $order) use ($cents) {
            if (! in_array($order->escrow_status, [self::ESCROW_FUNDED, self::ESCROW_RELEASED], true)) {
                throw new LogicException("Cannot refund from escrow status [{$order->escrow_status}].");
            }
            if ($cents <= 0) {
                throw new InvalidArgumentException('Refund amount must be positive.');
            }

            $total = Money::toCents($order->total);
            $before = $this->refundedCents($order->id);
            $after = $before + $cents;
            if ($after > $total) {
                throw new InvalidArgumentException('Refund exceeds the amount still refundable.');
            }

            $lines = [['account' => 'buyer', 'party_id' => $order->buyer_profile_id, 'credit' => $cents]];

            if ($order->escrow_status === self::ESCROW_FUNDED) {
                $lines[] = ['account' => 'escrow', 'party_id' => null, 'debit' => $cents];
            } else {
                // Allocate cumulatively so repeated partial refunds never drift
                // from the exact full split.
                $shares = EscrowTransaction::where('order_id', $order->id)
                    ->where('type', EscrowTransaction::TYPE_RELEASE)->first()->meta['shares'];
                $weights = array_column($shares, 'cents');
                $prev = Money::allocate($before, $weights);
                $next = Money::allocate($after, $weights);

                foreach ($shares as $i => $share) {
                    // Largest-remainder can shift a centavo between parties
                    // across steps, so a delta may be negative.
                    $clawback = $next[$i] - $prev[$i];
                    if ($clawback !== 0) {
                        $lines[] = ['account' => $share['account'], 'party_id' => $share['party_id']]
                            + ($clawback > 0 ? ['debit' => $clawback] : ['credit' => -$clawback]);
                    }
                }
            }

            $fullyRefunded = $after === $total;
            $order->forceFill([
                'escrow_status' => $fullyRefunded ? self::ESCROW_REFUNDED : $order->escrow_status,
                'payment_status' => $fullyRefunded ? 'Refunded' : $order->payment_status,
            ])->save();

            return [$lines, ['refunded_total_cents' => $after]];
        });
    }

    /**
     * Settles a completed Return + Refund once the item is back with the
     * seller. Unlike a refund-only (refundBuyer, proportional clawback from
     * all four shares), the logistics companies and the platform keep what
     * they earned on the forward delivery:
     *
     *   buyer     + item amount (+ original shipping when the whole order came back)
     *   seller    − item goods share (95%) − refunded shipping − return shipping
     *   platform  − 5% goods commission on the item, + 5% of return shipping
     *   logistics + 95% of return shipping, split over the return chain (100% / 60-40)
     *
     * Escrow still held (buyer never confirmed receipt) is released first so
     * every party's forward share exists before the return is netted off it.
     *
     * A couponed item refunds what the buyer actually paid ($itemCents); the
     * platform's commission is clawed back on the pre-discount price, so the
     * seller returns exactly the reduced share it received and keeps the
     * coupon cost it already gave.
     *
     * @param  list<string>  $returnChain  companies on the return legs, pickup-from-buyer first
     */
    public function settleReturn(string $orderId, string $returnRequestId, int $itemCents, int $shippingRefundCents, int $returnShippingCents, array $returnChain, int $couponDiscountCents = 0): EscrowTransaction
    {
        $order = Order::findOrFail($orderId);
        if ($order->escrow_status === self::ESCROW_UNFUNDED) {
            $this->chargeBuyer($orderId, $order->total);
            $order->refresh();
        }
        if ($order->escrow_status === self::ESCROW_FUNDED) {
            $this->releaseEscrow($orderId);
        }

        $buyerCents = $itemCents + $shippingRefundCents;

        return $this->run("return:{$returnRequestId}", $orderId, EscrowTransaction::TYPE_RETURN, $buyerCents, function (Order $order) use ($itemCents, $shippingRefundCents, $returnShippingCents, $returnChain, $buyerCents, $couponDiscountCents) {
            if ($order->escrow_status !== self::ESCROW_RELEASED) {
                throw new LogicException("Cannot settle a return from escrow status [{$order->escrow_status}].");
            }
            if ($itemCents <= 0) {
                throw new InvalidArgumentException('Return amount must be positive.');
            }

            $total = Money::toCents($order->total);
            $after = $this->refundedCents($order->id, true) + $buyerCents;
            if ($after > $total) {
                throw new InvalidArgumentException('Refund exceeds the amount still refundable.');
            }

            $goodsCommission = Money::percent($itemCents + $couponDiscountCents, PaymentSplitter::PLATFORM_BPS);
            $returnShares = $returnShippingCents > 0
                ? PaymentSplitter::split(0, $returnShippingCents, $order->seller_id, $returnChain)
                : [];

            $lines = [
                ['account' => 'buyer', 'party_id' => $order->buyer_profile_id, 'credit' => $buyerCents],
                ['account' => PaymentSplitter::ACCOUNT_SELLER, 'party_id' => $order->seller_id,
                    'debit' => $itemCents - $goodsCommission + $shippingRefundCents + $returnShippingCents],
                ['account' => PaymentSplitter::ACCOUNT_PLATFORM, 'party_id' => null, 'debit' => $goodsCommission],
            ];
            foreach ($returnShares as $share) {
                if ($share['cents'] > 0) {
                    $lines[] = ['account' => $share['account'], 'party_id' => $share['party_id'], 'credit' => $share['cents']];
                }
            }

            $fullyRefunded = $after === $total;
            $order->forceFill([
                'escrow_status' => $fullyRefunded ? self::ESCROW_REFUNDED : $order->escrow_status,
                'payment_status' => $fullyRefunded ? 'Refunded' : $order->payment_status,
            ])->save();

            return [$lines, [
                'item_cents' => $itemCents,
                'shipping_refund_cents' => $shippingRefundCents,
                'return_shipping_cents' => $returnShippingCents,
                'coupon_discount_cents' => $couponDiscountCents,
                'return_shares' => $returnShares,
            ]];
        });
    }

    /**
     * Distinct logistics companies in the order's parcel chain, origin first.
     *
     * @return list<string>
     */
    public function logisticsChain(string $orderId): array
    {
        return ParcelAssignment::where('order_id', $orderId)
            ->whereNull('return_request_id')
            ->orderBy('created_at')
            ->pluck('logistics_company_id')
            ->unique()
            ->values()
            ->all();
    }

    public function refundedCents(string $orderId, bool $includeReturns = false): int
    {
        return (int) EscrowTransaction::where('order_id', $orderId)
            ->whereIn('type', $includeReturns
                ? [EscrowTransaction::TYPE_REFUND, EscrowTransaction::TYPE_RETURN]
                : [EscrowTransaction::TYPE_REFUND])
            ->sum('amount_cents');
    }

    /**
     * @param  callable(Order): array  $build  returns [lines, meta, amount?]
     */
    private function run(string $key, string $orderId, string $type, ?int $amount, callable $build): EscrowTransaction
    {
        return DB::transaction(function () use ($key, $orderId, $type, $amount, $build) {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();

            if ($existing = EscrowTransaction::where('idempotency_key', $key)->first()) {
                if ($existing->order_id !== $orderId || $existing->type !== $type || ($amount !== null && $existing->amount_cents !== $amount)) {
                    throw new LogicException("Idempotency key [{$key}] was already used for a different request.");
                }

                return $existing;
            }

            $result = $build($order);
            [$lines, $meta] = $result;
            $amount ??= $result[2];

            $debits = array_sum(array_column($lines, 'debit'));
            $credits = array_sum(array_column($lines, 'credit'));
            if ($debits !== $credits) {
                throw new LogicException("Unbalanced ledger posting: debits {$debits}, credits {$credits}.");
            }

            $tx = EscrowTransaction::create([
                'order_id' => $orderId, 'type' => $type, 'amount_cents' => $amount,
                'idempotency_key' => $key, 'meta' => $meta,
            ]);

            $now = now();
            LedgerEntry::insert(array_map(fn ($l) => [
                'escrow_transaction_id' => $tx->id,
                'order_id' => $orderId,
                'account' => $l['account'],
                'party_id' => $l['party_id'],
                'debit_cents' => $l['debit'] ?? 0,
                'credit_cents' => $l['credit'] ?? 0,
                'created_at' => $now,
            ], $lines));

            if ($type === EscrowTransaction::TYPE_RELEASE) {
                $sellerCredit = collect($lines)->first(
                    fn (array $line): bool => $line['account'] === PaymentSplitter::ACCOUNT_SELLER,
                );

                if (
                    $sellerCredit
                    && ($sellerCredit['credit'] ?? 0) > 0
                    && Profile::query()->whereKey($order->seller_id)->exists()
                ) {
                    app(SellerPayoutService::class)->record(
                        $order->seller_id,
                        (int) $sellerCredit['credit'],
                        $now,
                    );
                }
            }

            return $tx;
        });
    }
}
