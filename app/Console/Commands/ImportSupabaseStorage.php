<?php

namespace App\Console\Commands;

use App\Services\FileStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * One-time copy of every Supabase Storage object onto this server's disks,
 * then rewrites stored Supabase file URLs to the local equivalents.
 * Resumable: files already copied are skipped unless --force.
 */
class ImportSupabaseStorage extends Command
{
    protected $signature = 'storage:import-supabase {--source=supabase_source} {--force : Re-download files that already exist}';

    protected $description = 'Copy Supabase Storage files to local disks and rewrite stored URLs';

    public function handle(FileStorage $files): int
    {
        $baseUrl = rtrim((string) config('services.supabase.url'), '/');
        $key = (string) config('services.supabase.service_role_key');

        if ($baseUrl === '' || $key === '') {
            $this->error('SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY are needed for the import.');

            return self::FAILURE;
        }

        $objects = DB::connection($this->option('source'))
            ->select("select bucket_id, name from storage.objects where name not like '%.emptyFolderPlaceholder' order by bucket_id, name");

        $copied = $skipped = $failed = 0;
        $bar = $this->output->createProgressBar(count($objects));

        foreach ($objects as $object) {
            [$disk, $diskPath] = $this->destination($files, $object->bucket_id, $object->name);

            if (! $this->option('force') && Storage::disk($disk)->exists($diskPath)) {
                $skipped++;
                $bar->advance();

                continue;
            }

            $encoded = implode('/', array_map('rawurlencode', explode('/', $object->name)));
            $response = Http::withHeaders(['apikey' => $key, 'Authorization' => "Bearer {$key}"])
                ->timeout(120)
                ->get("{$baseUrl}/storage/v1/object/{$object->bucket_id}/{$encoded}");

            if ($response->successful() && Storage::disk($disk)->put($diskPath, $response->body())) {
                $copied++;
            } else {
                $failed++;
                $this->newLine();
                $this->warn("Failed: {$object->bucket_id}/{$object->name} (HTTP {$response->status()})");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Files: {$copied} copied, {$skipped} already present, {$failed} failed.");

        $this->rewriteUrls($baseUrl);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{0: string, 1: string} disk name + path on it */
    private function destination(FileStorage $files, string $bucket, string $name): array
    {
        // Chat attachments have their own disk (see MessageAttachmentService).
        if ($bucket === 'message-attachments') {
            return ['message_attachments', $name];
        }

        return [$files->isPublic($bucket) ? 'public' : 'local', $files->key($bucket, $name)];
    }

    /**
     * Stored links: public-bucket URLs become /storage/..., chat attachment
     * URLs become disk paths (served through signed routes).
     */
    private function rewriteUrls(string $baseUrl): void
    {
        $publicPrefix = $baseUrl.'/storage/v1/object/public/';
        $replacements = [
            $publicPrefix.'message-attachments/' => '',
            $publicPrefix => '/storage/',
        ];

        $columns = DB::select(
            "select c.table_name as t, c.column_name as c from information_schema.columns c
             join information_schema.tables t on t.table_schema = c.table_schema and t.table_name = c.table_name
             where c.table_schema = database() and t.table_type = 'BASE TABLE'
               and c.data_type in ('json','text','mediumtext','longtext','varchar')
               and c.extra not in ('VIRTUAL GENERATED', 'STORED GENERATED')"
        );

        $rows = 0;

        foreach ($columns as $column) {
            foreach ($replacements as $from => $to) {
                $rows += DB::table($column->t)
                    ->where($column->c, 'like', '%'.$from.'%')
                    ->update([$column->c => DB::raw('REPLACE(`'.$column->c.'`, '.DB::getPdo()->quote($from).', '.DB::getPdo()->quote($to).')')]);
            }
        }

        $this->info("Rewrote stored file URLs in {$rows} rows.");
    }
}
