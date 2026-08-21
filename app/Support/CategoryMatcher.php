<?php

namespace App\Support;

class CategoryMatcher
{
    /** @var array<string, list<string>> */
    private const CATEGORY_ALIASES = [
        'Pet Supplies' => [
            'pets', 'pet', 'pet food', 'dog supplies', 'cat supplies',
            'pet accessories', 'pet grooming',
        ],
        'Kids and Baby' => [
            'kids', 'children', 'childrens products', 'baby', 'baby products',
            'nursery', 'toys and games',
        ],
        'Electronics and Gadgets' => [
            'electronics', 'gadgets', 'electronic accessories', 'phones',
            'mobile phones', 'smartphones', 'computers', 'laptops', 'tablets',
            'cameras', 'gaming',
        ],
        'House and Garden' => [
            'home', 'home and living', 'home living', 'household', 'furniture', 'kitchen',
            'home decor', 'garden', 'gardening',
        ],
        "Woman's Apparel" => [
            'womans apparel', 'womens apparel', 'women apparel',
            'womens clothing', 'women s clothing', 'women clothing', 'womens fashion',
            'women fashion',
        ],
        "Men's Apparel" => [
            'mens apparel', 'men apparel', 'mens clothing', 'men clothing',
            'mens fashion', 'men fashion',
        ],
        'Sports and Outdoors' => [
            'sports', 'sporting goods', 'outdoors', 'outdoor', 'fitness',
            'exercise equipment', 'camping',
        ],
        'Health and Beauty' => [
            'health', 'beauty', 'health and wellness', 'skincare',
            'skin care', 'cosmetics', 'makeup', 'personal care', 'wellness',
        ],
    ];

    public static function matches(?string $registeredCategory, ?string $productCategory): bool
    {
        if (! $registeredCategory || ! $productCategory) {
            return false;
        }

        return self::canonical($registeredCategory) === self::canonical($productCategory);
    }

    public static function canonical(string $category): string
    {
        $normalizedCategory = self::normalize($category);

        foreach (self::CATEGORY_ALIASES as $canonicalCategory => $aliases) {
            $acceptedCategories = [$canonicalCategory, ...$aliases];

            foreach ($acceptedCategories as $acceptedCategory) {
                if ($normalizedCategory === self::normalize($acceptedCategory)) {
                    return self::normalize($canonicalCategory);
                }
            }
        }

        return $normalizedCategory;
    }

    /**
     * Return a PostgreSQL CASE expression and its parameter bindings which apply
     * the same canonical category mapping used by matches().
     *
     * @return array{sql: string, bindings: list<string>}
     */
    public static function sqlCase(string $column): array
    {
        $normalizedColumn = "trim(regexp_replace(lower(coalesce({$column}, '')), '[^a-z0-9]+', ' ', 'g'))";
        $cases = [];
        $bindings = [];

        foreach (self::CATEGORY_ALIASES as $canonicalCategory => $aliases) {
            $acceptedCategories = array_values(array_unique(array_map(
                self::normalize(...),
                [$canonicalCategory, ...$aliases],
            )));
            $placeholders = implode(', ', array_fill(0, count($acceptedCategories), '?'));
            $cases[] = "when {$normalizedColumn} in ({$placeholders}) then ?";
            array_push($bindings, ...$acceptedCategories);
            $bindings[] = self::normalize($canonicalCategory);
        }

        return [
            'sql' => 'case '.implode(' ', $cases)." else {$normalizedColumn} end",
            'bindings' => $bindings,
        ];
    }

    private static function normalize(string $category): string
    {
        $normalized = mb_strtolower(trim($category));
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
