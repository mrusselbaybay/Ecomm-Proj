<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Profile;
use App\Support\CategoryFieldConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SellerProductService
{
    private const EAGER = ['options.values', 'variants.optionValues.option'];

    /**
     * @param  array<string, mixed>  $data  Already-validated payload from
     *                                       StoreProductRequest.
     */
    public function create(Profile $seller, array $data): Product
    {
        return DB::transaction(function () use ($seller, $data) {
            $category = $this->resolveCategory($seller);

            $product = Product::create(array_merge(
                $this->baseAttributes($category, $data),
                [
                    'seller_id' => $seller->id,
                    'status' => 'pending_review',
                    'has_variants' => !empty($data['variants']),
                ],
            ));

            $this->syncOptionsAndVariants($product, $category, $data);

            return $product->fresh(self::EAGER);
        });
    }

    /**
     * @param  array<string, mixed>  $data  Already-validated payload from
     *                                       UpdateProductRequest.
     */
    public function update(Profile $seller, Product $product, array $data): Product
    {
        return DB::transaction(function () use ($seller, $product, $data) {
            $category = $this->resolveCategory($seller);

            $product->update(array_merge(
                $this->baseAttributes($category, $data),
                [
                    // Editing always sends the product back for review,
                    // even if it was previously 'active' — never trust a
                    // status submitted by the client.
                    'status' => 'pending_review',
                    'has_variants' => !empty($data['variants']),
                ],
            ));

            // Simplest correct way to keep options/variants in sync with
            // a fully-submitted edit form: replace them wholesale inside
            // the same transaction, rather than diffing. Cascades clean
            // up product_option_values / product_variant_option_values;
            // order_items keeps its own variant snapshot regardless (see
            // 2026_08_23_000008_add_variant_columns_to_order_items_table),
            // so past orders referencing a deleted variant are unaffected.
            $product->variants()->delete();
            $product->options()->delete();

            $this->syncOptionsAndVariants($product, $category, $data);

            return $product->fresh(self::EAGER);
        });
    }

    /**
     * Fields shared by create/update that must never come from the
     * client as-is: category is always the seller's own current
     * line_of_business, and specifications are filtered/validated
     * against that category's own template — see
     * CategoryFieldConfig::validateSpecifications(). Any key not defined
     * for the seller's category, or an invalid value for a select field,
     * is dropped or rejected regardless of what the client submitted.
     */
    private function baseAttributes(string $category, array $data): array
    {
        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'category' => $category,
            'brand' => $data['brand'] ?? null,
            'condition' => $data['condition'] ?? null,
            // Shipping-only measurements (freight calculation), never
            // shown to buyers as a product spec — see the task's
            // "pack weight vs shipping weight" distinction.
            'dimensions' => $data['dimensions'] ?? null,
            'weight' => $data['weight'] ?? null,
            'specifications' => CategoryFieldConfig::validateSpecifications(
                $category,
                $data['specifications'] ?? [],
            ),
            'low_stock_threshold' => $data['low_stock_threshold'] ?? null,
            'sku' => $data['sku'] ?? null,
            'price' => $data['price'],
            'compare_price' => $data['compare_price'] ?? null,
            'promo_code' => $data['promo_code'] ?? null,
            'stock' => max(0, (int) $data['stock']),
            'images' => $data['images'] ?? [],
        ];
    }

    private function resolveCategory(Profile $seller): string
    {
        $lineOfBusiness = $seller->sellerDetail?->line_of_business;

        if (!$lineOfBusiness) {
            throw ValidationException::withMessages([
                'category' => 'Your seller account has no registered line of business yet.',
            ]);
        }

        return $lineOfBusiness;
    }

    /**
     * Creates product_options/product_option_values from `options`, then
     * creates one product_variants row per entry in `variants`, attaching
     * each to the option values that make up its combination. Rejects
     * duplicate combinations and duplicate SKUs (both within this
     * product and against every other product's variants), and rejects
     * any option name/value that isn't part of the seller's own
     * category template (CategoryFieldConfig) — irrelevant or
     * free-typed option types/values never reach the database, no
     * matter what the client sends.
     */
    private function syncOptionsAndVariants(Product $product, string $category, array $data): void
    {
        $variantsInput = $data['variants'] ?? [];

        if (empty($variantsInput)) {
            return; // simple product — existing product price/stock apply.
        }

        $optionsInput = $data['options'] ?? [];
        $valueIdByKey = [];

        foreach ($optionsInput as $index => $opt) {
            $optionName = trim($opt['name']);

            if (!CategoryFieldConfig::isValidOptionName($category, $optionName)) {
                throw ValidationException::withMessages([
                    'options' => "\"{$optionName}\" isn't an available variant type for {$category}.",
                ]);
            }

            $allowedValues = CategoryFieldConfig::allowedValuesFor($category, $optionName);

            $option = $product->options()->create([
                'name' => $optionName,
                'position' => $index,
            ]);

            foreach (array_values(array_unique(array_map('trim', $opt['values']))) as $j => $val) {
                if ($allowedValues !== null && !in_array($val, $allowedValues, true)) {
                    throw ValidationException::withMessages([
                        'options' => "\"{$val}\" isn't a valid {$optionName} value.",
                    ]);
                }

                $optionValue = $option->values()->create([
                    'value' => $val,
                    'position' => $j,
                ]);

                $valueIdByKey[$this->comboKey($optionName, $val)] = $optionValue->id;
            }
        }

        $seenCombos = [];
        $seenSkusInProduct = [];

        foreach ($variantsInput as $variantInput) {
            $optionValues = $variantInput['option_values'];
            ksort($optionValues);
            $comboSignature = json_encode($optionValues);

            if (isset($seenCombos[$comboSignature])) {
                throw ValidationException::withMessages([
                    'variants' => 'Duplicate variant combination: ' . implode(', ', array_map(
                        fn ($k, $v) => "{$k}: {$v}",
                        array_keys($optionValues),
                        $optionValues,
                    )),
                ]);
            }
            $seenCombos[$comboSignature] = true;

            $sku = isset($variantInput['sku']) ? trim((string) $variantInput['sku']) : null;
            $sku = $sku !== '' ? $sku : null;

            if ($sku !== null) {
                $skuKey = mb_strtolower($sku);

                if (isset($seenSkusInProduct[$skuKey])) {
                    throw ValidationException::withMessages([
                        'variants' => "Duplicate SKU within this product: {$sku}",
                    ]);
                }
                $seenSkusInProduct[$skuKey] = true;

                if (ProductVariant::where('sku', $sku)->exists()) {
                    throw ValidationException::withMessages([
                        'variants' => "SKU is already in use by another variant: {$sku}",
                    ]);
                }
            }

            $variant = $product->variants()->create([
                'sku' => $sku,
                'price' => $variantInput['price'] ?? null,
                'stock' => max(0, (int) $variantInput['stock']),
                'image' => $variantInput['image'] ?? null,
                'status' => in_array($variantInput['status'] ?? null, ['active', 'unavailable'], true)
                    ? $variantInput['status']
                    : 'active',
            ]);

            $valueIds = [];

            foreach ($optionValues as $optionName => $value) {
                $key = $this->comboKey($optionName, $value);

                if (!isset($valueIdByKey[$key])) {
                    throw ValidationException::withMessages([
                        'variants' => "Option value \"{$optionName}: {$value}\" is not listed in this product's options.",
                    ]);
                }

                $valueIds[] = $valueIdByKey[$key];
            }

            $variant->optionValues()->attach($valueIds);
        }
    }

    private function comboKey(string $optionName, string $value): string
    {
        return mb_strtolower(trim($optionName)) . '::' . mb_strtolower(trim($value));
    }
}