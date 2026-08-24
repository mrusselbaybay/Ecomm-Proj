<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\StoreProductRequest;
use App\Http\Requests\Seller\UpdateProductRequest;
use App\Models\Product;
use App\Services\SellerProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class SellerProductController extends Controller
{
    public function __construct(private readonly SellerProductService $products)
    {
    }

    /**
     * GET /api/seller/products
     *
     * Every product belonging to the authenticated seller, with its
     * options/variants eager loaded.
     */
    public function index(): JsonResponse
    {
        $seller = request()->user();

        $products = Product::with(['options.values', 'variants.optionValues.option'])
            ->where('seller_id', $seller->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $products->map(fn (Product $p) => $this->transform($p)),
        ]);
    }

    /**
     * POST /api/seller/products
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        try {
            $product = $this->products->create($request->user(), $request->validated());
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Could not create the product.',
            ], 422);
        }

        return response()->json(['data' => $this->transform($product)], 201);
    }

    /**
     * PUT /api/seller/products/{id}
     */
    public function update(UpdateProductRequest $request, string $id): JsonResponse
    {
        $seller = $request->user();
        $product = Product::where('seller_id', $seller->id)->find($id);

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        try {
            $product = $this->products->update($seller, $product, $request->validated());
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Could not save changes.',
            ], 422);
        }

        return response()->json(['data' => $this->transform($product)]);
    }

    /**
     * DELETE /api/seller/products/{id}
     *
     * Soft-archives rather than deleting the row (see the product-status
     * contract: seller "delete" = status -> 'archived'), preserving order
     * history that references this product.
     */
    public function destroy(string $id): JsonResponse
    {
        $seller = request()->user();
        $product = Product::where('seller_id', $seller->id)->find($id);

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $product->update(['status' => 'archived']);

        return response()->json(['data' => $this->transform($product->fresh(['options.values', 'variants.optionValues.option']))]);
    }

    private function transform(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'category' => $product->category,
            'brand' => $product->brand,
            'condition' => $product->condition,
            'dimensions' => $product->dimensions,
            'weight' => $product->weight !== null ? (float) $product->weight : null,
            'specifications' => $product->specifications ?? [],
            'low_stock_threshold' => $product->low_stock_threshold,
            'sku' => $product->sku,
            'price' => (float) $product->price,
            'compare_price' => $product->compare_price !== null ? (float) $product->compare_price : null,
            'promo_code' => $product->promo_code,
            'stock' => (int) $product->stock,
            'images' => $product->images ?? [],
            'status' => $product->status,
            'has_variants' => (bool) $product->has_variants,
            'created_at' => $product->created_at,
            'updated_at' => $product->updated_at,
            'options' => $product->options->map(fn ($opt) => [
                'id' => $opt->id,
                'name' => $opt->name,
                'values' => $opt->values->map(fn ($v) => [
                    'id' => $v->id,
                    'value' => $v->value,
                ])->all(),
            ])->all(),
            'variants' => $product->variants->map(fn ($v) => [
                'id' => $v->id,
                'sku' => $v->sku,
                'price' => $v->price !== null ? (float) $v->price : null,
                'stock' => (int) $v->stock,
                'image' => $v->image,
                'status' => $v->status,
                'option_values' => $v->optionValues->mapWithKeys(
                    fn ($ov) => [$ov->option?->name ?? '' => $ov->value],
                )->all(),
            ])->all(),
        ];
    }
}