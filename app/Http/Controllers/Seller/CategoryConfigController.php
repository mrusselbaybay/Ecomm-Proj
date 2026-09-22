<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Support\CategoryFieldConfig;
use Illuminate\Http\JsonResponse;

class CategoryConfigController extends Controller
{
    /**
     * GET /api/seller/category-config?subcategory=...
     *
     * The seller can't change their CATEGORY here or anywhere else — it's
     * always their account's own line_of_business. Some categories (e.g.
     * Pet Supplies) cover different enough products that they're further
     * split into subcategories (Food & Treats vs Toys vs ...), and THAT
     * the seller does pick, per product — see CategoryFieldConfig's class
     * docblock. `subcategories` in the response lists the choices for
     * this seller's category (empty if it doesn't use them); pass the
     * chosen one back as `?subcategory=` to get that subcategory's own
     * specifications/variant_options instead of an empty shell.
     */
    public function show(): JsonResponse
    {
        $seller = request()->user();
        $category = $seller->sellerDetail?->line_of_business;

        if (! $category) {
            return response()->json([
                'message' => 'Your seller account has no registered line of business yet.',
            ], 422);
        }

        $subcategory = request()->query('subcategory');
        $subcategory = CategoryFieldConfig::isValidSubcategory($category, $subcategory) ? $subcategory : null;

        $template = CategoryFieldConfig::for($category, $subcategory);

        return response()->json([
            'data' => [
                'category' => $category,
                'subcategories' => CategoryFieldConfig::subcategoriesFor($category),
                'subcategory' => $subcategory,
                'specifications' => $template['specifications'],
                'variant_options' => $template['variant_options'],
            ],
        ]);
    }
}
