<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\BuyerVoucher;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Voucher;
use App\Services\CheckoutService;
use App\Services\StoreCatalog;
use App\Services\Vouchers\PlatformVoucherService;
use App\Services\Vouchers\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Buyer side of seller vouchers. The /coupons routes point here too and
 * keep their old response shapes for the mobile app (one release cycle).
 */
class VoucherController extends Controller
{
    public function __construct(
        private VoucherService $vouchers,
        private PlatformVoucherService $platformVouchers,
        private CheckoutService $checkout,
        private StoreCatalog $stores,
    ) {}

    /** GET /api/products/{id}/vouchers — public: claimable vouchers for this product's page. */
    public function forProduct(string $id): JsonResponse
    {
        $product = Product::where('status', 'active')->findOrFail($id);

        return response()->json([
            'data' => $this->vouchers->claimableForProduct($product)
                ->map(fn (Voucher $v) => $this->vouchers->presentClaimable($v, $product)),
        ]);
    }

    /** GET /api/stores/{id}/vouchers — public shop-wide offers. */
    public function forStore(string $id): JsonResponse
    {
        if (! $this->stores->isVisibleStore($id)) {
            return response()->json(['message' => 'Store not found.'], 404);
        }

        return response()->json([
            'data' => $this->vouchers->claimableForStore($id)
                ->map(fn (Voucher $voucher) => $this->vouchers->presentClaimable($voucher)),
        ]);
    }

    /** GET /api/buyer/vouchers — the buyer's wallet. */
    public function index(Request $request): JsonResponse
    {
        $entries = $this->vouchers->wallet($request->user());
        $used = $this->vouchers->usageByBuyer($request->user(), $entries->pluck('voucher_id')->all());

        return response()->json([
            'data' => $entries->map(fn (BuyerVoucher $bc) => $this->vouchers->presentWalletEntry($bc, $used[$bc->voucher_id] ?? 0)),
        ]);
    }

    /** POST /api/buyer/vouchers/{voucherId}/claim */
    public function claim(Request $request, string $voucherId): JsonResponse
    {
        try {
            $bc = $this->vouchers->claim($request->user(), $voucherId);
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }

        $bc->voucher->load(['seller.sellerDetail', 'products' => fn ($q) => $q->select('products.id', 'products.name')->limit(1)])
            ->loadCount('products');

        return response()->json(['data' => $this->vouchers->presentWalletEntry($bc)], 201);
    }

    /**
     * POST /api/buyer/vouchers/quote
     * { lines: [{key, product_id, variant_id?, quantity}], shipping_method, address_id?, selected?: {sellerId: {discount, shipping}} }
     *
     * `sellers`: applicable seller vouchers per seller order + best pick.
     * `platform`: BuyTheWay vouchers valued on top of the seller picks
     * (`selected`, else each seller's best) + best pick; `best.exclusive`
     * means a non-stackable platform voucher beats keeping seller vouchers.
     * Prices come from the DB.
     */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'max:100'],
            'lines.*.key' => ['required', 'string', 'max:200'],
            'lines.*.product_id' => ['required', 'string'],
            'lines.*.variant_id' => ['nullable', 'string'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'shipping_method' => ['nullable', Rule::in(['standard', 'express', 'same_day'])],
            'address_id' => ['nullable', 'uuid'],
            'selected' => ['nullable', 'array'],
            'selected.*.discount' => ['nullable', 'uuid'],
            'selected.*.shipping' => ['nullable', 'uuid'],
        ]);

        $buyer = $request->user();
        $fee = CheckoutService::shippingFee($data['shipping_method'] ?? 'standard');
        $lines = $this->priced($data['lines'])
            ->map(fn ($l) => [...$l, 'subtotal' => round($l['unit_price'] * $l['quantity'], 2)]);
        $sellers = $this->vouchers->quote($buyer, $lines->all(), $fee);

        // Seller picks the platform pass builds on: the buyer's, else the best.
        $linesBySeller = $lines->groupBy('seller_id')->map(fn ($ls) => $ls->values()->all())->all();
        $applied = [];
        $sellerSavings = 0.0;
        foreach ($linesBySeller as $sellerId => $sellerLines) {
            $pick = $data['selected'][$sellerId] ?? [
                'discount' => $sellers[$sellerId]['best']['discountId'] ?? null,
                'shipping' => $sellers[$sellerId]['best']['shippingId'] ?? null,
            ];
            try {
                $applied[$sellerId] = $this->vouchers->applySelection($buyer, $sellerId, $sellerLines, $fee, $pick['discount'] ?? null, $pick['shipping'] ?? null);
            } catch (ValidationException) {
                $applied[$sellerId] = ['discount' => null, 'shipping' => null];
            }
            $sellerSavings += ($applied[$sellerId]['discount']['amount'] ?? 0) + ($applied[$sellerId]['shipping']['amount'] ?? 0);
        }

        $region = $this->checkout->destination($buyer, $data['address_id'] ?? null)['region_name'];

        return response()->json(['data' => [
            'sellers' => $sellers,
            'platform' => $this->platformVouchers->quote(
                $buyer,
                $region,
                PlatformVoucherService::context($linesBySeller, $applied),
                PlatformVoucherService::context($linesBySeller, []),
                $fee,
                $sellerSavings,
            ),
        ]]);
    }

    /** POST /api/buyer/coupons/quote — legacy per-line product voucher quote (mobile). */
    public function legacyQuote(Request $request): JsonResponse
    {
        $lines = $request->validate([
            'lines' => ['required', 'array', 'max:100'],
            'lines.*.key' => ['required', 'string', 'max:200'],
            'lines.*.product_id' => ['required', 'string'],
            'lines.*.variant_id' => ['nullable', 'string'],
        ])['lines'];

        return response()->json(['data' => $this->vouchers->legacyQuote($request->user(), $this->priced($lines)->all())]);
    }

    /** @return Collection<int, array{key: string, product_id: string, seller_id: string, unit_price: float, quantity: int}> */
    private function priced(array $lines): Collection
    {
        $products = Product::whereIn('id', array_column($lines, 'product_id'))->get(['id', 'price', 'seller_id', 'category'])->keyBy('id');
        $variants = ProductVariant::whereIn('id', array_filter(array_column($lines, 'variant_id')))->get(['id', 'price'])->keyBy('id');

        return collect($lines)
            ->filter(fn ($l) => $products->has($l['product_id']))
            ->map(fn ($l) => [
                'key' => $l['key'],
                'product_id' => $l['product_id'],
                'seller_id' => $products[$l['product_id']]->seller_id,
                'category' => $products[$l['product_id']]->category,
                'unit_price' => (float) ($variants->get($l['variant_id'] ?? '')?->price ?? $products[$l['product_id']]->price),
                'quantity' => (int) ($l['quantity'] ?? 1),
            ])
            ->values();
    }
}
