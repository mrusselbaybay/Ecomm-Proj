<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ONLY place products.stock / product_variants.stock may change.
 *
 * Every path here:
 *   - runs inside a DB transaction and takes a row lock (lockForUpdate)
 *     so two concurrent changes can't both read the same "before";
 *   - refuses to let stock go negative (prevents overselling);
 *   - writes exactly one inventory_movements row per change, recording
 *     quantity_before / signed change / quantity_after, the type, the
 *     reason, the actor, and the related order when there is one;
 *   - for a variant product, re-derives products.stock as the sum of its
 *     ACTIVE variants' stock (the variant rows are the source of truth;
 *     products.stock is a cache the buyer storefront reads).
 *
 * Frontend-supplied stock numbers are never trusted: callers pass a
 * signed delta or an order, and this service reads the real "before"
 * from the locked row itself.
 */
class InventoryService
{
    /** Seller-selectable reasons for a manual adjustment. */
    public const MANUAL_REASONS = [
        'restock',
        'damaged',
        'incorrect_count',
        'returned_item',
        'lost_item',
        'other',
    ];

    /** Maps a manual reason to the movement_type stored in the log. */
    private const REASON_MOVEMENT_TYPE = [
        'restock' => 'restock',
        'damaged' => 'damaged',
        'incorrect_count' => 'incorrect_count',
        'returned_item' => 'returned_item',
        'lost_item' => 'lost_item',
        'other' => 'other',
    ];

    /**
     * Manual seller adjustment. `$delta` is signed: +10 adds ten, -3
     * removes three. Never a replacement value.
     *
     * @throws ValidationException on a zero delta, an unknown reason, or
     *                             any result below zero.
     */
    public function adjustManually(
        Profile $actor,
        Product $product,
        ?string $variantId,
        int $delta,
        string $reason,
        ?string $note = null,
    ): InventoryMovement {
        if ($delta === 0) {
            throw ValidationException::withMessages(['delta' => 'Enter a non-zero quantity to add or remove.']);
        }

        if (! in_array($reason, self::MANUAL_REASONS, true)) {
            throw ValidationException::withMessages(['reason' => 'Choose a valid reason for the adjustment.']);
        }

        return DB::transaction(function () use ($actor, $product, $variantId, $delta, $reason, $note) {
            if ($variantId !== null) {
                $variant = ProductVariant::where('product_id', $product->id)
                    ->whereKey($variantId)
                    ->lockForUpdate()
                    ->first();

                if (! $variant) {
                    throw ValidationException::withMessages(['variant_id' => 'That variant does not belong to this product.']);
                }

                $movement = $this->applyToVariant(
                    $variant,
                    $delta,
                    self::REASON_MOVEMENT_TYPE[$reason],
                    $reason,
                    $note,
                    $actor->id,
                    'seller',
                    null,
                );

                $this->syncProductStock($product);

                return $movement;
            }

            if ($product->has_variants) {
                throw ValidationException::withMessages([
                    'variant_id' => 'This product uses variants — adjust the stock of a specific variant.',
                ]);
            }

            $locked = Product::whereKey($product->id)->lockForUpdate()->first();

            return $this->applyToProduct(
                $locked,
                $delta,
                self::REASON_MOVEMENT_TYPE[$reason],
                $reason,
                $note,
                $actor->id,
                'seller',
                null,
            );
        });
    }

    /**
     * Record the starting stock of a freshly created simple product (or a
     * freshly created variant) as an `initial_stock` movement, so the log
     * has a baseline. Call from SellerProductService right after creation,
     * inside its transaction.
     */
    public function recordInitialStock(Product $product, ?ProductVariant $variant = null, ?string $actorId = null): void
    {
        $qty = $variant ? (int) $variant->stock : (int) $product->stock;

        if ($qty === 0) {
            return; // nothing to record for a product that started empty
        }

        InventoryMovement::create([
            'seller_id' => $product->seller_id,
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'order_id' => null,
            'movement_type' => 'initial_stock',
            'reason' => null,
            'note' => 'Initial stock on creation',
            'quantity_before' => 0,
            'quantity_change' => $qty,
            'quantity_after' => $qty,
            'actor_id' => $actorId,
            'actor_type' => 'seller',
        ]);
    }

