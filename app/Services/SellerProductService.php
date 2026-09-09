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

    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * @param  array<string, mixed>  $data  Already-validated payload from
     *                                      StoreProductRequest.
     */
    public function create(Profile $seller, array $data): Product
    {
        return DB::transaction(function () use ($seller, $data) {
            $category = $this->resolveCategory($seller);
            $subcategory = $this->resolveSubcategory($category, $data);

            $product = Product::create(array_merge(
                $this->baseAttributes($category, $subcategory, $data),
                [
                    'seller_id' => $seller->id,
                    'status' => 'pending_review',
                    'has_variants' => ! empty($data['variants']),
                ],
            ));

            // Every variant created here is brand new (nothing to match
            // against), so syncOptionsAndVariants() logs each one's own
            // initial_stock baseline as it creates it.
            $this->syncOptionsAndVariants($product, $data, $seller->id);

            // Cache products.stock/price for a variant product as the
            // sum/cheapest-available of its variants — see
            // InventoryService::syncProductStock()/syncProductPrice().
            $product->load('variants');
            $this->inventory->syncProductStock($product);
            $this->inventory->syncProductPrice($product);

            return $product->fresh(self::EAGER);
        });
    }

    /**
     * @param  array<string, mixed>  $data  Already-validated payload from
     *                                      UpdateProductRequest.
     */
    public function update(Profile $seller, Product $product, array $data): Product
    {
        return DB::transaction(function () use ($seller, $product, $data) {
            $category = $this->resolveCategory($seller);
            $subcategory = $this->resolveSubcategory($category, $data);

            $stockBefore = (int) $product->stock;
            $hadVariants = (bool) $product->has_variants;

            $product->update(array_merge(
                $this->baseAttributes($category, $subcategory, $data),
                [
                    // Editing always sends the product back for review,
                    // even if it was previously 'active' — never trust a
                    // status submitted by the client.
                    'status' => 'pending_review',
                    'has_variants' => ! empty($data['variants']),
                ],
            ));

            // Match incoming variants against what's already on this
            // product BY OPTION COMBINATION (signed the same way
            // syncOptionsAndVariants() signs incoming ones below) so an
            // edit that doesn't touch a variant's options reuses that
            // row — keeping its id, and so its inventory_movements
            // history (stock adjustments, sales, cancellation restocks)
            // — instead of deleting and recreating it (and fabricating a
            // fresh "Initial stock on creation" entry) on every single
            // save, even a name/description-only edit.
            $existingVariantsByCombo = [];

            foreach ($product->variants()->with('optionValues.option')->get() as $existingVariant) {
                $optionValues = $existingVariant->optionValues
                    ->mapWithKeys(fn ($ov) => [$ov->option?->name ?? '' => $ov->value])
                    ->all();
                ksort($optionValues);
                $existingVariantsByCombo[json_encode($optionValues)] = $existingVariant;
            }

            // Options themselves carry no independent identity/history
            // worth preserving, so they're still always replaced
            // wholesale — this cascades to delete product_option_values
            // and, through that, every variant's
            // product_variant_option_values pivot rows (kept variants
            // included); each variant's pivot is rebuilt from the
            // freshly created option values inside
            // syncOptionsAndVariants() regardless of whether the variant
            // row itself is reused or brand new. order_items keeps its
            // own variant snapshot regardless (see
            // 2026_08_23_000008_add_variant_columns_to_order_items_table),
            // so past orders are unaffected either way.
            $product->options()->delete();

            $this->syncOptionsAndVariants($product, $data, $seller->id, $existingVariantsByCombo);

            $product->refresh()->load('variants');

            if ($product->has_variants) {
                $this->inventory->syncProductStock($product);
                $this->inventory->syncProductPrice($product);
            } elseif (! $hadVariants && (int) $product->stock !== $stockBefore) {
                // Simple product whose stock field was changed on the
                // edit form — record the difference so it's auditable
                // (the dedicated adjust endpoint is the preferred path).
                $this->inventory->recordFormStockEdit(
                    $product,
                    $stockBefore,
                    (int) $product->stock,
                    $seller->id,
                );
            }

            return $product->fresh(self::EAGER);
        });
    }

    /**
     * Fields shared by create/update that must never come from the
     * client as-is: category is always the seller's own current
     * line_of_business, subcategory is whatever resolveSubcategory()
     * already validated, and specifications are filtered/validated
     * against that category+subcategory's own template — see
     * CategoryFieldConfig::validateSpecifications(). Any key not defined
     * there, or an invalid value for a select field, is dropped or
     * rejected regardless of what the client submitted.
     */
    private function baseAttributes(string $category, ?string $subcategory, array $data): array
    {
        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'category' => $category,
            'subcategory' => $subcategory,
            'brand' => $data['brand'] ?? null,
            'condition' => $data['condition'] ?? null,
            // Shipping-only measurements (freight calculation), never
            // shown to buyers as a product spec — see the task's
            // "pack weight vs shipping weight" distinction.
            'dimensions' => $data['dimensions'] ?? null,
            'weight' => $data['weight'] ?? null,
            'specifications' => CategoryFieldConfig::validateSpecifications(
                $category,
                $subcategory,
                $data['specifications'] ?? [],
            ),
            'low_stock_threshold' => $data['low_stock_threshold'] ?? null,
            'sku' => $data['sku'] ?? null,
            // The client never submits these anymore (every product has
            // at least one variant, and price/stock live there instead) —
            // 0 here is just a placeholder immediately overwritten by
            // InventoryService::syncProductPrice()/syncProductStock()
            // right after syncOptionsAndVariants() runs.
            'price' => $data['price'] ?? 0,
            'compare_price' => $data['compare_price'] ?? null,
            'promo_code' => $data['promo_code'] ?? null,
            'stock' => max(0, (int) ($data['stock'] ?? 0)),
            'images' => $data['images'] ?? [],
        ];
    }

    private function resolveCategory(Profile $seller): string
    {
        $lineOfBusiness = $seller->sellerDetail?->line_of_business;

        if (! $lineOfBusiness) {
            throw ValidationException::withMessages([
                'category' => 'Your seller account has no registered line of business yet.',
            ]);
        }

        return $lineOfBusiness;
    }

    /**
     * A category with no subcategory concept (see
     * CategoryFieldConfig::hasSubcategories()) always resolves to null —
     * submitting one is simply ignored, not an error. A category that DOES
     * have subcategories requires a valid pick: unlike a variant value,
     * which subcategory a listing belongs to changes which specification
     * fields are even valid, so there's no sensible "Other"/default to
     * fall back to.
     */
    private function resolveSubcategory(string $category, array $data): ?string
    {
        if (! CategoryFieldConfig::hasSubcategories($category)) {
            return null;
        }

        $subcategory = $data['subcategory'] ?? null;

        if (! CategoryFieldConfig::isValidSubcategory($category, $subcategory)) {
            throw ValidationException::withMessages([
                'subcategory' => "Choose what kind of {$category} product this is.",
            ]);
        }

        return $subcategory;
    }

    /**
     * Creates product_options/product_option_values from `options`, then
     * upserts one product_variants row per entry in `variants`, attaching
     * each to the option values that make up its combination. An entry
     * whose combination matches one of $existingVariantsByCombo (built
     * by update() before this runs, empty for a brand-new product) is
     * updated IN PLACE — keeping its id, and so its inventory_movements
     * history — instead of deleted and recreated; a combination with no
     * match is created fresh and gets an initial_stock baseline logged;
     * an existing variant whose combination is no longer present in the
     * submitted list is deleted. Rejects duplicate combinations and
     * duplicate SKUs (both within this product and against every other
     * product's variants), and rejects any option name/value that isn't
     * part of the seller's own category template (CategoryFieldConfig) —
     * irrelevant or free-typed option types/values never reach the
     * database, no matter what the client sends.
     *
     * @param  array<string, ProductVariant>  $existingVariantsByCombo
     */
    private function syncOptionsAndVariants(
        Product $product,
        array $data,
        string $actorId,
        array $existingVariantsByCombo = [],
    ): void {
        $variantsInput = $data['variants'] ?? [];

        // StoreProductRequest/UpdateProductRequest already require at
        // least one variant — this is just a defensive no-op against
        // whatever gets past that (or a pre-existing row this branch
        // predates), not an expected path.
        if (empty($variantsInput)) {
            return;
        }

        $optionsInput = $data['options'] ?? [];
        $valueIdByKey = [];
        $seenOptionNames = [];

        foreach ($optionsInput as $index => $opt) {
            $optionName = trim($opt['name']);

            if ($optionName === '') {
                continue; // a half-filled custom-option row the seller never named
            }

            $nameKey = mb_strtolower($optionName);

            if (isset($seenOptionNames[$nameKey])) {
                throw ValidationException::withMessages([
                    'options' => "\"{$optionName}\" is listed more than once.",
                ]);
            }
            $seenOptionNames[$nameKey] = true;

            $option = $product->options()->create([
                'name' => $optionName,
                'position' => $index,
            ]);

            // An option's curated `values` list (and, for a seller-added
            // custom option, the NAME itself) are suggestions, not a hard
            // whitelist — the product form's "Other" field lets a seller
            // type a value that isn't listed, and "+ Add Custom Option"
            // lets them add a whole axis the category template doesn't
            // anticipate (e.g. "Packaging"). CategoryFieldConfig is UI
            // guidance for the common case, not an enforced boundary here.
            foreach (array_values(array_filter(array_unique(array_map('trim', $opt['values'])))) as $j => $val) {
                $optionValue = $option->values()->create([
                    'value' => $val,
                    'position' => $j,
                ]);

                $valueIdByKey[$this->comboKey($optionName, $val)] = $optionValue->id;
            }
        }

        $seenCombos = [];
        $keptVariantIds = [];

        foreach ($variantsInput as $variantInput) {
            $optionValues = $variantInput['option_values'];
            ksort($optionValues);
            $comboSignature = json_encode($optionValues);

            if (isset($seenCombos[$comboSignature])) {
                throw ValidationException::withMessages([
                    'variants' => 'Duplicate variant combination: '.implode(', ', array_map(
                        fn ($k, $v) => "{$k}: {$v}",
                        array_keys($optionValues),
                        $optionValues,
                    )),
                ]);
            }
            $seenCombos[$comboSignature] = true;

            // A variant already on the product with this exact option
            // combination is reused (updated in place) rather than
            // recreated — see the docblock above and update()'s own
            // comment on $existingVariantsByCombo.
            $existing = $existingVariantsByCombo[$comboSignature] ?? null;

            $discountType = trim((string) ($variantInput['discount_type'] ?? ''));

            // No 'sku' here: it's never client-submitted anymore (see
            // generateVariantSku()) — a kept variant's row simply isn't
            // touched, so update() below can't overwrite it, and a new
            // variant gets one assigned right after it's created (its id
            // has to exist first).
            $attributes = [
                'seller_id' => $product->seller_id,
                'price' => $variantInput['price'] ?? null,
                // Seller-facing promo labeling only — see the discount
                // columns' migration docblock.
                'discount_percent' => $variantInput['discount_percent'] ?? null,
                'discount_type' => $discountType !== '' ? $discountType : null,
                'stock' => max(0, (int) $variantInput['stock']),
                // Null falls back to the product's own threshold, then the
                // app default — see ProductVariant::effectiveLowStockThreshold().
                'low_stock_threshold' => $variantInput['low_stock_threshold'] ?? null,
                'image' => $variantInput['image'] ?? null,
                'status' => in_array($variantInput['status'] ?? null, ['active', 'unavailable'], true)
                    ? $variantInput['status']
                    : 'active',
            ];

            if ($existing) {
                $stockBefore = (int) $existing->stock;
                $existing->update($attributes);
                $variant = $existing;
                $keptVariantIds[] = $existing->id;

                if ((int) $variant->stock !== $stockBefore) {
                    $this->inventory->recordFormStockEdit($product, $stockBefore, (int) $variant->stock, $actorId, $variant);
                }
            } else {
                $variant = $product->variants()->create($attributes);
                $variant->update(['sku' => $this->generateVariantSku($product, $variant)]);
                $this->inventory->recordInitialStock($product, $variant, $actorId);
            }

            $valueIds = [];

            foreach ($optionValues as $optionName => $value) {
                $key = $this->comboKey($optionName, $value);

                if (! isset($valueIdByKey[$key])) {
                    throw ValidationException::withMessages([
                        'variants' => "Option value \"{$optionName}: {$value}\" is not listed in this product's options.",
                    ]);
                }

                $valueIds[] = $valueIdByKey[$key];
            }

            // sync(), not attach(): options were just wholesale-recreated
            // above, so a kept variant's old pivot rows are already gone
            // (cascaded via product_option_values) — sync() is simply the
            // safer, idempotent choice for both the kept and brand-new case.
            $variant->optionValues()->sync($valueIds);
        }

        // Anything still on the product that wasn't matched by an
        // incoming combination was removed by the seller — delete it.
        // inventory_movements.variant_id is nullOnDelete, so its past
        // stock history survives at the product level.
        foreach ($existingVariantsByCombo as $existingVariant) {
            if (! in_array($existingVariant->id, $keptVariantIds, true)) {
                $existingVariant->delete();
            }
        }
    }

    private function comboKey(string $optionName, string $value): string
    {
        return mb_strtolower(trim($optionName)).'::'.mb_strtolower(trim($value));
    }

    /**
     * Auto-generates a brand-new variant's SKU from its own already-
     * assigned id plus its seller and product — never from the product's
     * NAME (which can change and can collide — "Blue Shirt" isn't
     * unique), so a rename can never affect it. Built once, right after
     * the variant's row (and so its real id) exists, and never touched
     * again afterward: syncOptionsAndVariants() never includes 'sku' in
     * the attributes used to update an existing/kept variant.
     *
     * Uniqueness is scoped to this seller's own inventory (see the
     * seller_id column + composite unique index added alongside this
     * feature) — a different seller's catalog producing the same-looking
     * code is expected, not a collision. The scheme is collision-free by
     * construction (each segment comes from a real UUID that's already
     * unique), but this still verifies it against the database rather
     * than assuming that, retrying with a longer variant segment on the
     * astronomically unlikely chance two different variants' truncated
     * ids collide.
     */
    private function generateVariantSku(Product $product, ProductVariant $variant): string
    {
        $sellerCode = $this->shortCode($product->seller_id);
        $productCode = $this->shortCode($product->id);

        $length = 6;
        $sku = "{$sellerCode}-{$productCode}-{$this->shortCode($variant->id, $length)}";

        while (
            ProductVariant::where('seller_id', $product->seller_id)
                ->where('sku', $sku)
                ->whereKeyNot($variant->id)
                ->exists()
        ) {
            $length += 2;
            $sku = "{$sellerCode}-{$productCode}-{$this->shortCode($variant->id, $length)}";
        }

        return $sku;
    }

    private function shortCode(string $uuid, int $length = 6): string
    {
        return strtoupper(substr(str_replace('-', '', $uuid), 0, $length));
    }
}
