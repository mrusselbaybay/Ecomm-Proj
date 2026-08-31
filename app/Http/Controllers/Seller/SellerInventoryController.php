<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\AdjustStockRequest;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\InventoryService;
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
