<?php

namespace App\Services\Payments;

use InvalidArgumentException;

/** Philippine peso amounts as integer centavos. No float arithmetic. */
final class Money
{
    public static function toCents(string|int|float $pesos): int
    {
        $value = is_float($pesos) ? sprintf('%.2F', $pesos) : trim((string) $pesos);

        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $m)) {
            throw new InvalidArgumentException("Invalid peso amount [{$value}].");
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    public static function format(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /** $cents × $basisPoints / 10000, rounded half-up. */
    public static function percent(int $cents, int $basisPoints): int
    {
        return intdiv($cents * $basisPoints + 5000, 10000);
    }

    /**
     * Split $amount across $weights proportionally (largest remainder), so
     * the parts always sum to exactly $amount. Keys are preserved.
     *
     * @param  array<array-key, int>  $weights
     * @return array<array-key, int>
     */
    public static function allocate(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        if ($total <= 0 || $amount === 0) {
            return array_map(fn () => 0, $weights);
        }

        $parts = [];
        $remainders = [];
        foreach ($weights as $key => $weight) {
            $parts[$key] = intdiv($amount * $weight, $total);
            $remainders[$key] = ($amount * $weight) % $total;
        }

        arsort($remainders);
        $left = $amount - array_sum($parts);
        foreach (array_keys($remainders) as $key) {
            if ($left-- <= 0) {
                break;
            }
            $parts[$key]++;
        }

        return $parts;
    }
}
