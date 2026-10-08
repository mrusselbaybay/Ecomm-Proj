<?php

namespace App\Console\Commands;

use App\Models\ConsentRecord;
use App\Models\Message;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('compliance:prune-retained-data {--dry-run : Report eligible rows without deleting them}')]
#[Description('Apply the configured chat and cookie-consent retention periods')]
class PruneComplianceData extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $messages = Message::withTrashed()->where('created_at', '<', now()->subMonths(12));
        $consents = ConsentRecord::query()->where('created_at', '<', now()->subMonths(24));
        $messageCount = $messages->count();
        $consentCount = $consents->count();

        if (! $this->option('dry-run')) {
            $messages->forceDelete();
            $consents->delete();
        }

        $mode = $this->option('dry-run') ? 'eligible' : 'deleted';
        $this->info("Chat messages {$mode}: {$messageCount}");
        $this->info("Cookie consent records {$mode}: {$consentCount}");

        return self::SUCCESS;
    }
}
