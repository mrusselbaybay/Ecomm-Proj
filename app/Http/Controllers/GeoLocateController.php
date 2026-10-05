<?php

namespace App\Http\Controllers;

use App\Support\MapPin;
use App\Support\PhilippineGeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Where to open the address pin picker (resources/js/shared/AddressPinPicker.vue)
 * so the user only has to nudge the pin instead of hunting across the map.
 *
 * Tries OpenStreetMap Nominatim from most to least specific (street →
 * barangay → municipality), then the offline PhilippineGeo centroids,
 * then the whole country. Upstream answers are cached for 30 days and
 * proxied server-side: one shared cache + an identifying User-Agent, as
 * Nominatim's usage policy requires.
 */
class GeoLocateController extends Controller
{
    private const NOMINATIM = 'https://nominatim.openstreetmap.org/search';

    private const CACHE_TTL = 60 * 60 * 24 * 30;

    // Misses are cached briefly so a flaky upstream isn't re-hit per keystroke.
    private const MISS_TTL = 60 * 60 * 6;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'street' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:150'],
            'municipality' => ['nullable', 'string', 'max:150'],
            'province' => ['nullable', 'string', 'max:150'],
        ]);

        $street = trim((string) ($data['street'] ?? ''));
        $barangay = trim((string) ($data['barangay'] ?? ''));
        $municipality = trim((string) ($data['municipality'] ?? ''));
        $province = trim((string) ($data['province'] ?? ''));

        $attempts = array_filter([
            'street' => $street && $barangay && $municipality ? [$street, $barangay, $municipality, $province] : null,
            'barangay' => $barangay && $municipality ? [$barangay, $municipality, $province] : null,
            'municipality' => $municipality ? [$municipality, $province] : null,
        ]);

        foreach ($attempts as $precision => $parts) {
            if ($hit = $this->nominatim($parts)) {
                return $this->answer($hit, $precision);
            }
        }

        if ($hit = PhilippineGeo::locate($municipality, $province)) {
            return $this->answer($hit, 'approximate');
        }

        // Geographic centre of the Philippines.
        return $this->answer(['lat' => 12.8797, 'lng' => 121.774], 'country');
    }

    /** @param  list<string>  $parts */
    private function nominatim(array $parts): ?array
    {
        $query = implode(', ', array_filter([...$parts, 'Philippines']));
        $key = 'geo:locate:'.md5(mb_strtolower($query));

        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached ?: null;
        }

        try {
            $response = Http::timeout(4)
                ->withHeaders(['User-Agent' => config('app.name', 'BuyTheWay').' address pin ('.config('app.url').')'])
                ->get(self::NOMINATIM, ['q' => $query, 'format' => 'jsonv2', 'countrycodes' => 'ph', 'limit' => 1]);
        } catch (\Throwable $e) {
            Log::info('GeoLocate: Nominatim unreachable', ['error' => $e->getMessage()]);

            return null; // not cached — retry next time
        }

        $first = $response->ok() ? ($response->json()[0] ?? null) : null;
        $hit = $first ? MapPin::from($first['lat'] ?? null, $first['lon'] ?? null) : null;

        if ($hit && ! $this->inPhilippines($hit)) {
            $hit = null;
        }

        Cache::put($key, $hit ?: false, $hit ? self::CACHE_TTL : self::MISS_TTL);

        return $hit;
    }

    private function inPhilippines(array $p): bool
    {
        return $p['lat'] >= MapPin::LAT_MIN && $p['lat'] <= MapPin::LAT_MAX
            && $p['lng'] >= MapPin::LNG_MIN && $p['lng'] <= MapPin::LNG_MAX;
    }

    private function answer(array $point, string $precision): JsonResponse
    {
        $zoom = ['street' => 17, 'barangay' => 15, 'municipality' => 13, 'approximate' => 11, 'country' => 6][$precision];

        return response()->json(['data' => [
            'lat' => round((float) $point['lat'], 6),
            'lng' => round((float) $point['lng'], 6),
            'precision' => $precision,
            'zoom' => $zoom,
        ]]);
    }
}
