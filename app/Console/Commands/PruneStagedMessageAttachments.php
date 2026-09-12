<?php

namespace App\Console\Commands;

use App\Models\MessageAttachment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('messages:prune-staged-attachments {--hours=24 : Delete unlinked uploads older than this many hours}')]
#[Description('Delete abandoned staged message attachments')]
class PruneStagedMessageAttachments extends Command
{
    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $deleted = 0;

        MessageAttachment::query()
            ->whereNull('message_id')
            ->where('created_at', '<', now()->subHours($hours))
            ->eachById(function (MessageAttachment $attachment) use (&$deleted): void {
                if (! str_starts_with($attachment->url, 'data:') && ! str_starts_with($attachment->url, 'http')) {
                    Storage::disk('message_attachments')->delete($attachment->url);
                }

                $attachment->delete();
                $deleted++;
            });

        $this->info("Deleted {$deleted} abandoned message attachment(s).");

        return self::SUCCESS;
    }
}
