<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Checkout stopped because the amount changed (a price, a fee, an item
 * that can no longer be bought) since the quote the buyer confirmed.
 * Carries the fresh quote so the page can show the new amounts and ask
 * the buyer to confirm again. Nothing was ordered.
 */
class CheckoutQuoteChanged extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $quote
     */
    public function __construct(public readonly array $quote)
    {
        parent::__construct('Prices or fees changed since you last reviewed your order.');
    }
}
