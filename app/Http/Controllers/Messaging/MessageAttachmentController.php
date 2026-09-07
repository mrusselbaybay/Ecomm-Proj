<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageAttachmentController extends Controller
{
    public function show(MessageAttachment $attachment): StreamedResponse
    {
        abort_if(
            str_starts_with($attachment->url, 'data:') || str_starts_with($attachment->url, 'http'),
            404,
        );

        abort_unless(Storage::disk('message_attachments')->exists($attachment->url), 404);

        return Storage::disk('message_attachments')->response(
            $attachment->url,
            $attachment->name,
            [
                'Content-Type' => $attachment->mime,
                'Content-Disposition' => 'inline; filename="'.addslashes($attachment->name).'"',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
