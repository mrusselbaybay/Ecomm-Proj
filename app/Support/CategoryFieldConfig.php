<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Category templates keyed by the exact line_of_business values found in
 * seller_details (see the CHECK constraint on that column):
 *   Pet Supplies, Kids and Baby, Electronics and Gadgets, House and
 *   Garden, Woman's Apparel, Men's Apparel, Sports and Outdoors,
 *   Health and Beauty.
 *
 * Each template has two parts:
 *   - specifications: customer-facing product details shown on the
 *     Buyer product page (NOT shipping measurements — see `dimensions`/
 *     `weight` on products, which stay shipping-only per the task).
 *   - variant_options: the option "types" (e.g. Color, Size) a seller in
 *     that category is allowed to build variants from. Each option
 *     either has a controlled `values` list (buyer/seller pick from it,
 *     never free text) or is marked `free_text` => true for the rare
 *     fields where no finite list makes sense (e.g. a model number).
 *
 * A field/option/value not present here for the seller's own category is
 * never trusted or persisted, regardless of what the client sends — see
 * SellerProductService::validateSpecifications()/validateVariantOption().
 */
class CategoryFieldConfig
{
    // ---- reusable controlled value lists (avoid repeating the same
    // list literal across categories that share it) ----

    private const COLORS = [
        'Black', 'White', 'Gray', 'Red', 'Blue', 'Green', 'Yellow',
        'Orange', 'Purple', 'Pink', 'Brown', 'Beige', 'Navy', 'Multicolor',
    ];

    private const APPAREL_SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'];

    private const KIDS_SIZES = [
        'Newborn', '0-3M', '3-6M', '6-12M', '12-18M', '18-24M',
        '2T', '3T', '4T', '5T', '6', '7', '8',
    ];

    private const MATERIALS = [
        'Cotton', 'Polyester', 'Wool', 'Leather', 'Denim', 'Linen',
        'Silk', 'Nylon', 'Wood', 'Metal', 'Plastic', 'Glass', 'Rattan', 'Other',
    ];

    private const PACK_WEIGHTS = ['100g', '250g', '500g', '1kg', '2kg', '5kg', '10kg', '20kg'];

    private const VOLUMES = ['30ml', '50ml', '100ml', '150ml', '200ml', '250ml', '500ml', '1L'];

    /**
     * @return array{specifications: array<int, array<string, mixed>>, variant_options: array<int, array<string, mixed>>}
     */
    public static function for(string $category): array
    {
        return self::templates()[$category] ?? self::genericTemplate();
    }

    /** @return array<int, string> Every category this config recognizes. */
    public static function categories(): array
    {
        return array_keys(self::templates());
    }

    public static function specificationFields(string $category): array
    {
        return self::for($category)['specifications'];
    }

    public static function variantOptionDefs(string $category): array
    {
        return self::for($category)['variant_options'];
    }

    /**
     * Validates/cleans a submitted specifications array against the
     * seller's own category template: unknown keys are dropped silently
     * (never persisted), select fields must match one of their allowed
     * options, everything else is cast to a trimmed string. Never
     * throws for missing/empty fields — specifications are optional.
     */
    public static function validateSpecifications(string $category, array $submitted): array
    {
        $clean = [];

        foreach (self::specificationFields($category) as $field) {
            $value = $submitted[$field['key']] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if ($field['type'] === 'select') {
                if (!in_array($value, $field['options'], true)) {
                    throw ValidationException::withMessages([
                        'specifications' => "Invalid value for \"{$field['label']}\".",
                    ]);
                }
                $clean[$field['key']] = $value;
            } else {
                $clean[$field['key']] = trim((string) $value);
            }
        }

        return $clean;
    }

    /**
     * Human-readable {label: value} pairs for the buyer product page,
     * built from the same template so labels never drift from what the
     * seller form used to collect them.
     */
    public static function labelSpecifications(string $category, ?array $specifications): array
    {
        if (empty($specifications)) {
            return [];
        }

        $labels = [];

        foreach (self::specificationFields($category) as $field) {
            if (!empty($specifications[$field['key']])) {
                $labels[$field['label']] = $specifications[$field['key']];
            }
        }

        return $labels;
    }

