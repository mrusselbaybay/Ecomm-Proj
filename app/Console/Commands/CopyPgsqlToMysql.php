<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * One-off data move from the Supabase Postgres database into the MySQL
 * schema built by `php artisan migrate --database=mysql_target`.
 *
 * Target tables are truncated and refilled, so the command can be re-run
 * until the numbers match. FK checks are off during the copy, so table
 * order doesn't matter. Only columns present on both sides are copied
 * (MySQL-only generated columns fill themselves).
 */
class CopyPgsqlToMysql extends Command
{
    protected $signature = 'db:copy-pgsql-to-mysql
        {--source=supabase_source : Postgres connection to read from}
        {--target=mysql : MySQL connection to write to}
        {--table=* : Copy only these tables}
        {--chunk=500 : Rows per insert}';

    protected $description = 'Copy every table from Supabase Postgres into the MySQL database';

    /** Laravel bookkeeping / throwaway state that must not be copied. */
    private const SKIP = ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches'];

    public function handle(): int
    {
        $source = DB::connection($this->option('source'));
        $target = DB::connection($this->option('target'));

        if ($source->getDriverName() !== 'pgsql' || ! in_array($target->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->error('Source must be pgsql and target must be mysql.');

            return self::FAILURE;
        }

        $tables = $this->option('table') ?: collect($source->select(
            "select table_name from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE' order by 1"
        ))->pluck('table_name')->diff(self::SKIP)->values()->all();

        $target->statement('SET FOREIGN_KEY_CHECKS = 0');

        $failed = 0;

        try {
            foreach ($tables as $table) {
                try {
                    [$read, $written] = $this->copyTable($source, $target, $table);
                    $status = $read === $written ? '<info>ok</info>' : '<error>MISMATCH</error>';
                    $failed += $read === $written ? 0 : 1;
                    $this->line(sprintf('%-45s %6d -> %6d  %s', $table, $read, $written, $status));
                } catch (\Throwable $e) {
                    $failed++;
                    $this->line(sprintf('%-45s <error>FAILED</error> %s', $table, strtok($e->getMessage(), "\n")));
                }
            }
        } finally {
            $target->statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $failed ? $this->error("$failed table(s) need attention.") : $this->info('All tables copied.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{int, int} rows read from source, rows now in target */
    private function copyTable(Connection $source, Connection $target, string $table): array
    {
        $sourceColumns = collect($source->select(
            "select column_name, data_type from information_schema.columns where table_schema = 'public' and table_name = ?",
            [$table]
        ))->pluck('data_type', 'column_name');

        $targetColumns = collect($target->select(
            'select column_name as column_name from information_schema.columns where table_schema = database() and table_name = ? and extra not in (?, ?)',
            // Skip computed columns only — DEFAULT_GENERATED just means an expression default.
            [$table, 'VIRTUAL GENERATED', 'STORED GENERATED']
        ))->pluck('column_name', 'column_name');

        if ($targetColumns->isEmpty()) {
            throw new \RuntimeException('table does not exist in MySQL');
        }

        $columns = $sourceColumns->keys()->intersect($targetColumns->keys())->values()->all();
        $timestamps = $sourceColumns->filter(fn ($type) => str_starts_with($type, 'timestamp'))->keys()->all();

        $target->table($table)->truncate();

        $read = 0;
        $batch = [];
        $flush = function () use ($target, $table, &$batch) {
            if ($batch) {
                $target->table($table)->insert($batch);
                $batch = [];
            }
        };

        foreach ($source->table($table)->select($columns)->cursor() as $row) {
            $row = (array) $row;

            foreach ($timestamps as $column) {
                if (isset($row[$column])) {
                    // Postgres returns "…+00"; MySQL TIMESTAMP wants a plain UTC literal.
                    $row[$column] = CarbonImmutable::parse($row[$column])->utc()->format('Y-m-d H:i:s');
                }
            }

            $batch[] = $row;
            $read++;

            if (count($batch) >= (int) $this->option('chunk')) {
                $flush();
            }
        }

        $flush();

        return [$read, $target->table($table)->count()];
    }
}
