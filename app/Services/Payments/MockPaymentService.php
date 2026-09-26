<?php

namespace App\Services\Payments;

use App\Models\EscrowTransaction;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\ParcelAssignment;
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
            $shares = PaymentSplitter::split(
                $total - $shipping,
                $shipping,
                $order->seller_id,
                $this->logisticsChain($order->id),
                array_map([Money::class, 'toCents'], $rateCard),
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
     * Distinct logistics companies in the order's parcel chain, origin first.
     *
     * @return list<string>
     */
    public function logisticsChain(string $orderId): array
    {
        return ParcelAssignment::where('order_id', $orderId)
            ->orderBy('created_at')
            ->pluck('logistics_company_id')
            ->unique()
            ->values()
            ->all();
    }

    private function refundedCents(string $orderId): int
    {
        return (int) EscrowTransaction::where('order_id', $orderId)
            ->where('type', EscrowTransaction::TYPE_REFUND)
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

            return $tx;
        });
    }
}
