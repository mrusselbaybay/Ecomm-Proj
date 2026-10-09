<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema questions the buyer catalogue asks on every request ("does
 * products.subcategory exist?", "is store_follows there yet?"), answered
 * from the file cache instead of the database.
 *
 * Each uncached check is a catalogue query against Supabase, a full network
 * round trip (~110 ms here) before any real work starts. The answers only
 * change when a migration runs, so they're cached for a day and forgotten
 * whenever migrations finish (AppServiceProvider listens for that).
 *
 * The file store, not the default (database) store: a cache read must not
 * cost the round trip it is meant to save. Only PostgreSQL answers are
 * cached; the sqlite test database changes shape between tests.
 */
class SchemaCache
{
    private const PREFIX = 'schema-cache.';

    private const KEYS = 'schema-cache.keys';

    /** @var array<string, mixed> per-process memo */
    private static array $memo = [];

    public static function hasColumn(string $table, string $column): bool
    {
        return (bool) self::remember("column.{$table}.{$column}", fn () => Schema::hasColumn($table, $column));
    }

    /**
     * @param  list<string>  $columns
     */
    public static function hasColumns(string $table, array $columns): bool
    {
        return (bool) self::remember("columns.{$table}.".implode(',', $columns), fn () => Schema::hasColumns($table, $columns));
    }

    public static function hasTable(string $table): bool
    {
        return (bool) self::remember("table.{$table}", fn () => Schema::hasTable($table));
    }

    /**
     * @return list<string>
     */
    public static function columns(string $table): array
    {
        return self::remember("listing.{$table}", fn () => Schema::getColumnListing($table));
    }

    public static function flush(): void
    {
        $store = Cache::store('file');

        foreach ($store->get(self::KEYS, []) as $key) {
            $store->forget($key);
        }

        $store->forget(self::KEYS);
        self::$memo = [];
    }

    private static function remember(string $name, callable $resolve): mixed
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return $resolve();
        }

        $key = self::PREFIX.DB::connection()->getName().'.'.$name;

        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        $store = Cache::store('file');
        $value = $store->get($key);

        if ($value === null) {
            $value = $resolve();
            $store->put($key, $value, now()->addDay());
            $store->forever(self::KEYS, array_values(array_unique([...$store->get(self::KEYS, []), $key])));
        }

        return self::$memo[$key] = $value;
    }
}
