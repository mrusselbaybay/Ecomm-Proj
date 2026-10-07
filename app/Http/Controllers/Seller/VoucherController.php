<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Services\Vouchers\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Seller voucher management (Vouchers modal on the Inventory page). */
class VoucherController extends Controller
{
    public function __construct(private VoucherService $vouchers) {}

    /**
     * GET /api/seller/vouchers?tab=active|inactive&scope=&type=&status=&page=
     * Active tab: active/scheduled/fully redeemed; Inactive: deactivated/expired.
     * 5 per page, plus per-tab counts under the same scope/type filters.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['active', 'inactive'])],
            'scope' => ['nullable', Rule::in([Voucher::SCOPE_SHOP, Voucher::SCOPE_PRODUCT])],
            'type' => ['nullable', Rule::in([Voucher::TYPE_DISCOUNT, Voucher::TYPE_SHIPPING])],
            'status' => ['nullable', Rule::in([Voucher::STATUS_ACTIVE, Voucher::STATUS_DEACTIVATED, Voucher::STATUS_FULLY_REDEEMED, Voucher::STATUS_EXPIRED])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $base = Voucher::where('seller_id', $request->user()->id)
            ->when($filters['scope'] ?? null, fn ($q, $scope) => $q->where('scope', $scope))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type));
        $tab = ($filters['tab'] ?? 'active') === 'inactive' ? 'inactive' : 'live';
        $unavailable = fn ($q) => $q->where(fn ($q) => $q->where('products.status', '!=', 'active')->orWhere('products.stock', '<=', 0));

        $page = (clone $base)
            ->whereStatus($tab)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->whereStatus($status))
            ->withCount(['products', 'products as unavailable_products_count' => $unavailable, 'redemptions'])
            ->with(['products' => fn ($q) => $q->select('products.id', 'products.name')->orderBy('products.name')->limit(5)])
            ->latest()
            ->paginate(5);

        return response()->json(['data' => [
            'items' => collect($page->items())->map(fn (Voucher $v) => $this->vouchers->presentForSeller($v)),
            'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
            'counts' => [
                'active' => (clone $base)->whereStatus('live')->count(),
                'inactive' => (clone $base)->whereStatus('inactive')->count(),
            ],
        ]]);
    }

    /** GET /api/seller/vouchers/{id} — full product list, for cloning. */
    public function show(Request $request, string $id): JsonResponse
    {
        $voucher = $this->voucher($request, $id)->loadCount(['products', 'redemptions']);

        return response()->json(['data' => [
            ...$this->vouchers->presentForSeller($voucher),
            'productIds' => $voucher->products()->pluck('products.id'),
        ]]);
    }

    /**
     * POST /api/seller/vouchers. A product voucher overlapping another on the
     * same product and dates → 409 with the conflicts; resend with
     * confirm_overlap=true to create anyway.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([Voucher::TYPE_DISCOUNT, Voucher::TYPE_SHIPPING])],
            'scope' => ['required', Rule::in([Voucher::SCOPE_SHOP, Voucher::SCOPE_PRODUCT])],
            'product_ids' => ['nullable', 'array', 'max:'.Voucher::MAX_PRODUCTS],
            'product_ids.*' => ['uuid'],
            // Shipping vouchers are always free shipping (VoucherService::create()).
            'discount_type' => ['required_if:type,discount', 'nullable', Rule::in([Voucher::DISCOUNT_PERCENTAGE, Voucher::DISCOUNT_FIXED])],
            'discount_value' => ['required_if:type,discount', 'nullable', 'numeric', 'gt:0', 'max:1000000'],
            'max_discount' => [Rule::requiredIf(fn () => $request->input('type') === 'discount' && $request->input('discount_type') === 'percentage'), 'nullable', 'numeric', 'gt:0', 'max:1000000'],
            'min_spend' => [Rule::requiredIf(fn () => $request->input('scope') === 'shop'), 'nullable', 'numeric', 'min:0', 'max:10000000'],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after:starts_at', 'after:now'],
            'usage_limit' => ['required', 'integer', 'min:1', 'max:1000000'],
            'per_user_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'budget_cap' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'stackable' => ['required', 'boolean'],
            'confirm_overlap' => ['sometimes', 'boolean'],
        ], [
            'max_discount.required' => 'Set a max discount for percentage vouchers.',
            'min_spend.required' => 'Set a minimum spend for shop-wide vouchers.',
            'expires_at.after' => 'End date must be after the start date and in the future.',
        ]);

        $seller = $request->user();
        $isProductDiscount = $data['type'] === Voucher::TYPE_DISCOUNT && $data['scope'] === Voucher::SCOPE_PRODUCT;

        if ($isProductDiscount && ! ($data['confirm_overlap'] ?? false)) {
            $conflicts = $this->vouchers->overlaps(
                $seller,
                array_values(array_unique($data['product_ids'] ?? [])),
                Carbon::parse($data['starts_at']),
                Carbon::parse($data['expires_at']),
            );

            if ($conflicts->isNotEmpty()) {
                return response()->json([
                    'message' => 'Some products already have a product voucher during these dates.',
                    'conflicts' => $conflicts->map(fn (Voucher $v) => [
                        'id' => $v->id,
                        'code' => $v->code,
                        'label' => $v->label(),
                        'products' => $v->products->pluck('name')->take(5)->values(),
                        'productsCount' => $v->products->count(),
                    ]),
                ], 409);
            }
        }

        $voucher = $this->vouchers->create($seller, $data)->loadCount(['products', 'redemptions']);

        return response()->json(['data' => $this->vouchers->presentForSeller($voucher)], 201);
    }

    /** POST /api/seller/vouchers/{id}/deactivate */
    public function deactivate(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->vouchers->deactivate($this->voucher($request, $id)));
    }

    /** POST /api/seller/vouchers/{id}/reactivate */
    public function reactivate(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->vouchers->reactivate($this->voucher($request, $id)));
    }

    /** POST /api/seller/vouchers/{id}/stock — { usage_limit } (Fully Redeemed only) */
    public function addStock(Request $request, string $id): JsonResponse
    {
        $limit = (int) $request->validate(['usage_limit' => ['required', 'integer', 'min:1', 'max:1000000']])['usage_limit'];

        return $this->respond($this->vouchers->addStock($this->voucher($request, $id), $limit));
    }

    /** DELETE /api/seller/vouchers/{id} — only if never redeemed. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->vouchers->delete($this->voucher($request, $id));

        return response()->json(['data' => ['id' => $id]]);
    }

    private function respond(Voucher $voucher): JsonResponse
    {
        $voucher->loadCount(['products', 'redemptions']);

        return response()->json(['data' => $this->vouchers->presentForSeller($voucher)]);
    }

    private function voucher(Request $request, string $id): Voucher
    {
        return Voucher::where('seller_id', $request->user()->id)->findOrFail($id);
    }
}