    /**
     * Log (only) a simple product's stock being changed through the
     * product edit form. The form has already written the new value; this
     * keeps the audit trail complete. The dedicated adjust endpoint is
     * still the preferred path — see SellerProductService::update().
     */
    public function recordFormStockEdit(Product $product, int $before, int $after, ?string $actorId): void
    {
        if ($before === $after) {
            return;
        }

        InventoryMovement::create([
            'seller_id' => $product->seller_id,
            'product_id' => $product->id,
            'variant_id' => null,
            'order_id' => null,
            'movement_type' => 'form_edit',
            'reason' => 'incorrect_count',
            'note' => 'Stock changed on the product edit form',
            'quantity_before' => $before,
            'quantity_change' => $after - $before,
            'quantity_after' => $after,
            'actor_id' => $actorId,
            'actor_type' => 'seller',
        ]);
    }

    /**
     * Deduct stock for a placed order. Call from the checkout transaction
     * (buyer branch) once the order + items exist. Each item must carry
     * product_id, quantity and optionally variant_id.
     *
     * @param  iterable<array{product_id:string, variant_id?:?string, quantity:int}>  $lines
     *
     * @throws ValidationException if any line would drive stock negative.
     */
    public function applySale(Order $order, iterable $lines): void
    {
        DB::transaction(function () use ($order, $lines) {
            foreach ($lines as $line) {
                $qty = (int) $line['quantity'];

                if ($qty <= 0) {
                    continue;
                }

                if (! empty($line['variant_id'])) {
                    $variant = ProductVariant::whereKey($line['variant_id'])->lockForUpdate()->first();

                    if (! $variant) {
                        continue;
                    }

                    $this->applyToVariant($variant, -$qty, 'sale', null, null, null, 'system', $order->id);
                    $this->syncProductStock($variant->product);

                    continue;
                }

                $product = Product::whereKey($line['product_id'])->lockForUpdate()->first();

                if ($product) {
                    $this->applyToProduct($product, -$qty, 'sale', null, null, null, 'system', $order->id);
                }
            }
        });
    }

    /**
     * Restore stock for a cancelled / rejected order (or an approved
     * return), using the movement log as the source of truth for what to
     * put back:
     *   - restores exactly the quantities a prior 'sale' movement
     *     deducted for this order — nothing if the order never deducted
     *     stock (e.g. orders created before checkout wired InventoryService);
     *   - is idempotent: if a restore of this `$type` already exists for
     *     the order, it does nothing, so a repeated cancel request never
     *     inflates stock.
     *
     * `$type` is 'cancellation_restock' or 'return_restock'.
     */
    public function restoreForOrder(Order $order, string $type, ?string $actorId = null): void
    {
        try {
            DB::transaction(function () use ($order, $type, $actorId) {
                $alreadyRestored = InventoryMovement::where('order_id', $order->id)
                    ->where('movement_type', $type)
                    ->exists();

                if ($alreadyRestored) {
                    return;
                }

                $sales = InventoryMovement::where('order_id', $order->id)
                    ->where('movement_type', 'sale')
                    ->get();

                foreach ($sales as $sale) {
                    $qty = abs((int) $sale->quantity_change);

                    if ($qty <= 0) {
                        continue;
                    }

                    if ($sale->variant_id) {
                        $variant = ProductVariant::whereKey($sale->variant_id)->lockForUpdate()->first();

                        if ($variant) {
                            $this->applyToVariant($variant, $qty, $type, null, null, $actorId, $actorId ? 'seller' : 'system', $order->id);
                            $this->syncProductStock($variant->product);
                        }

                        continue;
                    }

                    $product = Product::whereKey($sale->product_id)->lockForUpdate()->first();

                    if ($product) {
                        $this->applyToProduct($product, $qty, $type, null, null, $actorId, $actorId ? 'seller' : 'system', $order->id);
                    }
                }
            });
        } catch (\Throwable $e) {
            // inventory_movements not migrated yet, or a transient DB
            // issue — the cancellation itself must still go through.
            report($e);
        }
    }

