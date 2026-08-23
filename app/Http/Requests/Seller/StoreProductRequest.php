<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'seller';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'brand' => ['nullable', 'string', 'max:150'],
            'condition' => ['nullable', 'string', 'in:new,used,refurbished'],
            'dimensions' => ['nullable', 'array'],
            'dimensions.length' => ['nullable', 'numeric', 'min:0'],
            'dimensions.width' => ['nullable', 'numeric', 'min:0'],
            'dimensions.height' => ['nullable', 'numeric', 'min:0'],
            'dimensions.unit' => ['nullable', 'string', 'max:10'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],

            // Structural validation only — which keys/values are actually
            // allowed depends on the seller's own category, and is
            // enforced in CategoryFieldConfig::validateSpecifications()
            // (SellerProductService), not here.
            'specifications' => ['nullable', 'array'],
            'specifications.*' => ['nullable', 'string', 'max:2000'],

            'sku' => ['nullable', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0'],
            'compare_price' => ['nullable', 'numeric', 'min:0'],
            'promo_code' => ['nullable', 'string', 'max:100'],
            'stock' => ['required', 'integer', 'min:0'],
            'images' => ['nullable', 'array'],

            // Note: category and status are intentionally NOT accepted
            // here — SellerProductService always derives category from
            // the seller's own line_of_business and forces status to
            // 'pending_review', regardless of anything submitted.

            'options' => ['nullable', 'array'],
            'options.*.name' => ['required', 'string', 'max:100'],
            'options.*.values' => ['required', 'array', 'min:1'],
            'options.*.values.*' => ['required', 'string', 'max:100'],

            'variants' => ['nullable', 'array'],
            'variants.*.option_values' => ['required', 'array', 'min:1'],
            'variants.*.option_values.*' => ['required', 'string', 'max:100'],
            'variants.*.sku' => ['nullable', 'string', 'max:100'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.stock' => ['required', 'integer', 'min:0'],
            'variants.*.image' => ['nullable', 'array'],
            'variants.*.status' => ['nullable', 'string', 'in:active,unavailable'],
        ];
    }
}