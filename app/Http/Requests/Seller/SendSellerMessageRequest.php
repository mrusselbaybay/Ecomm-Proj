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
            // A "pure card" message (the parcel-inquiry picker) has neither
            // body nor attachments — order_id is enough content on its own,
            // so it counts alongside attachment_ids as satisfying the other.
            'body' => ['nullable', 'required_without_all:attachment_ids,order_id', 'string', 'max:4000'],
            'attachment_ids' => ['nullable', 'required_without_all:body,order_id', 'array', 'max:5'],
            'attachment_ids.*' => ['uuid', 'distinct'],
            // Optional purchase/parcel context for this specific message
            // (e.g. a seller "inquiring about" a parcel with logistics) —
            // the controller re-checks either one actually belongs to this
            // seller before attaching it, same trust model as attachment_ids.
            'order_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
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
