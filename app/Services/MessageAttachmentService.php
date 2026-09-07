<?php

namespace App\Services;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MessageAttachmentService
{
    /**
     * Store a validated upload in the staging ledger until it is claimed by a message.
     */
    public function stage(Profile $uploader, UploadedFile $file): MessageAttachment
    {
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $originalName = basename($file->getClientOriginalName() ?: 'attachment');
        $safeDisplayName = trim(str_replace(["\0", "\r", "\n"], '', $originalName)) ?: 'attachment';
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $path = $file->storeAs(
            $uploader->id.'/'.now()->format('Y/m'),
            Str::uuid().'.'.$extension,
            'message_attachments',
        );

        try {
            return MessageAttachment::create([
                'seller_id' => $uploader->role === 'seller' ? $uploader->id : null,
                'uploader_id' => $uploader->id,
                'message_id' => null,
                'name' => $safeDisplayName,
                'mime' => $mime,
                'size' => $file->getSize(),
                'url' => $path,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('message_attachments')->delete($path);

            throw $exception;
        }
    }

    /**
     * @param  list<string>  $attachmentIds
     * @return Collection<int, MessageAttachment>
     */
    public function findOwnedUnlinked(Profile $uploader, array $attachmentIds): Collection
    {
        if ($attachmentIds === []) {
            return new Collection;
        }

        return MessageAttachment::query()
            ->whereIn('id', $attachmentIds)
            ->where('uploader_id', $uploader->id)
            ->whereNull('message_id')
            ->get();
    }

    /**
     * @param  Collection<int, MessageAttachment>  $attachments
     */
    public function linkToMessage(Collection $attachments, Message $message): void
    {
        if ($attachments->isEmpty()) {
            return;
        }

        MessageAttachment::query()
            ->whereKey($attachments->modelKeys())
            ->update(['message_id' => $message->id]);
    }
}
