<?php

use App\Support\CategoryMatcher;

it('matches an exact registered category', function () {
    expect(CategoryMatcher::matches('Electronics and Gadgets', 'Electronics and Gadgets'))->toBeTrue();
});

it('matches recognized subcategories and aliases', function (string $registeredCategory, string $productCategory) {
    expect(CategoryMatcher::matches($registeredCategory, $productCategory))->toBeTrue();
})->with([
    ['Electronics and Gadgets', 'Mobile Phones'],
    ['House and Garden', 'Home & Living'],
    ["Woman's Apparel", "Women's Clothing"],
    ['Health and Beauty', 'Skin Care'],
    ['Sports and Outdoors', 'Fitness'],
]);

it('still identifies categories from different groups as mismatches', function () {
    expect(CategoryMatcher::matches('Pet Supplies', 'Mobile Phones'))->toBeFalse();
});

it('does not guess when a category is missing or too broad', function () {
    expect(CategoryMatcher::matches('Electronics and Gadgets', null))->toBeFalse()
        ->and(CategoryMatcher::matches("Woman's Apparel", 'Fashion'))->toBeFalse();
});
