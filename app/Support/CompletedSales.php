<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * What counts as a completed sale, everywhere buyers see sales numbers
 * (a product's "N sold", a store's items sold / orders / buyers):
 * an order that reached Delivered and was not refunded. New, Processing
 * and In Transit orders aren't complete yet; Cancelled orders never
 * deliver; a Refunded payment (OrderCancellationService) undoes the sale.
 */
class CompletedSales
{
    public const ORDER_STATUS = 'Delivered';

    public const EXCLUDED_PAYMENT_STATUS = 'Refunded';

    /**
     * Narrows a query that has the orders table available as $table.
     *
     * @template TQuery of Builder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function constrain($query, string $table = 'orders')
    {
        return $query
            ->where("{$table}.status", self::ORDER_STATUS)
            ->where(function ($q) use ($table) {
                $q->whereNull("{$table}.payment_status")
                    ->orWhere("{$table}.payment_status", '<>', self::EXCLUDED_PAYMENT_STATUS);
            });
    }

    /**
     * The same rule as raw SQL, for correlated sub-selects used in ORDER BY.
     */
    public static function sql(string $table = 'orders'): string
    {
        return "{$table}.status = '".self::ORDER_STATUS."' and ({$table}.payment_status is null or {$table}.payment_status <> '".self::EXCLUDED_PAYMENT_STATUS."')";
    }
}
