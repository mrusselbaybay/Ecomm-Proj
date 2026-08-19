<?php

namespace App\Http\Requests\Seller;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'seller';
    }

    public function rules(): array
    {
        return [
            // 'New' is intentionally excluded — a seller can only move an
            // order forward (or cancel it), never reopen/reset it.
            'status' => ['required', Rule::in(['Processing', 'In Transit', 'Delivered', 'Cancelled'])],
            'reason' => ['nullable', 'required_if:status,Cancelled', 'string', 'min:3', 'max:1000'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'shipping_carrier' => ['nullable', 'string', 'max:100'],
            'shipping_service' => ['nullable', 'string', 'max:100'],
        ];
    }
}
