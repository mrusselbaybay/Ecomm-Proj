<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\AdjustStockRequest;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\InventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Manual stock adjustments + the movement history behind
 * resources/js/seller/components/Inventory.vue.
 *
 * Scoped by seller_id like every other seller controller: a product that
 * isn't the caller's resolves as a plain 404.
 */
class SellerInventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * POST /api/seller/products/{id}/stock-adjustments
     *
     * Body: { variant_id?, delta (signed, non-zero), reason, note? }
     */
    public function adjust(AdjustStockRequest $request, string $id): JsonResponse
    {
        $seller = $request->user();

        $product = Product::with(['options.values', 'variants.optionValues.option'])
            ->where('seller_id', $seller->id)
            ->find($id);

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $variantId = $request->validated('variant_id');

        try {
            $movement = $this->inventory->adjustManually(
                $seller,
                $product,
                $variantId,
                (int) $request->validated('delta'),
                $request->validated('reason'),
                $request->validated('note'),
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Could not adjust the stock.',
                'errors' => $e->errors(),
            ], 422);
        }

        // Fresh stock numbers so Inventory.vue can patch its local copy
        // without re-fetching the whole product list.
        $product = $product->fresh(['variants']);
        $variant = $variantId ? $product->variants->firstWhere('id', $variantId) : null;
        $variant?->setRelation('product', $product);

        return response()->json([
            'data' => [
                'movement' => $this->transformMovement($movement->load(['actor', 'variant.optionValues.option', 'order'])),
                'stock' => [
                    'productId' => $product->id,
                    'variantId' => $variant?->id,
                    'productStock' => $product->effectiveStock(),
                    'productStockStatus' => $product->stockStatus(),
                    'productIsOutOfStock' => $product->isOutOfStock(),
                    'variantStock' => $variant ? (int) $variant->stock : null,
                    'variantStockStatus' => $variant?->stockStatus(),
                    'variantIsOutOfStock' => $variant?->isOutOfStock(),
                ],
            ],
        ]);
    }

    /**
     * GET /api/seller/products/{id}/stock-movements?variant_id=&page=
     */
    public function movements(Request $request, string $id): JsonResponse
    {
        $seller = $request->user();

        $product = Product::where('seller_id', $seller->id)->find($id);

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $query = InventoryMovement::with(['actor', 'variant.optionValues.option', 'order'])
            ->where('product_id', $product->id)
            ->orderByDesc('created_at');

        if ($variantId = $request->string('variant_id')->toString()) {
            $query->where('variant_id', $variantId);
        }

        $paginated = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (InventoryMovement $m) => $this->transformMovement($m))->all(),
            'meta' => [
                'currentPage' => $paginated->currentPage(),
                'lastPage' => $paginated->lastPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * GET /api/seller/products/stock-trend
     *
     * Real day-by-day In Stock / Low Stock / Out of Stock counts across
     * the seller's current catalog, reconstructed from inventory_movements
     * — the append-only audit log InventoryService writes for every stock
     * change (including a real `initial_stock` row when a product is
     * first created), rather than a snapshot table this project doesn't
     * have. For a given product and day, its stock as of that day is the
     * `quantity_after` of its most recent movement at or before that
     * day's end (summed across active-at-creation variants for a variant
     * product); a product with no movement yet by that day didn't exist
     * yet and is left out of that day's totals rather than counted as 0.
     *
     * Fixed to the CURRENT Sun–Sat calendar week (matching the reference
     * mock's S M T W T F S order) rather than a rolling "last 7 days"
     * window — a rolling window ends on whatever weekday "today" happens
     * to be, so the same two letters (e.g. both Tuesdays and Saturdays)
     * could appear at both ends and read as confusing. A day later than
     * today naturally reflects today's real totals carried forward
     * (nothing has happened there yet to change them) rather than a
     * fabricated projection.
     *
     * Two disclosed simplifications, both consistent with how the rest of
     * this page already reads "stock status": (1) each product's CURRENT
     * low_stock_threshold is applied to every past day too, since
     * threshold history isn't tracked; (2) a variant's active/inactive
     * flag is only known as of now, not retroactively, so a variant with
     * any movement counts on every day it had one.
     */
    public function stockTrend(Request $request): JsonResponse
    {
        $seller = $request->user();
        $tz = config('app.timezone');
        $days = 7;

        $now = CarbonImmutable::now($tz);
        $start = $now->startOfWeek(\Carbon\CarbonInterface::SUNDAY);
        $end = $start->copy()->addDays($days - 1)->endOfDay();

        $products = Product::where('seller_id', $seller->id)
            ->get(['id', 'has_variants', 'low_stock_threshold']);

        if ($products->isEmpty()) {
            return response()->json(['data' => ['days' => []]]);
        }

        $movements = InventoryMovement::whereIn('product_id', $products->pluck('id'))
            ->where('created_at', '<=', $end)
            ->orderBy('created_at')
            ->get(['product_id', 'variant_id', 'quantity_after', 'created_at']);

        $movementsByProduct = $movements->groupBy('product_id');

        $days_ = [];

        for ($i = 0; $i < $days; $i++) {
            $dayEnd = $start->copy()->addDays($i)->endOfDay();

            $inStock = 0;
            $lowStock = 0;
            $outOfStock = 0;

            foreach ($products as $product) {
                $upToDay = $movementsByProduct
                    ->get($product->id, collect())
                    ->filter(fn ($m) => $m->created_at <= $dayEnd);

                if ($upToDay->isEmpty()) {
                    continue; // no stock event yet — product didn't exist as of this day
                }

                if ($product->has_variants) {
                    $qty = $upToDay
                        ->whereNotNull('variant_id')
                        ->groupBy('variant_id')
                        ->map(fn ($g) => $g->last()->quantity_after)
                        ->sum();
                } else {
                    $qty = $upToDay->last()->quantity_after;
                }

                $threshold = (int) ($product->low_stock_threshold ?? Product::DEFAULT_LOW_STOCK_THRESHOLD);

                if ($qty <= 0) {
                    $outOfStock++;
                } elseif ($qty <= $threshold) {
                    $lowStock++;
                } else {
                    $inStock++;
                }
            }

            $known = $inStock + $lowStock + $outOfStock;

            $days_[] = [
                'date' => $dayEnd->toDateString(),
                'label' => strtoupper(substr($dayEnd->format('D'), 0, 1)),
                'inStock' => $inStock,
                'lowStock' => $lowStock,
                'outOfStock' => $outOfStock,
                'healthyPct' => $known > 0 ? round(($inStock / $known) * 100, 1) : null,
            ];
        }

        return response()->json(['data' => ['days' => $days_]]);
    }

    private function transformMovement(InventoryMovement $m): array
    {
        $variantLabel = $m->variant
            ? $m->variant->optionValues
                ->map(fn ($ov) => $ov->value)
                ->join(' / ')
            : null;

        return [
            'id' => $m->id,
            'type' => $m->movement_type,
            'reason' => $m->reason,
            'note' => $m->note,
            'quantityBefore' => $m->quantity_before,
            'quantityChange' => $m->quantity_change,
            'quantityAfter' => $m->quantity_after,
            'variantId' => $m->variant_id,
            'variantLabel' => $variantLabel ?: null,
            'orderNumber' => $m->order?->order_number,
            'actor' => $m->actor_type === 'system' ? 'System' : ($m->actor?->full_name ?? 'Seller'),
            'actorType' => $m->actor_type,
            'createdAt' => optional($m->created_at)->toIso8601String(),
        ];
    }
}