    /**
     * Restore explicit lines (used by the buyer checkout / return flows
     * that pass their own line list). `$type` is 'cancellation_restock'
     * or 'return_restock'.
     *
     * @param  iterable<array{product_id:string, variant_id?:?string, quantity:int}>  $lines
     */
    public function restoreStock(Order $order, iterable $lines, string $type, ?string $actorId = null): void
    {
        DB::transaction(function () use ($order, $lines, $type, $actorId) {
            foreach ($lines as $line) {
                $qty = (int) $line['quantity'];

                if ($qty <= 0) {
                    continue;
                }

                if (! empty($line['variant_id'])) {
                    $variant = ProductVariant::whereKey($line['variant_id'])->lockForUpdate()->first();

                    if (! $variant) {
                        continue;
                    }

                    $this->applyToVariant($variant, $qty, $type, null, null, $actorId, $actorId ? 'seller' : 'system', $order->id);
                    $this->syncProductStock($variant->product);

                    continue;
                }

                $product = Product::whereKey($line['product_id'])->lockForUpdate()->first();

                if ($product) {
                    $this->applyToProduct($product, $qty, $type, null, null, $actorId, $actorId ? 'seller' : 'system', $order->id);
                }
            }
        });
    }

    /**
     * Re-derive products.stock for a variant product as the sum of its
     * active variants' stock. No-op for a simple product. Records no
     * movement — products.stock is only a cache here; the movements were
     * written against the variant rows.
     */
    public function syncProductStock(Product $product): void
    {
        if (! $product->has_variants) {
            return;
        }

        $sum = (int) ProductVariant::where('product_id', $product->id)
            ->where('status', 'active')
            ->sum('stock');

        if ((int) $product->stock !== $sum) {
            // Update the column directly to avoid the model's `saving`
            // hooks / status-workflow concerns — this is a cache refresh,
            // not a seller edit.
            Product::whereKey($product->id)->update(['stock' => $sum]);
            $product->setAttribute('stock', $sum);
        }
    }

    // ---- internals -------------------------------------------------------

    private function applyToVariant(
        ProductVariant $variant,
        int $delta,
        string $movementType,
        ?string $reason,
        ?string $note,
        ?string $actorId,
        string $actorType,
        ?string $orderId,
    ): InventoryMovement {
        $before = (int) $variant->stock;
        $after = $before + $delta;

        if ($after < 0) {
            throw ValidationException::withMessages([
                'delta' => "Only {$before} in stock for this variant — can't remove {$this->abs($delta)}.",
            ]);
        }

        $variant->forceFill(['stock' => $after])->save();

        return $this->log($variant->product_id, $variant->id, $orderId, $variant->product?->seller_id, $movementType, $reason, $note, $before, $delta, $after, $actorId, $actorType);
    }

    private function applyToProduct(
        Product $product,
        int $delta,
        string $movementType,
        ?string $reason,
        ?string $note,
        ?string $actorId,
        string $actorType,
        ?string $orderId,
    ): InventoryMovement {
        $before = (int) $product->stock;
        $after = $before + $delta;

        if ($after < 0) {
            throw ValidationException::withMessages([
                'delta' => "Only {$before} in stock — can't remove {$this->abs($delta)}.",
            ]);
        }

        $product->forceFill(['stock' => $after])->save();

        return $this->log($product->id, null, $orderId, $product->seller_id, $movementType, $reason, $note, $before, $delta, $after, $actorId, $actorType);
    }

    private function log(
        string $productId,
        ?string $variantId,
        ?string $orderId,
        ?string $sellerId,
        string $movementType,
        ?string $reason,
        ?string $note,
        int $before,
        int $change,
        int $after,
        ?string $actorId,
        string $actorType,
    ): InventoryMovement {
        return InventoryMovement::create([
            'seller_id' => $sellerId ?? Product::whereKey($productId)->value('seller_id'),
            'product_id' => $productId,
            'variant_id' => $variantId,
            'order_id' => $orderId,
            'movement_type' => $movementType,
            'reason' => $reason,
            'note' => $note,
            'quantity_before' => $before,
            'quantity_change' => $change,
            'quantity_after' => $after,
            'actor_id' => $actorId,
            'actor_type' => $actorType,
        ]);
    }

    private function abs(int $n): int
    {
        return $n < 0 ? -$n : $n;
    }
}
