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
 * A category is either:
 *   - flat: `['specifications' => [...], 'variant_options' => [...]]`
 *     directly, used by every product in that line of business, or
 *   - subcategorised: `['subcategories' => ['Toys' => [...], 'Food &
 *     Treats' => [...], ...]]`, where each subcategory has its own
 *     specifications/variant_options. This exists because a single line
 *     of business can cover very different products — a "Pet Supplies"
 *     seller might list dog TOYS (Size/Color/Material) on one listing
 *     and FOOD (Pack Weight/Flavor) on another; one flat template can't
 *     fit both without offering irrelevant fields on each. Unlike
 *     line_of_business (fixed per seller account), subcategory is picked
 *     PER PRODUCT (products.subcategory) since one seller plausibly
 *     lists across several of their line's subcategories. See
 *     hasSubcategories()/subcategoriesFor()/isValidSubcategory().
 *
 * Each specifications/variant_options pair:
 *   - specifications: customer-facing product details shown on the
 *     Buyer product page (NOT shipping measurements — see `dimensions`/
 *     `weight` on products, which stay shipping-only per the task).
 *   - variant_options: the option "types" (e.g. Color, Size) a seller in
 *     that category/subcategory is allowed to build variants from. A
 *     seller builds variants one at a time in the product form: pick a
 *     single value per option, then "Add Variant"; repeat for each
 *     combination they stock. `values` is the suggested list shown as
 *     radio choices for that option — every option's UI also offers an
 *     "Other" field to type a value that isn't listed, so this is
 *     guidance, not a hard whitelist. Omitting `values` entirely (e.g. a
 *     model number, where no finite list makes sense) just means the
 *     option starts with no suggestions, "Other" only. An optional
 *     `hint` string is shown under the option's label in the product
 *     form.
 *
 * A specifications FIELD KEY not present here for the seller's own
 * category/subcategory is dropped silently, never persisted — see
 * SellerProductService::validateSpecifications(). Variant option names
 * and values are NOT similarly restricted to this template: they're the
 * suggested/default set the product form renders, but "Other" (values)
 * and "+ Add Custom Option" (names) let a seller add one that isn't
 * here — see SellerProductService::syncOptionsAndVariants(). Both are
 * still trimmed and length-capped by StoreProductRequest/
 * UpdateProductRequest. A category's SUBCATEGORY, when it has any, IS a
 * hard whitelist (isValidSubcategory()) — unlike a variant value, which
 * subcategory a listing belongs to changes which spec fields are even
 * valid, so it can't be left to an "Other" escape hatch.
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

    private const PET_SIZES = ['Small', 'Medium', 'Large', 'X-Large'];

    private const SHOE_SIZES = ['5', '6', '7', '8', '9', '10', '11', '12'];

    private const HOME_SIZES = ['Small', 'Medium', 'Large', 'Custom'];

    /**
     * @return array{specifications: array<int, array<string, mixed>>, variant_options: array<int, array<string, mixed>>}
     */
    public static function for(string $category, ?string $subcategory = null): array
    {
        $entry = self::templates()[$category] ?? null;

        if ($entry === null) {
            return self::genericTemplate();
        }

        if (isset($entry['subcategories'])) {
            // No/invalid subcategory: an empty shell rather than throwing —
            // callers that haven't gotten a subcategory pick yet (e.g. the
            // category-config endpoint, before the seller has chosen one)
            // just render nothing until they do. The actual whitelist
            // enforcement for product creation lives in isValidSubcategory().
            return ($subcategory !== null ? $entry['subcategories'][$subcategory] ?? null : null)
                ?? ['specifications' => [], 'variant_options' => []];
        }

        return $entry;
    }

    /** @return array<int, string> Every category this config recognizes. */
    public static function categories(): array
    {
        return array_keys(self::templates());
    }

    public static function hasSubcategories(string $category): bool
    {
        return isset(self::templates()[$category]['subcategories']);
    }

    /** @return array<int, string> Subcategory names for a category, or [] if it doesn't use them. */
    public static function subcategoriesFor(string $category): array
    {
        return array_keys(self::templates()[$category]['subcategories'] ?? []);
    }

    /** A category with no subcategory concept trivially accepts a null/empty one. */
    public static function isValidSubcategory(string $category, ?string $subcategory): bool
    {
        if (! self::hasSubcategories($category)) {
            return true;
        }

        return $subcategory !== null && in_array($subcategory, self::subcategoriesFor($category), true);
    }

    public static function specificationFields(string $category, ?string $subcategory = null): array
    {
        return self::for($category, $subcategory)['specifications'];
    }

    public static function variantOptionDefs(string $category, ?string $subcategory = null): array
    {
        return self::for($category, $subcategory)['variant_options'];
    }

    /**
     * Validates/cleans a submitted specifications array against the
     * seller's own category (+ subcategory, when the category has any)
     * template: unknown keys are dropped silently (never persisted),
     * select fields must match one of their allowed options, everything
     * else is cast to a trimmed string. Never throws for missing/empty
     * fields — specifications are optional.
     */
    public static function validateSpecifications(string $category, ?string $subcategory, array $submitted): array
    {
        $clean = [];

        foreach (self::specificationFields($category, $subcategory) as $field) {
            $value = $submitted[$field['key']] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if ($field['type'] === 'select') {
                if (! in_array($value, $field['options'], true)) {
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
    public static function labelSpecifications(string $category, ?string $subcategory, ?array $specifications): array
    {
        if (empty($specifications)) {
            return [];
        }

        $labels = [];

        foreach (self::specificationFields($category, $subcategory) as $field) {
            if (! empty($specifications[$field['key']])) {
                $labels[$field['label']] = $specifications[$field['key']];
            }
        }

        return $labels;
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

    /**
     * Shared by Woman's/Men's Apparel — same product shapes (a top is a
     * top regardless of who it's for), minus Dresses for menswear. Fit/
     * Size/Material genuinely differ by garment type (a shoe doesn't have
     * a textile "Fit", a bag doesn't have a clothing Size), which is
     * exactly why apparel needed subcategories at all rather than one
     * flat "Color/Size/Material" template covering everything from
     * jackets to belts.
     */
    private static function apparelSubcategories(bool $includeDresses): array
    {
        $clothingSpecs = [
            ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
            ['key' => 'fit', 'label' => 'Fit', 'type' => 'select', 'options' => ['Slim', 'Regular', 'Loose', 'Oversized']],
            ['key' => 'care_instructions', 'label' => 'Care Instructions', 'type' => 'textarea'],
        ];
        $clothingVariants = [
            ['name' => 'Color', 'values' => self::COLORS],
            ['name' => 'Size', 'values' => self::APPAREL_SIZES],
            ['name' => 'Material', 'values' => self::MATERIALS],
        ];

        $subcategories = [
            'Tops' => ['specifications' => $clothingSpecs, 'variant_options' => $clothingVariants],

            'Dresses' => [
                'specifications' => [
                    ...$clothingSpecs,
                    ['key' => 'occasion', 'label' => 'Occasion', 'type' => 'select', 'options' => ['Casual', 'Formal', 'Party', 'Work', 'Other']],
                ],
                'variant_options' => $clothingVariants,
            ],

            'Bottoms' => ['specifications' => $clothingSpecs, 'variant_options' => $clothingVariants],

            'Outerwear' => ['specifications' => $clothingSpecs, 'variant_options' => $clothingVariants],

            'Footwear' => [
                'specifications' => [
                    ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                    ['key' => 'care_instructions', 'label' => 'Care Instructions', 'type' => 'textarea'],
                ],
                'variant_options' => [
                    ['name' => 'Color', 'values' => self::COLORS],
                    ['name' => 'Size', 'values' => self::SHOE_SIZES],
                    ['name' => 'Material', 'values' => self::MATERIALS],
                ],
            ],

            'Accessories' => [
                'specifications' => [
                    ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                ],
                'variant_options' => [
                    ['name' => 'Color', 'values' => self::COLORS],
                    ['name' => 'Material', 'values' => self::MATERIALS],
                ],
            ],
        ];

        if (! $includeDresses) {
            unset($subcategories['Dresses']);
        }

        return $subcategories;
    }

    private static function templates(): array
    {
        return [
            "Woman's Apparel" => ['subcategories' => self::apparelSubcategories(includeDresses: true)],
            "Men's Apparel" => ['subcategories' => self::apparelSubcategories(includeDresses: false)],

            // Pet Supplies covers very different products — food, toys,
            // accessories, grooming, housing — that don't share a sane
            // set of variant options (a squeaky toy has no "Flavor"; a
            // bag of treats has no "Color"). Split per-product via
            // products.subcategory instead of one flat template. See the
            // class docblock and CategoryConfigController.
            'Pet Supplies' => [
                'subcategories' => [
                    'Food & Treats' => [
                        'specifications' => [
                            ['key' => 'animal_type', 'label' => 'Animal Type', 'type' => 'select', 'options' => ['Dog', 'Cat', 'Bird', 'Fish', 'Small Pet', 'Other']],
                            ['key' => 'food_type', 'label' => 'Food Type', 'type' => 'select', 'options' => ['Dry Food', 'Wet Food', 'Treats', 'Supplement', 'Other']],
                            ['key' => 'ingredients', 'label' => 'Ingredients', 'type' => 'textarea'],
                            ['key' => 'feeding_instructions', 'label' => 'Feeding Instructions', 'type' => 'textarea'],
                            ['key' => 'expiration_date', 'label' => 'Expiration Date', 'type' => 'date'],
                        ],
                        'variant_options' => [
                            ['name' => 'Pack Weight', 'values' => self::PACK_WEIGHTS],
                            ['name' => 'Pet Type', 'values' => ['Dogs', 'Cats', 'Small Animals', 'All Pets'], 'hint' => 'Who this product is made for'],
                            ['name' => 'Flavor', 'values' => ['Chicken', 'Beef', 'Salmon', 'Tuna', 'Lamb', 'Duck', 'Mixed', 'Unflavored']],
                            ['name' => 'Life Stage', 'values' => ['Puppy/Kitten', 'Adult', 'Senior', 'All Life Stages']],
                        ],
                    ],

                    'Toys' => [
                        'specifications' => [
                            ['key' => 'animal_type', 'label' => 'Animal Type', 'type' => 'select', 'options' => ['Dog', 'Cat', 'Bird', 'Fish', 'Small Pet', 'Other']],
                            ['key' => 'toy_type', 'label' => 'Toy Type', 'type' => 'select', 'options' => ['Chew Toy', 'Fetch Toy', 'Interactive', 'Plush', 'Scratching', 'Other']],
                            ['key' => 'safety_notes', 'label' => 'Safety Notes', 'type' => 'textarea'],
                        ],
                        'variant_options' => [
                            ['name' => 'Pet Type', 'values' => ['Dogs', 'Cats', 'Small Animals', 'All Pets'], 'hint' => 'Who this toy is made for'],
                            ['name' => 'Size', 'values' => self::PET_SIZES],
                            ['name' => 'Color', 'values' => self::COLORS],
                        ],
                    ],

                    'Accessories' => [
                        'specifications' => [
                            ['key' => 'animal_type', 'label' => 'Animal Type', 'type' => 'select', 'options' => ['Dog', 'Cat', 'Bird', 'Fish', 'Small Pet', 'Other']],
                            ['key' => 'accessory_type', 'label' => 'Accessory Type', 'type' => 'select', 'options' => ['Collar', 'Leash', 'Harness', 'Bowl', 'Carrier', 'ID Tag', 'Other']],
                            ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                        ],
                        'variant_options' => [
                            ['name' => 'Pet Type', 'values' => ['Dogs', 'Cats', 'Small Animals', 'All Pets'], 'hint' => 'Who this product is made for'],
                            ['name' => 'Size', 'values' => ['XS', 'S', 'M', 'L', 'XL']],
                            ['name' => 'Color', 'values' => self::COLORS],
                        ],
                    ],

                    'Grooming & Health' => [
                        'specifications' => [
                            ['key' => 'animal_type', 'label' => 'Animal Type', 'type' => 'select', 'options' => ['Dog', 'Cat', 'Bird', 'Fish', 'Small Pet', 'Other']],
                            ['key' => 'product_type', 'label' => 'Product Type', 'type' => 'select', 'options' => ['Shampoo', 'Conditioner', 'Wipes', 'Supplement', 'Medication', 'Dental Care', 'Other']],
                            ['key' => 'ingredients', 'label' => 'Ingredients', 'type' => 'textarea'],
                            ['key' => 'directions', 'label' => 'Directions', 'type' => 'textarea'],
                            ['key' => 'expiration_date', 'label' => 'Expiration Date', 'type' => 'date'],
                        ],
                        'variant_options' => [
                            ['name' => 'Pet Type', 'values' => ['Dogs', 'Cats', 'Small Animals', 'All Pets'], 'hint' => 'Who this product is made for'],
                            ['name' => 'Scent', 'values' => ['Unscented', 'Floral', 'Fresh', 'Citrus', 'Other']],
                            ['name' => 'Volume', 'values' => self::VOLUMES],
                        ],
                    ],

                    'Bedding & Housing' => [
                        'specifications' => [
                            ['key' => 'animal_type', 'label' => 'Animal Type', 'type' => 'select', 'options' => ['Dog', 'Cat', 'Bird', 'Fish', 'Small Pet', 'Other']],
                            ['key' => 'housing_type', 'label' => 'Housing Type', 'type' => 'select', 'options' => ['Bed', 'Crate', 'Cage', 'Playpen', 'House', 'Other']],
                            ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                            ['key' => 'assembly_required', 'label' => 'Assembly Required', 'type' => 'select', 'options' => ['Yes', 'No']],
                        ],
                        'variant_options' => [
                            ['name' => 'Pet Type', 'values' => ['Dogs', 'Cats', 'Small Animals', 'All Pets'], 'hint' => 'Who this product is made for'],
                            ['name' => 'Size', 'values' => self::PET_SIZES],
                            ['name' => 'Color', 'values' => self::COLORS],
                        ],
                    ],
                ],
            ],

            // Phones and blenders don't share a sane variant template any
            // more than dog food and dog toys do — split the same way.
            'Electronics and Gadgets' => [
                'subcategories' => [
                    'Phones & Tablets' => [
                        'specifications' => [
                            ['key' => 'model', 'label' => 'Model', 'type' => 'text'],
                            ['key' => 'connectivity', 'label' => 'Connectivity', 'type' => 'select', 'options' => ['Wi-Fi', 'Bluetooth', '4G/LTE', '5G', 'NFC', 'Other']],
                            ['key' => 'warranty', 'label' => 'Warranty', 'type' => 'text'],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Storage Capacity', 'values' => ['32GB', '64GB', '128GB', '256GB', '512GB', '1TB', '2TB']],
                        ],
                    ],

                    'Computers & Laptops' => [
                        'specifications' => [
                            ['key' => 'model', 'label' => 'Model', 'type' => 'text'],
                            ['key' => 'connectivity', 'label' => 'Connectivity', 'type' => 'select', 'options' => ['Wired', 'Bluetooth', 'Wi-Fi', 'USB-C', 'Other']],
                            ['key' => 'warranty', 'label' => 'Warranty', 'type' => 'text'],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Storage Capacity', 'values' => ['128GB', '256GB', '512GB', '1TB', '2TB']],
                            ['name' => 'RAM', 'values' => ['4GB', '8GB', '16GB', '32GB', '64GB']],
                        ],
                    ],

                    'Audio' => [
                        'specifications' => [
                            ['key' => 'model', 'label' => 'Model', 'type' => 'text'],
                            ['key' => 'connectivity', 'label' => 'Connectivity', 'type' => 'select', 'options' => ['Wired', 'Bluetooth', 'Wi-Fi', 'Other']],
                            ['key' => 'battery_life', 'label' => 'Battery Life', 'type' => 'text'],
                            ['key' => 'warranty', 'label' => 'Warranty', 'type' => 'text'],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Model'],
                        ],
                    ],

                    'Home Appliances' => [
                        'specifications' => [
                            ['key' => 'model', 'label' => 'Model', 'type' => 'text'],
                            ['key' => 'voltage', 'label' => 'Voltage', 'type' => 'text'],
                            ['key' => 'wattage', 'label' => 'Wattage', 'type' => 'text'],
                            ['key' => 'warranty', 'label' => 'Warranty', 'type' => 'text'],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Model'],
                        ],
                    ],

                    'Accessories' => [
                        'specifications' => [
                            ['key' => 'connectivity', 'label' => 'Connectivity', 'type' => 'select', 'options' => ['Wired', 'Bluetooth', 'Wi-Fi', 'USB-C', 'NFC', 'Other']],
                            ['key' => 'warranty', 'label' => 'Warranty', 'type' => 'text'],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Model'],
                        ],
                    ],
                ],
            ],

            // A sofa and a garden hose are both "House and Garden" but
            // need nothing in common variant-wise.
            'House and Garden' => [
                'subcategories' => [
                    'Furniture' => [
                        'specifications' => [
                            ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                            ['key' => 'room_type', 'label' => 'Room Type', 'type' => 'select', 'options' => ['Living Room', 'Bedroom', 'Dining Room', 'Office', 'Outdoor', 'Other']],
                            ['key' => 'assembly_required', 'label' => 'Assembly Required', 'type' => 'select', 'options' => ['Yes', 'No']],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Material', 'values' => self::MATERIALS],
                            ['name' => 'Size', 'values' => self::HOME_SIZES],
                        ],
                    ],

                    'Kitchenware' => [
                        'specifications' => [
                            ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                            ['key' => 'set_size', 'label' => 'Set Size', 'type' => 'text'],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Material', 'values' => self::MATERIALS],
                            ['name' => 'Size', 'values' => self::HOME_SIZES],
                        ],
                    ],

                    'Home Decor' => [
                        'specifications' => [
                            ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                            ['key' => 'room_type', 'label' => 'Room Type', 'type' => 'select', 'options' => ['Living Room', 'Bedroom', 'Bathroom', 'Kitchen', 'Office', 'Outdoor', 'Other']],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Material', 'values' => self::MATERIALS],
                            ['name' => 'Size', 'values' => self::HOME_SIZES],
                        ],
                    ],

                    'Garden & Outdoor' => [
                        'specifications' => [
                            ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                            ['key' => 'assembly_required', 'label' => 'Assembly Required', 'type' => 'select', 'options' => ['Yes', 'No']],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Material', 'values' => self::MATERIALS],
                            ['name' => 'Size', 'values' => self::HOME_SIZES],
                        ],
                    ],

                    'Storage & Organization' => [
                        'specifications' => [
                            ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                            ['key' => 'room_type', 'label' => 'Room Type', 'type' => 'select', 'options' => ['Living Room', 'Bedroom', 'Kitchen', 'Bathroom', 'Garage', 'Office', 'Other']],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Material', 'values' => self::MATERIALS],
                            ['name' => 'Size', 'values' => self::HOME_SIZES],
                        ],
                    ],
                ],
            ],

            // Lipstick and shampoo don't share "Shade" or "Volume" the
            // same way, and skincare's Skin Type doesn't apply to a
            // perfume — split per product type instead of one guess-list.
            'Health and Beauty' => [
                'subcategories' => [
                    'Skincare' => [
                        'specifications' => [
                            ['key' => 'skin_type', 'label' => 'Skin Type', 'type' => 'select', 'options' => ['Normal', 'Oily', 'Dry', 'Combination', 'Sensitive', 'All Skin Types']],
                            ['key' => 'ingredients', 'label' => 'Ingredients', 'type' => 'textarea'],
                            ['key' => 'directions', 'label' => 'Directions', 'type' => 'textarea'],
                        ],
                        'variant_options' => [
                            ['name' => 'Volume', 'values' => self::VOLUMES],
                            ['name' => 'Scent', 'values' => ['Unscented', 'Floral', 'Fresh', 'Citrus', 'Other']],
                        ],
                    ],

                    'Makeup' => [
                        'specifications' => [
                            ['key' => 'skin_type', 'label' => 'Skin Type', 'type' => 'select', 'options' => ['Normal', 'Oily', 'Dry', 'Combination', 'Sensitive', 'All Skin Types']],
                            ['key' => 'ingredients', 'label' => 'Ingredients', 'type' => 'textarea'],
                            ['key' => 'directions', 'label' => 'Directions', 'type' => 'textarea'],
                        ],
                        'variant_options' => [
                            ['name' => 'Shade'],
                            ['name' => 'Volume', 'values' => self::VOLUMES],
                        ],
                    ],

                    'Haircare' => [
                        'specifications' => [
                            ['key' => 'hair_type', 'label' => 'Hair Type', 'type' => 'select', 'options' => ['Straight', 'Wavy', 'Curly', 'Coily', 'All Hair Types']],
                            ['key' => 'ingredients', 'label' => 'Ingredients', 'type' => 'textarea'],
                            ['key' => 'directions', 'label' => 'Directions', 'type' => 'textarea'],
                        ],
                        'variant_options' => [
                            ['name' => 'Scent', 'values' => ['Unscented', 'Floral', 'Fresh', 'Citrus', 'Other']],
                            ['name' => 'Volume', 'values' => self::VOLUMES],
                        ],
                    ],

                    'Fragrance' => [
                        'specifications' => [
                            ['key' => 'ingredients', 'label' => 'Ingredients', 'type' => 'textarea'],
                        ],
                        'variant_options' => [
                            ['name' => 'Scent', 'values' => ['Floral', 'Fresh', 'Citrus', 'Woody', 'Fruity', 'Musky', 'Other']],
                            ['name' => 'Volume', 'values' => self::VOLUMES],
                        ],
                    ],

                    'Personal Care' => [
                        'specifications' => [
                            ['key' => 'ingredients', 'label' => 'Ingredients', 'type' => 'textarea'],
                            ['key' => 'directions', 'label' => 'Directions', 'type' => 'textarea'],
                        ],
                        'variant_options' => [
                            ['name' => 'Scent', 'values' => ['Unscented', 'Floral', 'Fresh', 'Citrus', 'Other']],
                            ['name' => 'Volume', 'values' => self::VOLUMES],
                        ],
                    ],
                ],
            ],

            // A onesie and a stroller are both "Kids and Baby" but need
            // completely different variant/spec shapes.
            'Kids and Baby' => [
                'subcategories' => [
                    'Clothing' => [
                        'specifications' => [
                            ['key' => 'age_range', 'label' => 'Age Range', 'type' => 'select', 'options' => ['0-6 months', '6-12 months', '1-2 years', '3-5 years', '6-8 years', '9-12 years', 'All Ages']],
                            ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Size', 'values' => self::KIDS_SIZES],
                        ],
                    ],

                    'Toys & Learning' => [
                        'specifications' => [
                            ['key' => 'age_range', 'label' => 'Age Range', 'type' => 'select', 'options' => ['0-6 months', '6-12 months', '1-2 years', '3-5 years', '6-8 years', '9-12 years', 'All Ages']],
                            ['key' => 'safety_certification', 'label' => 'Safety Certification', 'type' => 'text'],
                            ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Size', 'values' => self::PET_SIZES],
                        ],
                    ],

                    'Feeding & Nursing' => [
                        'specifications' => [
                            ['key' => 'age_range', 'label' => 'Age Range', 'type' => 'select', 'options' => ['0-6 months', '6-12 months', '1-2 years', '3-5 years', 'All Ages']],
                            ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                            ['key' => 'safety_certification', 'label' => 'Safety Certification', 'type' => 'text'],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Volume', 'values' => self::VOLUMES],
                        ],
                    ],

                    'Diapering & Bath' => [
                        'specifications' => [
                            ['key' => 'age_range', 'label' => 'Age Range', 'type' => 'select', 'options' => ['0-6 months', '6-12 months', '1-2 years', '3-5 years', 'All Ages']],
                            ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                        ],
                        'variant_options' => [
                            ['name' => 'Size', 'values' => ['Newborn', 'Small', 'Medium', 'Large', 'X-Large']],
                            ['name' => 'Color', 'values' => self::COLORS],
                        ],
                    ],

                    'Nursery & Gear' => [
                        'specifications' => [
                            ['key' => 'age_range', 'label' => 'Age Range', 'type' => 'select', 'options' => ['0-6 months', '6-12 months', '1-2 years', '3-5 years', 'All Ages']],
                            ['key' => 'safety_certification', 'label' => 'Safety Certification', 'type' => 'text'],
                            ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Material', 'values' => self::MATERIALS],
                        ],
                    ],
                ],
            ],

            // A running shoe, a tent, and a dumbbell are all "Sports and
            // Outdoors" but "Capacity" only makes sense for one of them.
            'Sports and Outdoors' => [
                'subcategories' => [
                    'Apparel & Footwear' => [
                        'specifications' => [
                            ['key' => 'activity_type', 'label' => 'Activity Type', 'type' => 'select', 'options' => ['Running', 'Gym', 'Cycling', 'Swimming', 'Team Sports', 'Outdoor', 'Other']],
                            ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Size', 'values' => [...self::APPAREL_SIZES, 'One Size']],
                        ],
                    ],

                    'Equipment & Gear' => [
                        'specifications' => [
                            ['key' => 'activity_type', 'label' => 'Activity Type', 'type' => 'select', 'options' => ['Gym', 'Team Sports', 'Racquet Sports', 'Other']],
                            ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Size', 'values' => self::PET_SIZES],
                        ],
                    ],

                    'Camping & Hiking' => [
                        'specifications' => [
                            ['key' => 'activity_type', 'label' => 'Activity Type', 'type' => 'select', 'options' => ['Camping', 'Hiking', 'Outdoor', 'Other']],
                            ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Capacity', 'values' => ['1L', '2L', '5L', '10L', '20L', '50L']],
                        ],
                    ],

                    'Fitness Accessories' => [
                        'specifications' => [
                            ['key' => 'activity_type', 'label' => 'Activity Type', 'type' => 'select', 'options' => ['Gym', 'Yoga', 'Running', 'Other']],
                            ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Size', 'values' => self::PET_SIZES],
                        ],
                    ],

                    'Cycling' => [
                        'specifications' => [
                            ['key' => 'activity_type', 'label' => 'Activity Type', 'type' => 'select', 'options' => ['Road Cycling', 'Mountain Biking', 'Commuting', 'Other']],
                            ['key' => 'material', 'label' => 'Material', 'type' => 'text'],
                        ],
                        'variant_options' => [
                            ['name' => 'Color', 'values' => self::COLORS],
                            ['name' => 'Size', 'values' => ['S', 'M', 'L', 'XL']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
