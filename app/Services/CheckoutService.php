<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Profile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    /**
     * Flat per-parcel shipping fees, mirroring the options presented in
     * resources/js/buyer/components/Checkout.vue's `shippingOptions`.
     * Charged once per seller order (each seller ships their own parcel),
     * not once per cart.
     */
    private const SHIPPING_FEES = [
        'standard' => 60.0,
        'express' => 120.0,
    ];

    /**
     * @param  Profile  $buyer
     * @param  array{items: array<int, array{product_id: string, variant_id?: string, quantity: int, variation?: string}>,
     *                delivery_address: array{recipient_name: string, contact_number?: string, address: string},
     *                shipping_method?: string, payment_method?: string} $payload
     * @return Collection<int, Order> The created orders (one per seller), loaded with items.
     *
     * @throws ValidationException if any item is invalid, out of stock, or
     *                              the requested quantity exceeds what's available.
     */
    public function checkout(Profile $buyer, array $payload): Collection
    {
        $shippingMethod = $payload['shipping_method'] ?? 'standard';
        $shippingFee = self::SHIPPING_FEES[$shippingMethod] ?? self::SHIPPING_FEES['standard'];
        $address = $payload['delivery_address'];

        return DB::transaction(function () use ($buyer, $payload, $shippingMethod, $shippingFee, $address) {
            // Lock every product AND variant row involved up front so two
            // simultaneous checkouts against the same product/variant
            // can't both read stale stock and both succeed.
            $productIds = collect($payload['items'])->pluck('product_id')->unique()->values();
            $variantIds = collect($payload['items'])->pluck('variant_id')->filter()->unique()->values();

            $products = Product::query()
                ->whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $variants = ProductVariant::query()
                ->with('optionValues.option')
                ->whereIn('id', $variantIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $itemsBySeller = collect($payload['items'])
                ->map(function (array $line) use ($products, $variants) {
                    $product = $products->get($line['product_id']);

                    if (!$product || $product->status !== 'active') {
                        throw ValidationException::withMessages([
                            'items' => 'One of the items in your cart is no longer available.',
                        ]);
                    }

                    $quantity = (int) $line['quantity'];

                    if ($quantity < 1) {
                        throw ValidationException::withMessages([
                            'items' => "Invalid quantity for \"{$product->name}\".",
                        ]);
                    }

                    $variantId = $line['variant_id'] ?? null;
                    $variant = $variantId ? $variants->get($variantId) : null;

                    // Never trust the client's own price/availability claim
                    // for a variant product — re-derive everything from the
                    // locked row, and require a real variant to be selected
                    // at all if the product has any (mirrors the buyer UI
                    // requirement, enforced again here server-side).
                    if ($product->has_variants) {
                        if (!$variant || $variant->product_id !== $product->id) {
                            throw ValidationException::withMessages([
                                'items' => "Please select a valid option for \"{$product->name}\".",
                            ]);
                        }

                        if ($variant->status !== 'active') {
                            throw ValidationException::withMessages([
                                'items' => "The selected option for \"{$product->name}\" is no longer available.",
                            ]);
                        }

                        if ($variant->stock < $quantity) {
                            throw ValidationException::withMessages([
                                'items' => "Insufficient stock for \"{$product->name}\". Only {$variant->stock} left.",
                            ]);
                        }
                    } elseif ($product->stock < $quantity) {
                        throw ValidationException::withMessages([
                            'items' => "Insufficient stock for \"{$product->name}\". Only {$product->stock} left.",
                        ]);
                    }

                    $unitPrice = $variant ? (float) ($variant->price ?? $product->price) : (float) $product->price;

                    $variantOptions = $variant
                        ? $variant->optionValues->mapWithKeys(
                            fn ($ov) => [$ov->option?->name ?? '' => $ov->value],
                        )->all()
                        : null;

                    $variantLabel = $variantOptions
                        ? implode(', ', array_map(
                            fn ($k, $v) => "{$k}: {$v}",
                            array_keys($variantOptions),
                            $variantOptions,
                        ))
                        : ($line['variation'] ?? null);

                    return [
                        'product' => $product,
                        'variant' => $variant,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'variant_label' => $variantLabel,
                        'variant_options' => $variantOptions,
                    ];
                })
                ->groupBy(fn (array $line) => $line['product']->seller_id);

            $orders = collect();

            foreach ($itemsBySeller as $sellerId => $lines) {
                $order = $this->createOrderForSeller(
                    $buyer,
                    (string) $sellerId,
                    $lines,
                    $address,
                    $shippingMethod,
                    $shippingFee,
                    (string) ($payload['payment_method'] ?? 'cod'),
                );

                $orders->push($order);
            }

            return $orders;
        });
    }

    private function createOrderForSeller(
        Profile $buyer,
        string $sellerId,
        Collection $lines,
        array $address,
        string $shippingMethod,
        float $shippingFee,
        string $paymentMethod,
    ): Order {
        $subtotal = $lines->sum(fn (array $line) => $line['unit_price'] * $line['quantity']);

        $order = Order::create([
            'order_number' => $this->generateOrderNumber(),
            'seller_id' => $sellerId,
            'buyer_profile_id' => $buyer->id,
            'recipient_name' => $address['recipient_name'],
            'recipient_contact_no' => $address['contact_number'] ?? null,
            'shipping_street' => $address['address'],
            'status' => 'New',
            'payment_method' => $paymentMethod,
            'payment_status' => 'Unpaid',
            'subtotal' => $subtotal,
            'shipping_fee' => $shippingFee,
            'tax' => 0,
            'discount' => 0,
            'total' => $subtotal + $shippingFee,
            'shipping_service' => $shippingMethod,
            'placed_at' => now(),
        ]);

        foreach ($lines as $line) {
            /** @var Product $product */
            $product = $line['product'];
            /** @var ProductVariant|null $variant */
            $variant = $line['variant'];
            $quantity = $line['quantity'];
            $unitPrice = $line['unit_price'];

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'category' => $product->category,
                'sku' => $variant?->sku ?? $product->sku,
                'variant' => $line['variant_label'],
                'variant_id' => $variant?->id,
                'variant_sku' => $variant?->sku,
                'variant_options' => $line['variant_options'],
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'subtotal' => $unitPrice * $quantity,
            ]);

            // Safe under the row locks taken in checkout(): no other
            // request can have read/modified this stock value since.
            if ($variant) {
                $variant->decrement('stock', $quantity);
            } else {
                $product->decrement('stock', $quantity);
            }
        }

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'status' => 'New',
            'note' => 'Order placed by buyer.',
            'changed_by' => $buyer->id,
        ]);

        return $order->load('items');
    }

    private function generateOrderNumber(): string
    {
        do {
            $candidate = 'SN-' . random_int(10000, 99999);
        } while (Order::where('order_number', $candidate)->exists());

        return $candidate;
    }
}