<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCoupon;
use App\Services\Coupons\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductCouponController extends Controller
{
    public function __construct(private CouponService $coupons) {}

    /** GET /api/seller/products/{id}/coupons */
    public function index(Request $request, string $id): JsonResponse
    {
        $product = $this->product($request, $id);

        return response()->json([
            'data' => ProductCoupon::where('product_id', $product->id)->latest()->get()
                ->map(fn (ProductCoupon $c) => $this->coupons->presentCoupon($c, (float) $product->price)),
        ]);
    }

    /** POST /api/seller/products/{id}/coupons — { coupons: [...] }, one or many at once. */
    public function store(Request $request, string $id): JsonResponse
    {
        $product = $this->product($request, $id);
        $rows = $request->validate([
            'coupons' => ['required', 'array', 'min:1', 'max:20'],
            ...$this->rules('coupons.*.'),
        ])['coupons'];

        $created = $this->coupons->createMany($request->user(), $product, $rows);

        return response()->json([
            'data' => $created->map(fn (ProductCoupon $c) => $this->coupons->presentCoupon($c, (float) $product->price)),
        ], 201);
    }

    /** PUT /api/seller/coupons/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        $coupon = $this->coupon($request, $id);
        $coupon = $this->coupons->update($request->user(), $coupon, $request->validate($this->rules()));

        return response()->json(['data' => $this->coupons->presentCoupon($coupon, (float) $coupon->product->price)]);
    }

    /** DELETE /api/seller/coupons/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->coupons->delete($request->user(), $this->coupon($request, $id));

        return response()->json(['data' => ['id' => $id]]);
    }

    /** @return array<string, mixed> */
    private function rules(string $p = ''): array
    {
        return [
            "{$p}code" => ['nullable', 'string', 'max:32'],
            "{$p}discount_type" => ['required', Rule::in([ProductCoupon::TYPE_PERCENTAGE, ProductCoupon::TYPE_FIXED])],
            "{$p}discount_value" => ['required', 'numeric', 'gt:0', 'max:1000000'],
            "{$p}max_discount" => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            "{$p}usage_limit" => ['nullable', 'integer', 'min:1', 'max:1000000'],
            "{$p}expires_at" => ['required', 'date', 'after:now'],
        ];
    }

    private function product(Request $request, string $id): Product
    {
        return Product::where('seller_id', $request->user()->id)->findOrFail($id);
    }

    private function coupon(Request $request, string $id): ProductCoupon
    {
        return ProductCoupon::with('product')->where('seller_id', $request->user()->id)->findOrFail($id);
    }
}
