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
    private const CACHE_TTL_SECONDS = 60 * 60 * 24;

    public function regions(Request $request): JsonResponse
    {
        return $this->proxy('/regions', $request->only(['limit']));
    }

    public function provinces(Request $request): JsonResponse
    {
        return $this->proxy('/provinces', $request->only(['region_code']));
    }

    public function citiesMunicipalities(Request $request): JsonResponse
    {
        return $this->proxy('/cities-municipalities', $request->only(['province_code']));
    }

    public function barangays(Request $request): JsonResponse
    {
        // Support both parameter names for compatibility
        $params = [];
        if ($request->has('municipality_code')) {
            $params['city_municipality_code'] = $request->input('municipality_code');
        } elseif ($request->has('city_municipality_code')) {
            $params['city_municipality_code'] = $request->input('city_municipality_code');
        }
        
        if ($request->has('limit')) {
            $params['limit'] = $request->input('limit');
        }
        
        return $this->proxy('/barangays', $params);
    }

    private function proxy(string $path, array $query): JsonResponse
    {
        $cacheKey = 'psgc:' . $path . ':' . http_build_query($query);

        try {
            $payload = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($path, $query) {
                $url = self::BASE . $path;
                Log::info('PSGC Proxy Request', ['url' => $url, 'query' => $query]);

                $response = Http::timeout(20)->get($url, $query);

                Log::info('PSGC Proxy Response', [
                    'status' => $response->status(),
                    'successful' => $response->successful(),
                ]);

                if (! $response->successful()) {
                    Log::error('PSGC upstream request failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    throw new \RuntimeException('PSGC upstream request failed: ' . $response->status());
                }

                $responseData = $response->json();

                $filtered = $this->filterPayloadByQuery($responseData, $query);

                if (is_array($filtered) && array_is_list($filtered)) {
                    return ['data' => $filtered];
                }

                if (is_array($filtered) && isset($filtered['data'])) {
                    return $filtered;
                }

                return ['data' => $filtered];
            });

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

    private function filterPayloadByQuery(mixed $payload, array $query): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        $items = $payload;

        if (isset($payload['data']) && is_array($payload['data'])) {
            $items = $payload['data'];
        }

        if (! array_is_list($items)) {
            return $payload;
        }

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

        if (isset($query['city_municipality_code'])) {
            $municipalityCode = (string) $query['city_municipality_code'];
            $items = array_values(array_filter($items, function ($item) use ($municipalityCode) {
                if (! is_array($item)) {
                    return false;
                }

                $itemMunicipalityCode = (string) ($item['municipalityCode'] ?? $item['cityCode'] ?? $item['city_municipality_code'] ?? $item['municipality_code'] ?? '');
                $itemCode = (string) ($item['code'] ?? '');

                return $itemMunicipalityCode === $municipalityCode || str_starts_with($itemCode, substr($municipalityCode, 0, 7));
            }));
        }

        $items = $this->deduplicateByCodeOrName($items);

        if (isset($payload['data']) && is_array($payload['data'])) {
            return ['data' => $items];
        }

        return $items;
    }

    private function deduplicateByCodeOrName(array $items): array
    {
        $seen = [];
        $unique = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $code = (string) ($item['code'] ?? $item['provinceCode'] ?? $item['cityCode'] ?? $item['municipalityCode'] ?? '');
            $name = (string) ($item['name'] ?? '');

            $key = $code !== ''
                ? 'code:' . $code
                : 'name:' . strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? '');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $item;
        }

        usort($unique, function ($a, $b) {
            $nameA = (string) ($a['name'] ?? '');
            $nameB = (string) ($b['name'] ?? '');

            return strcasecmp($nameA, $nameB);
        });

        return $unique;
    }
}