    public static function isValidOptionName(string $category, string $name): bool
    {
        foreach (self::variantOptionDefs($category) as $opt) {
            if (mb_strtolower($opt['name']) === mb_strtolower(trim($name))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Null return means the option is free-text (any non-empty value is
     * fine); an array return is the exact controlled list the value must
     * belong to.
     */
    public static function allowedValuesFor(string $category, string $name): ?array
    {
        foreach (self::variantOptionDefs($category) as $opt) {
            if (mb_strtolower($opt['name']) === mb_strtolower(trim($name))) {
                return $opt['free_text'] ?? false ? null : $opt['values'];
            }
        }

        return null;
    }

    private static function genericTemplate(): array
    {
        return [
            'specifications' => [
                ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
            ],
            'variant_options' => [
                ['name' => 'Color', 'values' => self::COLORS],
            ],
        ];
    }

    private static function templates(): array
    {
        $apparel = [
            'specifications' => [
                ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                ['key' => 'fit', 'label' => 'Fit', 'type' => 'select', 'options' => ['Slim', 'Regular', 'Loose', 'Oversized']],
                ['key' => 'care_instructions', 'label' => 'Care Instructions', 'type' => 'textarea'],
            ],
            'variant_options' => [
                ['name' => 'Color', 'values' => self::COLORS],
                ['name' => 'Size', 'values' => self::APPAREL_SIZES],
                ['name' => 'Material', 'values' => self::MATERIALS],
            ],
        ];

        return [
            "Woman's Apparel" => $apparel,
            "Men's Apparel" => $apparel,

            'Pet Supplies' => [
                'specifications' => [
                    ['key' => 'animal_type', 'label' => 'Animal Type', 'type' => 'select', 'options' => ['Dog', 'Cat', 'Bird', 'Fish', 'Small Pet', 'Other']],
                    ['key' => 'food_type', 'label' => 'Food Type', 'type' => 'select', 'options' => ['Dry Food', 'Wet Food', 'Treats', 'Toy', 'Accessory', 'Other']],
                    ['key' => 'ingredients', 'label' => 'Ingredients', 'type' => 'textarea'],
                    ['key' => 'feeding_instructions', 'label' => 'Feeding Instructions', 'type' => 'textarea'],
                    ['key' => 'expiration_date', 'label' => 'Expiration Date', 'type' => 'date'],
                ],
                'variant_options' => [
                    ['name' => 'Flavor', 'values' => ['Chicken', 'Beef', 'Salmon', 'Tuna', 'Lamb', 'Duck', 'Mixed', 'Unflavored']],
                    ['name' => 'Pack Weight', 'values' => self::PACK_WEIGHTS],
                    ['name' => 'Life Stage', 'values' => ['Puppy/Kitten', 'Adult', 'Senior', 'All Life Stages']],
                ],
            ],

            'Electronics and Gadgets' => [
                'specifications' => [
                    ['key' => 'model', 'label' => 'Model', 'type' => 'text'],
                    ['key' => 'connectivity', 'label' => 'Connectivity', 'type' => 'select', 'options' => ['Wired', 'Bluetooth', 'Wi-Fi', 'USB-C', 'NFC', 'Other']],
                    ['key' => 'voltage', 'label' => 'Voltage', 'type' => 'text'],
                    ['key' => 'wattage', 'label' => 'Wattage', 'type' => 'text'],
                    ['key' => 'warranty', 'label' => 'Warranty', 'type' => 'text'],
                ],
                'variant_options' => [
                    ['name' => 'Color', 'values' => self::COLORS],
                    ['name' => 'Storage Capacity', 'values' => ['32GB', '64GB', '128GB', '256GB', '512GB', '1TB', '2TB']],
                    ['name' => 'Model', 'free_text' => true],
                ],
            ],

            'House and Garden' => [
                'specifications' => [
                    ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                    ['key' => 'room_type', 'label' => 'Room Type', 'type' => 'select', 'options' => ['Living Room', 'Bedroom', 'Kitchen', 'Bathroom', 'Garden', 'Office', 'Other']],
                    ['key' => 'assembly_required', 'label' => 'Assembly Required', 'type' => 'select', 'options' => ['Yes', 'No']],
                ],
                'variant_options' => [
                    ['name' => 'Color', 'values' => self::COLORS],
                    ['name' => 'Material', 'values' => self::MATERIALS],
                    ['name' => 'Size', 'values' => ['Small', 'Medium', 'Large', 'Custom']],
                ],
            ],

            'Health and Beauty' => [
                'specifications' => [
                    ['key' => 'skin_type', 'label' => 'Skin Type', 'type' => 'select', 'options' => ['Normal', 'Oily', 'Dry', 'Combination', 'Sensitive', 'All Skin Types']],
                    ['key' => 'ingredients', 'label' => 'Ingredients', 'type' => 'textarea'],
                    ['key' => 'directions', 'label' => 'Directions', 'type' => 'textarea'],
                ],
                'variant_options' => [
                    ['name' => 'Shade', 'free_text' => true],
                    ['name' => 'Scent', 'values' => ['Unscented', 'Floral', 'Fresh', 'Citrus', 'Woody', 'Fruity', 'Other']],
                    ['name' => 'Volume', 'values' => self::VOLUMES],
                ],
            ],

            'Kids and Baby' => [
                'specifications' => [
                    ['key' => 'age_range', 'label' => 'Age Range', 'type' => 'select', 'options' => ['0-6 months', '6-12 months', '1-2 years', '3-5 years', '6-8 years', '9-12 years', 'All Ages']],
                    ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                    ['key' => 'safety_certification', 'label' => 'Safety Certification', 'type' => 'text'],
                ],
                'variant_options' => [
                    ['name' => 'Color', 'values' => self::COLORS],
                    ['name' => 'Size', 'values' => self::KIDS_SIZES],
                ],
            ],

            'Sports and Outdoors' => [
                'specifications' => [
                    ['key' => 'activity_type', 'label' => 'Activity Type', 'type' => 'select', 'options' => ['Running', 'Gym', 'Camping', 'Cycling', 'Swimming', 'Team Sports', 'Outdoor', 'Other']],
                    ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                ],
                'variant_options' => [
                    ['name' => 'Color', 'values' => self::COLORS],
                    ['name' => 'Size', 'values' => array_merge(self::APPAREL_SIZES, ['One Size'])],
                    ['name' => 'Capacity', 'values' => ['1L', '2L', '5L', '10L', '20L', '50L']],
                ],
            ],
        ];
    }
}