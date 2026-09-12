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
            'body' => ['nullable', 'required_without:attachment_ids', 'string', 'max:4000'],
            'attachment_ids' => ['nullable', 'required_without:body', 'array', 'max:5'],
            'attachment_ids.*' => ['uuid', 'distinct'],
            // Optional purchase/parcel context for this specific message
            // (e.g. a seller "inquiring about" a parcel with logistics) —
            // the controller re-checks either one actually belongs to this
            // seller before attaching it, same trust model as attachment_ids.
            'order_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
        ];
    }
}
