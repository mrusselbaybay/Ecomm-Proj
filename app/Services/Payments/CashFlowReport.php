<?php

namespace App\Services\Payments;

use App\Models\LedgerEntry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A party's view of the mock-escrow ledger: what escrow released to it,
 * what refunds clawed back, and its recent movements.
 */
class CashFlowReport
{
    /**
     * @param  list<string>  $accounts
     */
    public function for(array $accounts, ?string $partyId, int $limit = 20): array
    {
        $base = fn (): Builder => DB::table('ledger_entries as le')
            ->join('escrow_transactions as et', 'et.id', '=', 'le.escrow_transaction_id')
            ->whereIn('le.account', $accounts)
            ->when($partyId !== null, fn ($q) => $q->where('le.party_id', $partyId));

        $totals = $base()
            ->selectRaw("COALESCE(SUM(CASE WHEN et.type = 'release' THEN le.credit_cents ELSE 0 END), 0) AS released")
            ->selectRaw("COALESCE(SUM(CASE WHEN et.type = 'refund' THEN le.debit_cents - le.credit_cents ELSE 0 END), 0) AS clawed_back")
            ->selectRaw('COUNT(DISTINCT le.order_id) AS orders_count')
            ->first();

        $entries = $base()
            ->join('orders as o', 'o.id', '=', 'le.order_id')
            ->orderByDesc('le.id')
            ->limit($limit)
            ->get(['le.id', 'le.account', 'le.debit_cents', 'le.credit_cents', 'le.created_at', 'et.type', 'o.order_number'])
            ->map(fn ($e) => [
                'id' => $e->id,
                'order_number' => $e->order_number,
                'type' => $e->type,
                'account' => $e->account,
                'amount' => Money::format(abs($e->credit_cents - $e->debit_cents)),
                'direction' => $e->credit_cents >= $e->debit_cents ? 'in' : 'out',
                'created_at' => $e->created_at,
            ]);

        $released = (int) $totals->released;
        $clawedBack = (int) $totals->clawed_back;

        return [
            'currency' => 'PHP',
            'released' => Money::format($released),
            'clawed_back' => Money::format(max($clawedBack, 0)),
            'net' => ($released - $clawedBack < 0 ? '-' : '').Money::format(abs($released - $clawedBack)),
            'orders_count' => (int) $totals->orders_count,
            'entries' => $entries,
        ];
    }
}
