<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * How buyer search matches and ranks products and stores — shared by the
 * results page (GET /api/products?search=), the store list
 * (GET /api/stores?search=), related products and the header suggestions,
 * so all of them agree on what "matches" means.
 *
 * On PostgreSQL:
 *   - Words: full-text search with the 'simple' configuration (no
 *     stemming or stop words — the catalogue mixes English, Filipino and
 *     brand names, which language stemmers mangle) over name, brand,
 *     category, subcategory and description. Every word must appear; the
 *     last one may be a prefix ("cat foo" finds "cat food"). Indexed by
 *     products_search_document_idx (expression GIN index, DOCUMENT_SQL).
 *   - Partial names and typos, when the pg_trgm extension is installed:
 *     the name contains the phrase ("atfoo" finds "catfood"), or the phrase
 *     is a close trigram word match for part of the name ("catfod" finds
 *     "catfood"). Both indexed by products_name_trgm_idx. Only queries of
 *     at least MIN_FUZZY_LENGTH characters use them — shorter ones have no
 *     useful trigrams and would scan the table.
 *   - Store names, the same way (seller_details_business_name_trgm_idx),
 *     so searching a store also finds its products.
 * Without pg_trgm, partial names fall back to an unindexed lower() like and
 * typos aren't tolerated. On other databases (the sqlite test suite) every
 * word must appear, as a substring, in one of the same fields.
 *
 * Query text is normalised before it reaches SQL: lower-cased, anything
 * but letters and digits becomes a word break, at most MAX_TERMS words of
 * MAX_TERM_LENGTH characters. Every value is bound as a parameter.
 */
class ProductSearch
{
    public const MAX_TERMS = 6;

    public const MAX_TERM_LENGTH = 40;

    public const MIN_FUZZY_LENGTH = 3;

    /** Whole-name trigram similarity a typo match needs (see fuzzyNameSql()). */
    public const NAME_SIMILARITY = 0.45;

    /**
     * The searchable text of a product. Must stay identical to the
     * expression indexed by the 2026_10_08 search index migration, or the
     * planner can't use that index.
     */
    public const DOCUMENT_SQL = "to_tsvector('simple'::regconfig, coalesce(products.name, '') || ' ' || coalesce(products.brand, '') || ' ' || coalesce(products.category, '') || ' ' || coalesce(products.subcategory, '') || ' ' || coalesce(products.description, ''))";

    private const NAME_DOCUMENT_SQL = "to_tsvector('simple'::regconfig, coalesce(products.name, ''))";

    private const TRIGRAM_CACHE_KEY = 'search.pg_trgm_schema';

    /** @var array<string, string|false> per-connection memo of the pg_trgm schema */
    private static array $trigramSchema = [];

