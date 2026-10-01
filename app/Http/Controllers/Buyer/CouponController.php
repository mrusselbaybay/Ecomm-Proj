<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\BuyerCoupon;
use App\Models\Product;
use App\Models\ProductCoupon;
use App\Models\ProductVariant;
use App\Services\Coupons\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CouponController extends Controller
{
    public function __construct(private CouponService $coupons) {}

    /** GET /api/products/{id}/coupons — public: claimable coupons with the price after each. */
    public function forProduct(string $id): JsonResponse
    {
        $product = Product::where('status', 'active')->findOrFail($id);

        return response()->json([
            'data' => ProductCoupon::where('product_id', $product->id)
                ->where('status', ProductCoupon::STATUS_ACTIVE)
                ->where('expires_at', '>', now())
                ->orderBy('expires_at')
                ->get()
                ->filter(fn (ProductCoupon $c) => ! $c->isExhausted())
                ->map(fn (ProductCoupon $c) => $this->coupons->presentCoupon($c, (float) $product->price))
                ->values(),
        ]);
    }

    /** GET /api/buyer/coupons — the buyer's wallet. */
    public function index(Request $request): JsonResponse
    {
        $entries = BuyerCoupon::with('coupon.product:id,name')
            ->where('buyer_profile_id', $request->user()->id)
            ->latest('claimed_at')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $entries->map(fn (BuyerCoupon $bc) => $this->coupons->presentWalletEntry($bc)),
        ]);
    }

    /** POST /api/buyer/coupons/{couponId}/claim */
    public function claim(Request $request, string $couponId): JsonResponse
    {
        try {
            $bc = $this->coupons->claim($request->user(), $couponId);
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }

        $bc->coupon->load('product:id,name');

        return response()->json(['data' => $this->coupons->presentWalletEntry($bc)], 201);
    }

    /**
     * POST /api/buyer/coupons/quote — { lines: [{key, product_id, variant_id?}] }
     * Usable coupons per checkout line with server-computed discounts,
     * best first, plus the auto-apply pick. Prices come from the DB.
     */
    public function quote(Request $request): JsonResponse
    {
        $lines = $request->validate([
            'lines' => ['required', 'array', 'max:100'],
            'lines.*.key' => ['required', 'string', 'max:200'],
            'lines.*.product_id' => ['required', 'string'],
            'lines.*.variant_id' => ['nullable', 'string'],
        ])['lines'];

        $products = Product::whereIn('id', array_column($lines, 'product_id'))->get(['id', 'price'])->keyBy('id');
        $variants = ProductVariant::whereIn('id', array_filter(array_column($lines, 'variant_id')))->get(['id', 'price'])->keyBy('id');

        $priced = collect($lines)
            ->filter(fn ($l) => $products->has($l['product_id']))
            ->map(fn ($l) => [
                'key' => $l['key'],
                'product_id' => $l['product_id'],
                'unit_price' => (float) ($variants->get($l['variant_id'] ?? '')?->price ?? $products[$l['product_id']]->price),
            ])
            ->values()
            ->all();

        return response()->json(['data' => $this->coupons->quote($request->user(), $priced)]);
    }
}
