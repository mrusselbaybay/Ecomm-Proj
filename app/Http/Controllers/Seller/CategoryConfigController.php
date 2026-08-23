<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Support\CategoryFieldConfig;
use Illuminate\Http\JsonResponse;

class CategoryConfigController extends Controller
{
    /**
     * GET /api/seller/category-config
     *
     * Read-only: the seller can't select or submit a different category
     * here or anywhere else — this only tells the frontend which fields
     * and variant option types apply to the category already on their
     * account, so the product form can render itself accordingly.
     */
    public function show(): JsonResponse
    {
        $seller = request()->user();
        $category = $seller->sellerDetail?->line_of_business;

        if (!$category) {
            return response()->json([
                'message' => 'Your seller account has no registered line of business yet.',
            ], 422);
        }

        $template = CategoryFieldConfig::for($category);

        return response()->json([
            'data' => [
                'category' => $category,
                'specifications' => $template['specifications'],
                'variant_options' => $template['variant_options'],
            ],
        ]);
    }
}