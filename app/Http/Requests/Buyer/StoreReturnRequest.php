<?php

namespace App\Http\Requests\Buyer;

use App\Models\OrderReturnRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'buyer';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_item_id' => ['required', 'uuid'],
            'request_type' => ['required', Rule::in(OrderReturnRequest::REQUEST_TYPES)],
            'reason' => ['required', Rule::in(OrderReturnRequest::REASONS)],
            'details' => ['required', 'string', 'min:10', 'max:1000'],
            'quantity' => ['required', 'integer', 'min:1'],

            // Each entry is a public Storage URL (the normal case, a short
            // https:// string) or, when the return-evidence bucket isn't
            // set up, an inline data: URL fallback — capped at ~2MB of
            // base64 so one oversized image can't bloat the row. 1-3 images.
            'evidence' => ['required', 'array', 'min:1', 'max:3'],
            'evidence.*' => ['required', 'string', 'max:2097152'],
        ];
    }
}
