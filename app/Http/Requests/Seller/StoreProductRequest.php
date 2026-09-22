<?php

namespace App\Http\Requests\Seller;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->getAttribute('role') === 'seller';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'category' => ['required', 'string', 'max:255'],
            'subcategory' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'compare_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'promo_code' => ['nullable', 'string', 'max:255'],
            'stock' => ['required', 'integer', 'min:0'],
            'images' => ['sometimes', 'array', 'max:10'],
            'images.*' => ['array:url,isNew'],
            'images.*.url' => ['required', 'string', 'max:5242880'],
            'images.*.isNew' => ['sometimes', 'boolean'],
            'brand' => ['nullable', 'string', 'max:255'],
            'condition' => ['nullable', 'string', 'max:255'],
            'dimensions' => ['nullable', 'array'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:9999999.999'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'has_variants' => ['sometimes', 'boolean'],
            'specifications' => ['nullable', 'array'],
        ];
    }
}