    /**
     * Lower-cased words of a query, punctuation removed.
     *
     * @return list<string>
     */
    public static function terms(string $search): array
    {
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($search)) ?? '';
        $words = preg_split('/\s+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_map(
            fn (string $word) => mb_substr($word, 0, self::MAX_TERM_LENGTH),
            array_slice(array_unique($words), 0, self::MAX_TERMS),
        ));
    }

    /** The words joined by single spaces: what exact / prefix / contains compare against. */
    public static function phrase(string $search): string
    {
        return implode(' ', self::terms($search));
    }

    public static function isSearchable(string $search): bool
    {
        return self::terms($search) !== [];
    }

    /**
     * Narrows a products query to the products matching $search.
     */
    public static function apply(Builder|QueryBuilder $query, string $search): void
    {
        $terms = self::terms($search);

        if ($terms === []) {
            // Only punctuation ("%", "--"): nothing can match it.
            if (trim($search) !== '') {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $query->where(fn ($q) => self::matchProducts($q, $terms));
    }

    /**
     * Products NOT matching $search (related products leave direct matches out).
     */
    public static function exclude(Builder|QueryBuilder $query, string $search): void
    {
        $terms = self::terms($search);

        if ($terms !== []) {
            $query->whereNot(fn ($q) => self::matchProducts($q, $terms));
        }
    }

    /**
     * Best match first:
     *   0 the name is the query, 1 the name starts with it, 2 every word
     *   is in the name, 3 the name contains it, 4 the store's name matches,
     *   5 the words are in the brand / category / subcategory /
     *   description, 6 a typo-tolerant name match only.
     * Within a bucket: closer trigram match (PostgreSQL + pg_trgm), then
     * newest, then id — so results never shuffle between pages.
     */
    public static function orderByRelevance(Builder|QueryBuilder $query, string $search): void
    {
        $terms = self::terms($search);

        if ($terms === []) {
            return;
        }

        $phrase = implode(' ', $terms);
        // "cat-food" is still an exact match for a product named "cat-food".
        $raw = mb_strtolower(preg_replace('/\s+/u', ' ', trim($search)) ?? '');
        $contains = self::likeContains($phrase);

        if (! self::isPostgres()) {
            $query->orderByRaw(
                "case
                    when lower(products.name) in (?, ?) then 0
                    when lower(products.name) like ? escape '\\' then 1
                    when lower(products.name) like ? escape '\\' then 3
                    when exists (select 1 from seller_details where seller_details.profile_id = products.seller_id and lower(coalesce(seller_details.business_name, '')) like ? escape '\\') then 4
                    when lower(coalesce(products.category, '')) like ? escape '\\' then 5
                    else 6
                end",
                [$raw, $phrase, self::likePrefix($phrase), $contains, $contains, $contains],
            );

            return;
        }

        $tsquery = self::tsquery($terms);

        $query->orderByRaw(
            'case
                when lower(products.name) in (?, ?) then 0
                when lower(products.name) like ? escape \'\\\' then 1
                when '.self::NAME_DOCUMENT_SQL.' @@ to_tsquery(\'simple\'::regconfig, ?) then 2
                when lower(products.name) like ? escape \'\\\' then 3
                when exists (select 1 from seller_details where seller_details.profile_id = products.seller_id and '.self::storeNameMatchSql($phrase, 'seller_details.business_name', $storeBindings).') then 4
                when '.self::DOCUMENT_SQL.' @@ to_tsquery(\'simple\'::regconfig, ?) then 5
                else 6
            end',
            [$raw, $phrase, self::likePrefix($phrase), $tsquery, $contains, ...$storeBindings, $tsquery],
        );

        if ($schema = self::trigramSchema()) {
            $query->orderByRaw("{$schema}.word_similarity(?, lower(products.name)) desc", [$phrase]);
        }
    }

    /**
     * Stores whose name matches: contains the phrase, or (pg_trgm) a close
     * trigram match for it.
     */
    public static function applyToStores(Builder|QueryBuilder $query, string $search, string $column = 'seller_details.business_name'): void
    {
        $phrase = self::phrase($search);

        if ($phrase === '') {
            return;
        }

        $sql = self::storeNameMatchSql($phrase, $column, $bindings);
        $query->whereRaw("({$sql})", $bindings);
    }

    /**
     * Stores by how well their name matches: exact, starts with the query,
     * has it as a whole word ("Happy Paws" for "paws"), has a word starting
     * with it ("The Pawsome Den"), contains it, then typo-tolerant matches
     * (closest first). Callers add their own secondary order and a final
     * stable tie-breaker.
     */
    public static function orderStoresByRelevance(Builder|QueryBuilder $query, string $search, string $column = 'seller_details.business_name'): void
    {
        $phrase = self::phrase($search);

        if ($phrase === '') {
            return;
        }

        $name = "lower({$column})";
        $contains = self::likeContains($phrase);

        $query->orderByRaw(
            "case
                when {$name} = ? then 0
                when {$name} like ? escape '\' then 1
                when (' ' || {$name} || ' ') like ? escape '\' then 2
                when {$name} like ? escape '\' then 3
                when {$name} like ? escape '\' then 4
                else 5
            end",
            [$phrase, self::likePrefix($phrase), self::likeContains(' '.$phrase.' '), self::likeContains(' '.$phrase), $contains],
        );

        // Only typo matches are told apart by closeness; within the other
        // tiers the caller's secondary order (product count) decides.
        if ($schema = self::trigramSchema()) {
            $query->orderByRaw(
                "case when {$name} like ? escape '\' then 1 else {$schema}.word_similarity(?, {$name}) end desc",
                [$contains, $phrase],
            );
        }
    }

    /**
     * The schema pg_trgm is installed in (Supabase puts extensions in
     * "extensions", which isn't on the search_path, so every use is
     * schema-qualified), or null when it isn't installed. Checked once per
     * hour and cached in the file store, not the (database) default store,
     * so the check doesn't cost a database round trip per request.
     */
    public static function trigramSchema(): ?string
    {
        if (! self::isPostgres()) {
            return null;
        }

        $connection = DB::connection()->getName();

        if (! array_key_exists($connection, self::$trigramSchema)) {
            self::$trigramSchema[$connection] = Cache::store('file')->remember(
                self::TRIGRAM_CACHE_KEY.'.'.$connection,
                now()->addHour(),
                function () {
                    try {
                        $schema = DB::selectOne("select extnamespace::regnamespace::text as schema from pg_extension where extname = 'pg_trgm'")?->schema;
                    } catch (Throwable) {
                        $schema = null;
                    }

                    // A quoted identifier is never expected here; anything
                    // unusual is treated as "not available" rather than
                    // interpolated into SQL.
                    return is_string($schema) && preg_match('/^[a-z_][a-z0-9_]*$/', $schema) ? $schema : false;
                },
            );
        }

        return self::$trigramSchema[$connection] ?: null;
    }

    /** Forget the cached pg_trgm check (after the extension is installed or removed). */
    public static function forgetTrigramSchema(): void
    {
        self::$trigramSchema = [];

        foreach (array_keys(config('database.connections', [])) as $connection) {
            Cache::store('file')->forget(self::TRIGRAM_CACHE_KEY.'.'.$connection);
        }
    }

    /**
     * @param  list<string>  $terms
     */
    private static function matchProducts($query, array $terms): void
    {
        $phrase = implode(' ', $terms);

        if (! self::isPostgres()) {
            // Portable fallback: every word appears somewhere.
            foreach ($terms as $term) {
                $like = self::likeContains($term);

                $query->where(fn ($q) => $q
                    ->whereRaw("lower(products.name) like ? escape '\\'", [$like])
                    ->orWhereRaw("lower(coalesce(products.description, '')) like ? escape '\\'", [$like])
                    ->orWhereRaw("lower(coalesce(products.category, '')) like ? escape '\\'", [$like])
                    ->when(self::hasColumn('subcategory'), fn ($q) => $q->orWhereRaw("lower(coalesce(products.subcategory, '')) like ? escape '\\'", [$like]))
                    ->when(self::hasColumn('brand'), fn ($q) => $q->orWhereRaw("lower(coalesce(products.brand, '')) like ? escape '\\'", [$like]))
                    ->orWhereExists(fn ($store) => $store->selectRaw('1')
                        ->from('seller_details')
                        ->whereColumn('seller_details.profile_id', 'products.seller_id')
                        ->whereRaw("lower(coalesce(seller_details.business_name, '')) like ? escape '\\'", [$like])));
            }

            return;
        }

        $schema = self::trigramSchema();
        $long = mb_strlen($phrase) >= self::MIN_FUZZY_LENGTH;

        $query->whereRaw(self::DOCUMENT_SQL." @@ to_tsquery('simple'::regconfig, ?)", [self::tsquery($terms)]);

        // Partial names. With pg_trgm the like is served by the trigram
        // index; without it, it's a plain scan (only for 3+ characters).
        if ($long) {
            $query->orWhereRaw("lower(products.name) like ? escape '\\'", [self::likeContains($phrase)]);
        }

        if ($schema && $long) {
            [$fuzzySql, $fuzzyBindings] = self::fuzzyNameSql($schema, 'lower(products.name)', $phrase);
            $query->orWhereRaw($fuzzySql, $fuzzyBindings);
        }

        // "= any(array(...))" rather than "in (select ...)": the matching
        // stores become one array computed up front, which the seller_id
        // index can serve as a branch of the bitmap OR. An IN sub-select
        // can't be, and would force a scan of every product.
        $sql = self::storeNameMatchSql($phrase, 'seller_details.business_name', $bindings);
        $query->orWhereRaw("products.seller_id = any(array(select seller_details.profile_id from seller_details where ({$sql})))", $bindings);
    }

    /**
     * @param  array<int, string>|null  $bindings  filled with the SQL's bindings
     */
    private static function storeNameMatchSql(string $phrase, string $column, ?array &$bindings): string
    {
        $name = "lower({$column})";
        $schema = self::trigramSchema();

        if (mb_strlen($phrase) < self::MIN_FUZZY_LENGTH) {
            $bindings = [self::likePrefix($phrase), self::likeContains(' '.$phrase)];

            return "{$name} like ? escape '\\' or {$name} like ? escape '\\'";
        }

        $bindings = [self::likeContains($phrase)];
        $sql = "{$name} like ? escape '\\'";

        if ($schema) {
            [$fuzzySql, $fuzzyBindings] = self::fuzzyNameSql($schema, $name, $phrase);
            $bindings = [...$bindings, ...$fuzzyBindings];
            $sql .= " or {$fuzzySql}";
        }

        return $sql;
    }

    /**
     * A typo-tolerant match of $phrase against $name, both served by the
     * trigram index:
     *   - a word (or run of words) in the name is close to the phrase —
     *     pg_trgm's word similarity at its default 0.6 threshold ("catfod"
     *     in "catfood treats");
     *   - or the whole name is close to it — the % operator (threshold 0.3)
     *     finds candidates, rechecked at NAME_SIMILARITY, which catches a
     *     short name with a slip ("tecshop" for "techshop", 0.55) that word
     *     similarity just misses.
     * The thresholds are applied per expression, never with SET, so nothing
     * leaks to other sessions through the connection pooler.
     *
     * @return array{0: string, 1: list<string|float>}
     */
    private static function fuzzyNameSql(string $schema, string $name, string $phrase): array
    {
        return [
            "(? OPERATOR({$schema}.<%) {$name} or ({$name} OPERATOR({$schema}.%) ? and {$schema}.similarity({$name}, ?) >= ?))",
            [$phrase, $phrase, $phrase, self::NAME_SIMILARITY],
        ];
    }

    /**
     * "cat & foo:*" — every word, the last as a prefix. Words only hold
     * letters and digits (terms()), so no tsquery syntax can get through.
     *
     * @param  list<string>  $terms
     */
    private static function tsquery(array $terms): string
    {
        $last = array_pop($terms);

        return implode(' & ', [...$terms, $last.':*']);
    }

    private static function likeContains(string $value): string
    {
        return '%'.self::escapeLike($value).'%';
    }

    private static function likePrefix(string $value): string
    {
        return self::escapeLike($value).'%';
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private static function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private static function hasColumn(string $column): bool
    {
        return DB::getSchemaBuilder()->hasColumn('products', $column);
    }
}
