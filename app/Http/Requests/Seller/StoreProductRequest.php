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
            // Structural check only — whether one is actually REQUIRED (the
            // seller's category has subcategories) and whether it's one of
            // that category's own is enforced in
            // SellerProductService::resolveSubcategory(), which needs the
            // resolved category to check against (same reason
            // specifications validation lives there and not here).
            'subcategory' => ['nullable', 'string', 'max:100'],
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

            // No 'sku' here: it's auto-generated per variant now (see
            // SellerProductService::generateVariantSku()), never
            // client-submitted.
            // Always just a cache InventoryService::syncProductPrice()/
            // syncProductStock() derive from the (now mandatory) variants
            // below — never required from the client.
            'price' => ['nullable', 'numeric', 'min:0'],
            'compare_price' => ['nullable', 'numeric', 'min:0'],
            'promo_code' => ['nullable', 'string', 'max:100'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'images' => ['nullable', 'array'],

            // Note: category and status are intentionally NOT accepted
            // here — SellerProductService always derives category from
            // the seller's own line_of_business and forces status to
            // 'pending_review', regardless of anything submitted.

            'options' => ['nullable', 'array'],
            'options.*.name' => ['required', 'string', 'max:100'],
            'options.*.values' => ['required', 'array', 'min:1'],
            'options.*.values.*' => ['required', 'string', 'max:100'],

            // Every product needs at least one variant — price/stock/
            // low-stock threshold all live per variant now, there's no
            // more product-level "simple product" path. option_values
            // MAY be empty: a product with no real option axes still gets
            // exactly one variant with no option_values ("Default (no
            // options)" in the seller UI) — that's where its price/stock
            // live instead of a removed top-level Pricing & Inventory
            // section.
            'variants' => ['required', 'array', 'min:1'],
            // 'present' not 'required': Laravel's `required` rule treats an
            // EMPTY array as absent, which would wrongly reject a "solo"
            // variant's intentionally-empty option_values. `present` just
            // means the key must exist, empty or not.
            'variants.*.option_values' => ['present', 'array'],
            'variants.*.option_values.*' => ['required', 'string', 'max:100'],
            // No 'variants.*.sku' here either — same reason as above.
            'variants.*.price' => ['required', 'numeric', 'min:0'],
            // Seller-facing promo labeling only — see the discount columns'
            // migration docblock. discount_type isn't an `in:` list: the
            // product form's curated list is suggestions with an "Other"
            // field, same as variant option values.
            'variants.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'variants.*.discount_type' => ['nullable', 'string', 'max:60'],
            'variants.*.stock' => ['required', 'integer', 'min:0'],
            'variants.*.low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'variants.*.image' => ['nullable', 'array'],
            'variants.*.status' => ['nullable', 'string', 'in:active,unavailable'],
        ];
    }
}
