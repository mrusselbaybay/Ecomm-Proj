<?php

namespace App\Support;

/**
 * A pinpointed map location (latitude/longitude) attached to an address.
 * The bounds are a generous box around the Philippines — they reject
 * swapped lat/lng and 0,0 "null island" pins, not borderline islands.
 */
final class MapPin
{
    public const LAT_MIN = 4.2;

    public const LAT_MAX = 21.5;

    public const LNG_MIN = 116.0;

    public const LNG_MAX = 127.0;

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'latitude' => ['nullable', 'numeric', 'between:'.self::LAT_MIN.','.self::LAT_MAX, 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:'.self::LNG_MIN.','.self::LNG_MAX, 'required_with:latitude'],
        ];
    }

    /** @return array{lat: float, lng: float}|null */
    public static function from(mixed $lat, mixed $lng): ?array
    {
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return null;
        }

        return ['lat' => round((float) $lat, 6), 'lng' => round((float) $lng, 6)];
    }
}
