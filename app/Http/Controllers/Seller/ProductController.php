<?php

namespace App\Http\Controllers\Seller;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\StoreProductRequest;
use App\Jobs\ModerateProductJob;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function store(StoreProductRequest $request): JsonResponse
    {
        if (! config('services.product_moderation.enabled', false)) {
            return response()->json([
                'message' => 'Product moderation is not enabled yet.',
            ], 503);
        }

        $product = DB::transaction(function () use ($request): Product {
            $product = Product::query()->create([
                ...$request->validated(),
                'seller_id' => $request->user()->id,
                'status' => ProductStatus::PendingReview->value,
            ]);

            ModerateProductJob::dispatch($product->id)->afterCommit();

            return $product;
        });

        return response()->json([
            'data' => $product,
            'message' => 'Product submitted for moderation.',
        ], 201);
    }
}
