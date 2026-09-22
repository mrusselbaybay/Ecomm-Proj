<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/seller/messages/conversations/{id}/messages
 *
 * `attachment_ids` are ids returned by POST /messages/attachments; the
 * controller re-checks each one belongs to this seller and is still
 * unlinked before attaching it, so a stale or someone else's id is
 * ignored rather than trusted.
 *
 * A message needs a body OR at least one attachment, not necessarily
 * both — an image/file sent on its own (no caption) is a normal chat
 * message, not an invalid one.
 */
class SendSellerMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'seller';
    }

    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:4000', 'required_without:attachment_ids'],
            'attachment_ids' => ['nullable', 'array', 'max:5', 'required_without:body'],
            'attachment_ids.*' => ['uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required_without' => 'Write a message or attach a file.',
            'attachment_ids.required_without' => 'Write a message or attach a file.',
        ];
    }
}
