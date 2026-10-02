<?php

namespace App\Support;

/**
 * Reduces a "street" value to just the street when a buyer typed (or a
 * legacy import stored) the whole address into it — e.g.
 * "Rizal Street, Brgy. Calo, Bay, Laguna, Region IV-A, Philippines Rizal Street, …"
 * → "Rizal Street".
 *
 * Drops segments that repeat the structured parts we already store
 * (barangay, municipality, province, region), PSGC region labels and
 * "Philippines", then removes repeated segments. Never returns empty: if
 * everything would be stripped, the first segment is kept.
 */
class StreetCleaner
{
    /**
     * @param  array<int, ?string>  $known  barangay / municipality / province / region names
     */
    public static function clean(?string $street, array $known = []): ?string
    {
        if ($street === null || trim($street) === '') {
            return $street;
        }

        $normalize = fn (string $v) => preg_replace(
            '/^(brgy\.?|barangay|bgy\.?)\s+/i',
            '',
            mb_strtolower(preg_replace('/\s+/', ' ', trim($v)))
        );

        $knownSet = collect($known)->filter()->map($normalize)->flip();

        // "Philippines" often glues two copies together without a comma.
        $text = preg_replace('/\bphilippines\b/i', ',', $street);
        $segments = array_values(array_filter(array_map('trim', explode(',', $text)), fn ($s) => $s !== ''));

        if (! $segments) {
            return trim($street);
        }

        $kept = [];
        $seen = [];

        foreach ($segments as $segment) {
            $key = $normalize($segment);

            $isKnown = $knownSet->has($key)
                || preg_match('/^(region\s+[ivx0-9]+(-[a-z])?|ncr|car|barmm|mimaropa|metro manila|national capital region)$/i', $key);

            if ($isKnown || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $kept[] = $segment;
        }

        return $kept ? implode(', ', $kept) : $segments[0];
    }
}
