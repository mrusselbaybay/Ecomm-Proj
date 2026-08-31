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
            // order forward, cancel or reject it, never reopen/reset it.
            // The controller further enforces Order::ALLOWED_TRANSITIONS
            // from the order's current status and the cancel/reject window.
            'status' => ['required', Rule::in([
                'Confirmed', 'Processing', 'Packed', 'Ready for Pickup',
                'In Transit', 'Delivered', 'Cancelled', 'Rejected',
            ])],
            'reason' => ['nullable', 'required_if:status,Cancelled', 'required_if:status,Rejected', 'string', 'min:3', 'max:1000'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'shipping_carrier' => ['nullable', 'string', 'max:100'],
            'shipping_service' => ['nullable', 'string', 'max:100'],
        ];
    }
}
