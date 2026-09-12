<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MessageAttachmentController extends Controller
{
    /**
     * Streams via a BinaryFileResponse (not Storage::response(), which
     * ignores Range headers) so browsers can send Range requests — required
     * for video seeking/scrubbing to work instead of replaying from 0:00.
     */
    public function show(MessageAttachment $attachment): BinaryFileResponse
    {
        abort_if(
            str_starts_with($attachment->url, 'data:') || str_starts_with($attachment->url, 'http'),
            404,
        );

        abort_unless(Storage::disk('message_attachments')->exists($attachment->url), 404);

        return response()->file(
            Storage::disk('message_attachments')->path($attachment->url),
            [
                'Content-Type' => $attachment->mime,
                'Content-Disposition' => 'inline; filename="'.addslashes($attachment->name).'"',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
