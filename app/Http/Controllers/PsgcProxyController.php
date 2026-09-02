<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PsgcProxyController extends Controller
{
    private const BASE = 'https://psgc.gitlab.io/api';
    // PSGC codes are effectively static reference data (they change on
    // the order of years, not days), so caching for a day was making
    // every signup pay the slow upstream-refresh cost once a day for
    // every unique query combination. A month cuts that down drastically
    // for everyone hitting these endpoints, not just one browser session.
    private const CACHE_TTL_SECONDS = 60 * 60 * 24 * 30; // 30 days

    /**
     * Get all regions
     */
    public function regions(Request $request): JsonResponse
    {
        return $this->proxy('/regions', $request->only(['limit']));
    }

    /**
     * Get provinces by region code
     */
    public function provinces(Request $request): JsonResponse
    {
        // De-duplicate by code and sort by name: the upstream feed can
        // return the same province twice, and address dropdowns want it
        // alphabetical. (See PsgcProxyTest.)
        return $this->proxy('/provinces', $request->only(['region_code']), dedupeAndSort: true);
    }

    /**
     * Get every province across every region in one response.
     *
     * The signup wizard's fetchProvinces() (resources/js/app.js) used to
     * do this fan-out itself: one request for /regions, then one more
     * request per region (~17) for that region's provinces, all from the
     * browser. Even with each individual call cached, that's 18 separate
     * HTTP round-trips — and a full framework bootstrap — for every
     * visitor who reaches the signup wizard, which is exactly the kind of
     * thing that shows up as "the address step keeps timing out". This
     * does the same fan-out once, server-side, with real concurrency via
     * Http::pool() (not dependent on the browser's per-origin connection
     * limit or how many requests the dev server can run at once), and
     * caches the combined result under a single key — so after the very
     * first request from anyone, every subsequent visitor gets it in one
     * fast local round-trip instead of 18.
     */
    public function allProvinces(): JsonResponse
    {
        try {
            $provinces = Cache::remember('psgc:all-provinces', self::CACHE_TTL_SECONDS, function () {
                $regionsResponse = Http::timeout(30)->get(self::BASE . '/regions', ['limit' => 100]);

                if (! $regionsResponse->successful()) {
                    throw new \RuntimeException('PSGC regions request failed: ' . $regionsResponse->status());
                }

                $regions = $this->extractList($regionsResponse->json());

                $responses = Http::pool(fn ($pool) => collect($regions)
                    ->map(fn ($region) => $pool->as($region['code'] ?? uniqid('region_', true))
                        ->timeout(15)
                        ->get(self::BASE . '/provinces', ['region_code' => $region['code'] ?? null]))
                    ->all());

                $provinces = [];

                foreach ($responses as $response) {
                    if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                        $provinces = array_merge($provinces, $this->extractList($response->json()));
                    }
                }

                if (empty($provinces)) {
                    throw new \RuntimeException('No provinces returned from any region.');
                }

                return $this->dedupeByCode($provinces);
            });

            return response()->json(['data' => $provinces]);
        } catch (\Throwable $e) {
            Log::error('PSGC allProvinces failed', ['message' => $e->getMessage()]);

            return response()->json([
                'error' => 'Failed to fetch provinces from PSGC API',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Pull the list out of whatever shape the upstream response is in.
     */
    private function extractList(mixed $responseData): array
    {
        if (is_array($responseData) && isset($responseData['data']) && is_array($responseData['data'])) {
            return $responseData['data'];
        }

        if (is_array($responseData) && array_is_list($responseData)) {
            return $responseData;
        }

        return [];
    }

    /**
     * De-duplicate by province code (regions occasionally overlap at the
     * boundaries in the upstream data) while preserving order.
     */
    private function dedupeByCode(array $items): array
    {
        $seen = [];
        $result = [];

        foreach ($items as $item) {
            $key = is_array($item) ? ($item['code'] ?? $item['name'] ?? null) : null;

            if ($key === null || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $item;
        }

        return $result;
    }

    /**
     * Get cities/municipalities by province code
     */
    public function citiesMunicipalities(Request $request): JsonResponse
    {
        return $this->proxy('/cities-municipalities', $request->only(['province_code']));
    }

    /**
     * Get barangays by city/municipality code
     * Supports both 'city_municipality_code' and 'municipality_code' parameters
     */
    public function barangays(Request $request): JsonResponse
    {
        // Support both parameter names for compatibility
        $params = [];

        if ($request->has('city_municipality_code')) {
            $params['city_municipality_code'] = $request->input('city_municipality_code');
        } elseif ($request->has('municipality_code')) {
            $params['city_municipality_code'] = $request->input('municipality_code');
        }

        if ($request->has('limit')) {
            $params['limit'] = $request->input('limit');
        }

        return $this->proxy('/barangays', $params);
    }

    /**
     * Proxy request to PSGC API with caching
     */
    private function proxy(string $path, array $query, bool $dedupeAndSort = false): JsonResponse
    {
        $cacheKey = 'psgc:'.$path.':'.http_build_query($query);
        $cacheKey = str_replace(['[', ']'], '', $cacheKey); // Clean cache key

        try {
            $payload = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($path, $query) {
                $url = self::BASE.$path;
                Log::info('PSGC Proxy Request', ['url' => $url, 'query' => $query]);

                $response = Http::timeout(30)->get($url, $query);

                Log::info('PSGC Proxy Response', [
                    'status' => $response->status(),
                    'successful' => $response->successful(),
                ]);

                if (! $response->successful()) {
                    Log::error('PSGC upstream request failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    throw new \RuntimeException('PSGC upstream request failed: '.$response->status());
                }

                $responseData = $response->json();

                // If the response is already in our expected format, return it
                if (isset($responseData['data']) && is_array($responseData['data'])) {
                    // Filter the data if needed
                    $filtered = $this->filterPayloadByQuery($responseData['data'], $query);

                    return ['data' => $filtered];
                }

                // If response is a list, wrap it in our format
                if (is_array($responseData) && array_is_list($responseData)) {
                    $filtered = $this->filterPayloadByQuery($responseData, $query);

                    return ['data' => $filtered];
                }

                // If response is an object with data property, use it directly
                if (is_array($responseData) && isset($responseData['data'])) {
                    $filtered = $this->filterPayloadByQuery($responseData['data'], $query);

                    return ['data' => $filtered];
                }

                return ['data' => $responseData];
            });

            if ($dedupeAndSort && isset($payload['data']) && is_array($payload['data']) && array_is_list($payload['data'])) {
                $payload['data'] = collect($payload['data'])
                    ->unique(fn ($item) => is_array($item) ? ($item['code'] ?? json_encode($item)) : $item)
                    ->sortBy(fn ($item) => is_array($item) ? ($item['name'] ?? '') : $item)
                    ->values()
                    ->all();
            }

            return response()->json($payload);

        } catch (\Throwable $e) {
            Log::error('PSGC Proxy Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to fetch data from PSGC API',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Filter payload by query parameters (client-side filtering fallback)
     */
    private function filterPayloadByQuery(mixed $payload, array $query): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        $items = $payload;

        // If payload has a data key, use that as the items array
        if (isset($payload['data']) && is_array($payload['data'])) {
            $items = $payload['data'];
        }

        // If items is not a list, return as-is
        if (! array_is_list($items)) {
            return $payload;
        }

        // Filter by province_code
        if (isset($query['province_code'])) {
            $provinceCode = (string) $query['province_code'];
            $items = array_values(array_filter($items, function ($item) use ($provinceCode) {
                if (! is_array($item)) {
                    return false;
                }

                $itemProvinceCode = (string) ($item['provinceCode'] ?? $item['province_code'] ?? '');

                return $itemProvinceCode === $provinceCode;
            }));
        }

        // Filter by city_municipality_code
        if (isset($query['city_municipality_code'])) {
            $municipalityCode = (string) $query['city_municipality_code'];
            $items = array_values(array_filter($items, function ($item) use ($municipalityCode) {
                if (! is_array($item)) {
                    return false;
                }

                $itemMunicipalityCode = (string) ($item['municipalityCode'] ??
                    $item['cityCode'] ??
                    $item['city_municipality_code'] ??
                    $item['municipality_code'] ??
                    '');

                $itemCode = (string) ($item['code'] ?? '');

                return $itemMunicipalityCode === $municipalityCode ||
                       str_starts_with($itemCode, substr($municipalityCode, 0, 7));
            }));
        }

        // Return in the same format we received
        if (isset($payload['data']) && is_array($payload['data'])) {
            return ['data' => $items];
        }

        return $items;
    }

    /**
     * Clear the cache for a specific path or all PSGC cache
     */
    public function clearCache(Request $request): JsonResponse
    {
        $path = $request->input('path');

        if ($path) {
            $cacheKey = 'psgc:'.$path;
            Cache::forget($cacheKey);

            return response()->json(['message' => "Cache cleared for: {$path}"]);
        }

        // Clear all PSGC cache (be careful with this)
        $keys = ['psgc:/regions', 'psgc:/provinces', 'psgc:/cities-municipalities', 'psgc:/barangays'];
        foreach ($keys as $key) {
            Cache::forget($key);
        }

        return response()->json(['message' => 'All PSGC cache cleared']);
    }
}
