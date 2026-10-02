<?php

namespace App\Console\Commands;

use App\Http\Controllers\PsgcProxyController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Downloads the full PSGC dataset (4 requests) into the local psgc_*
 * tables. Safe to re-run: rows are upserted by code. Run once after
 * migrating, and again whenever the PSA publishes an update.
 */
class SyncPsgc extends Command
{
    protected $signature = 'psgc:sync';

    protected $description = 'Download PSGC regions, provinces, cities/municipalities and barangays into the local database';

    private const BASE = 'https://psgc.gitlab.io/api';

    public function handle(): int
    {
        try {
            $regions = $this->fetch('regions');
            $provinces = $this->fetch('provinces');
            $cities = $this->fetch('cities-municipalities');
            $barangays = $this->fetch('barangays');
        } catch (\Throwable $e) {
            $this->error('Download failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $code = fn ($v) => is_string($v) && $v !== '' ? $v : null;

        DB::transaction(function () use ($regions, $provinces, $cities, $barangays, $code) {
            $this->upsert('psgc_regions', array_map(fn ($r) => [
                'code' => $r['code'],
                'name' => $r['name'],
                'region_name' => $r['regionName'] ?? null,
                'island_group_code' => $code($r['islandGroupCode'] ?? null),
            ], $regions));

            $this->upsert('psgc_provinces', array_map(fn ($p) => [
                'code' => $p['code'],
                'name' => $p['name'],
                'region_code' => $p['regionCode'],
                'island_group_code' => $code($p['islandGroupCode'] ?? null),
            ], $provinces));

            $this->upsert('psgc_cities_municipalities', array_map(fn ($c) => [
                'code' => $c['code'],
                'name' => $c['name'],
                'is_city' => (bool) ($c['isCity'] ?? false),
                'is_capital' => (bool) ($c['isCapital'] ?? false),
                'province_code' => $code($c['provinceCode'] ?? null),
                'region_code' => $c['regionCode'],
                'island_group_code' => $code($c['islandGroupCode'] ?? null),
            ], $cities));

            $this->upsert('psgc_barangays', array_map(fn ($b) => [
                'code' => $b['code'],
                'name' => $b['name'],
                'city_municipality_code' => $code($b['cityCode'] ?? null) ?? $code($b['municipalityCode'] ?? null) ?? $code($b['subMunicipalityCode'] ?? null) ?? '',
                'province_code' => $code($b['provinceCode'] ?? null),
                'region_code' => $b['regionCode'],
                'island_group_code' => $code($b['islandGroupCode'] ?? null),
            ], $barangays));
        });

        // Old upstream responses are no longer needed.
        Cache::forget('psgc:all-provinces');
        Cache::forget(PsgcProxyController::LOCAL_FLAG_CACHE_KEY);

        $this->info(sprintf(
            'Synced %d regions, %d provinces, %d cities/municipalities, %d barangays.',
            count($regions), count($provinces), count($cities), count($barangays),
        ));

        return self::SUCCESS;
    }

    /** @return array<int, array<string, mixed>> */
    private function fetch(string $path): array
    {
        $this->line("Downloading {$path}…");

        $response = Http::timeout(120)->retry(2, 2000)->get(self::BASE . "/{$path}/");
        $data = $response->throw()->json();

        if (! is_array($data) || $data === []) {
            throw new \RuntimeException("Empty {$path} response.");
        }

        return $data;
    }

    private function upsert(string $table, array $rows): void
    {
        $columns = array_keys($rows[0]);

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table($table)->upsert($chunk, ['code'], array_diff($columns, ['code']));
        }
    }
}
