<?php

namespace App\Support;

/**
 * The return rules the platform enforces for every store
 * (Buyer\ReturnController): a store's own return_policy text is shown
 * alongside these, never instead of them, and can't loosen or replace
 * them.
 */
class PlatformReturnPolicy
{
    /**
     * Order status an item must reach before a return can be requested.
     */
    public const ELIGIBLE_ORDER_STATUS = 'Delivered';

    /**
     * Request statuses that count as "open" for the one-per-item rule.
     *
     * @var list<string>
     */
    public const OPEN_REQUEST_STATUSES = ['pending', 'approved'];

    /**
     * Buyer-facing wording of the rules above.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return [
            'Request a return or refund from your Orders page once the order has been delivered.',
            'Each item can have one open return request at a time.',
        ];
    }
}
