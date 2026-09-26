<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only. Balance of an account = SUM(credit) - SUM(debit), i.e. the
 * money currently "held" there; a negative party balance is a clawback owed.
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'escrow_transaction_id', 'order_id', 'account', 'party_id', 'debit_cents', 'credit_cents',
    ];

    protected $casts = ['debit_cents' => 'integer', 'credit_cents' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Ledger entries are immutable.'));
    }

    public static function balance(string $account, ?string $partyId = null): int
    {
        return (int) static::query()
            ->where('account', $account)
            ->when($partyId !== null, fn ($q) => $q->where('party_id', $partyId))
            ->selectRaw('COALESCE(SUM(credit_cents), 0) - COALESCE(SUM(debit_cents), 0) AS bal')
            ->value('bal');
    }
}
