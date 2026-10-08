<?php

namespace App\Services;

use App\Exceptions\CheckoutQuoteChanged;
use App\Http\Controllers\ProductImageController;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Profile;
use App\Models\SellerDetail;
use App\Support\CheckoutOptions;
use App\Support\ProductImage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Checkout's single source of truth for what an order costs.
 *
 *   quote()     prices a checkout without changing anything: every line at
 *               today's price, whether it can be bought (and if not, why),
 *               the shipping options and fee per seller, and the totals.
 *               The checkout page renders only from this.
 *   checkout()  places it: the same rules under row locks, one order per
 *               seller. Any line that can't be bought stops it with that
 *               line's reason; if the total no longer matches what the
 *               buyer last saw (expected_total), it stops with
 *               CheckoutQuoteChanged so the buyer reviews the new amounts
 *               before confirming again.
 *
 * Supported today (CheckoutOptions): flat shipping fees per seller parcel,
 * chosen per seller; cash on delivery only. No vouchers or discounts exist,
 * so both are always 0 — voucher_code is ignored.
 */
class CheckoutService
{
    /**
     * @param  array{items: array<int, array{product_id: string, variant_id?: string|null, quantity: int, variation?: string|null}>, shipping_methods?: array<string, string>, shipping_method?: string|null, payment_method?: string|null}  $payload
     * @return array<string, mixed>
     */
    public function quote(Profile $buyer, array $payload): array
    {
        [$products, $variants] = $this->load($payload['items'], lock: false);
        $lines = $this->resolveLines($payload['items'], $products, $variants);

        return $this->buildQuote($lines, $payload);
    }

    /**
     * @param  array{items: array<int, array{product_id: string, variant_id?: string|null, quantity: int, variation?: string|null}>,
     *                delivery_address: array{recipient_name: string, contact_number?: string|null, address: string, city?: string|null, province?: string|null},
     *                shipping_methods?: array<string, string>, shipping_method?: string|null, payment_method?: string|null, expected_total?: float|int|string|null}  $payload
     * @return Collection<int, Order> The created orders (one per seller), loaded with items.
     *
     * @throws ValidationException if a line can't be bought
     * @throws CheckoutQuoteChanged if the total changed since the buyer's quote
     */
    public function checkout(Profile $buyer, array $payload): Collection
    {
        return DB::transaction(function () use ($buyer, $payload) {
            // Lock every product AND variant row involved up front so two
            // simultaneous checkouts against the same product/variant
            // can't both read stale stock and both succeed.
            [$products, $variants] = $this->load($payload['items'], lock: true);
            $lines = $this->resolveLines($payload['items'], $products, $variants);

            $problem = $lines->first(fn (array $line) => $line['problem'] !== null);

            if ($problem) {
                throw ValidationException::withMessages([
                    'items' => "\"{$problem['name']}\": {$problem['problem']}",
                ]);
            }

            $quote = $this->buildQuote($lines, $payload);

            if (isset($payload['expected_total']) && $payload['expected_total'] !== null
                && abs((float) $payload['expected_total'] - $quote['totals']['total']) > 0.004) {
                throw new CheckoutQuoteChanged($quote);
            }

            $orders = collect();

            foreach ($lines->groupBy('seller_id') as $sellerId => $sellerLines) {
                $shipping = $quote['sellers'][array_search($sellerId, array_column($quote['sellers'], 'seller_id'), true)]['shipping'];

                $orders->push($this->createOrderForSeller(
                    $buyer,
                    (string) $sellerId,
                    $sellerLines,
                    $payload['delivery_address'],
                    $shipping['method'],
                    (float) $shipping['fee'],
                    (string) ($payload['payment_method'] ?? 'cod'),
                ));
            }

            return $orders;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{0: Collection<string, Product>, 1: Collection<string, ProductVariant>}
     */
    private function load(array $items, bool $lock): array
    {
        $productIds = collect($items)->pluck('product_id')->unique()->values();
        $variantIds = collect($items)->pluck('variant_id')->filter()->unique()->values();

        // Photos as links only (ProductImage::liteSql()) — never the inline
        // photo itself.
        $products = ProductImage::selectWithLiteImages(
            Product::query()->with('seller:id,account_status'),
            ['products.id', 'products.seller_id', 'products.name', 'products.category', 'products.sku', 'products.price', 'products.stock', 'products.status', 'products.has_variants', 'products.updated_at'],
        )
            ->whereIn('products.id', $productIds)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get()
            ->keyBy('id');

        $variants = ProductImage::selectVariantWithLiteImage(
            ProductVariant::query()->with('optionValues.option'),
            ['product_variants.id', 'product_variants.product_id', 'product_variants.sku', 'product_variants.price', 'product_variants.stock', 'product_variants.status'],
        )
            ->whereIn('product_variants.id', $variantIds)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get()
            ->keyBy('id');

        return [$products, $variants];
    }

    /**
     * Every requested line at today's price, with `problem` set (and the
     * line left out of totals) when it can't be bought as requested.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function resolveLines(array $items, Collection $products, Collection $variants): Collection
    {
        return collect($items)->values()->map(function (array $line, int $index) use ($products, $variants) {
            /** @var Product|null $product */
            $product = $products->get($line['product_id']);
            $variantId = $line['variant_id'] ?? null;
            /** @var ProductVariant|null $variant */
            $variant = $variantId ? $variants->get($variantId) : null;
            $quantity = max(0, (int) $line['quantity']);

            $problem = null;
            $stock = null;

            if (! $product || $product->status !== 'active' || $product->seller?->account_status !== 'active') {
                $problem = 'This product is no longer available.';
            } elseif ($quantity < 1) {
                $problem = 'Choose a quantity of at least 1.';
            } elseif ($product->has_variants) {
                if (! $variantId) {
                    $problem = 'Choose an option for this product in your cart.';
                } elseif (! $variant || $variant->product_id !== $product->id || $variant->status !== 'active') {
                    $problem = 'This option is no longer available.';
                } else {
                    $stock = (int) $variant->stock;
                }
            } else {
                $stock = (int) $product->stock;
            }

            if ($problem === null && $stock !== null) {
                if ($stock < 1) {
                    $problem = 'Out of stock.';
                } elseif ($stock < $quantity) {
                    $problem = "Only {$stock} left — lower the quantity in your cart.";
                }
            }

            $unitPrice = $product
                ? (float) ($variant && $variant->price !== null ? $variant->price : $product->price)
                : 0.0;

            $variantOptions = $variant
                ? $variant->optionValues->mapWithKeys(fn ($ov) => [$ov->option?->name ?? '' => $ov->value])->all()
                : null;

            $variantLabel = $variantOptions
                ? implode(', ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($variantOptions), $variantOptions))
                : ($line['variation'] ?? null);

            $image = null;

            if ($variant) {
                $image = ProductImage::normalize($variant->image, fn () => ProductImage::inlineUrl(
                    $product->id, 0, $product->updated_at, ProductImageController::CARD_WIDTH, $variant->id,
                ));
            }

            $image ??= $product ? ProductImage::cardUrl($product) : null;

            return [
                'key' => $line['product_id'].'-'.($variantId ?: 'simple'),
                'index' => $index,
                'product' => $product,
                'variant' => $variant,
                'product_id' => $line['product_id'],
                'variant_id' => $variantId,
                'seller_id' => $product?->seller_id,
                'name' => $product?->name ?? ($line['name'] ?? 'Product'),
                'variation' => $variantLabel,
                'variant_options' => $variantOptions,
                'image' => $image,
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'line_total' => round($unitPrice * $quantity, 2),
                'stock' => $stock,
                'problem' => $problem,
            ];
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function buildQuote(Collection $lines, array $payload): array
    {
        $storeNames = SellerDetail::query()
            ->whereIn('profile_id', $lines->pluck('seller_id')->filter()->unique()->values())
            ->pluck('business_name', 'profile_id');

        $chosen = $payload['shipping_methods'] ?? [];
        $fallback = $payload['shipping_method'] ?? CheckoutOptions::DEFAULT_SHIPPING;

        $sellers = $lines
            ->groupBy(fn (array $line) => $line['seller_id'] ?? 'unavailable')
            ->map(function (Collection $sellerLines, string $sellerId) use ($storeNames, $chosen, $fallback) {
                $buyable = $sellerLines->whereNull('problem');
                $requested = (string) ($chosen[$sellerId] ?? $fallback);
                $method = isset(CheckoutOptions::SHIPPING[$requested]) ? $requested : CheckoutOptions::DEFAULT_SHIPPING;
                // A parcel only ships (and is only charged) if something in
                // it can be bought.
                $fee = $buyable->isNotEmpty() ? CheckoutOptions::shippingFee($method) : 0.0;
                $subtotal = round($buyable->sum('line_total'), 2);

                return [
                    'seller_id' => $sellerId === 'unavailable' ? null : $sellerId,
                    'store_name' => $storeNames[$sellerId] ?? ($sellerId === 'unavailable' ? 'Unavailable items' : 'BuyTheWay Seller'),
                    'items' => $sellerLines->map(fn (array $line) => [
                        'key' => $line['key'],
                        'product_id' => $line['product_id'],
                        'variant_id' => $line['variant_id'],
                        'name' => $line['name'],
                        'variation' => $line['variation'],
                        'image' => $line['image'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'line_total' => $line['line_total'],
                        'stock' => $line['stock'],
                        'available' => $line['problem'] === null,
                        'problem' => $line['problem'],
                    ])->values()->all(),
                    'shipping' => [
                        'method' => $method,
                        'fee' => $fee,
                        'eta' => CheckoutOptions::SHIPPING[$method]['eta'],
                        'options' => collect(CheckoutOptions::SHIPPING)->map(fn (array $option, string $id) => [
                            'id' => $id,
                            'name' => $option['name'],
                            'fee' => $option['fee'],
                            'eta' => $option['eta'],
                        ])->values()->all(),
                    ],
                    'subtotal' => $subtotal,
                    'store_discount' => 0.0,
                    'total' => round($subtotal + $fee, 2),
                ];
            })
            ->values()
            ->all();

        $subtotal = round(array_sum(array_column($sellers, 'subtotal')), 2);
        $shipping = round(array_sum(array_map(fn (array $s) => $s['shipping']['fee'], $sellers)), 2);
        $paymentFee = 0.0;

        return [
            'sellers' => $sellers,
            'totals' => [
                'item_count' => (int) $lines->whereNull('problem')->sum('quantity'),
                'subtotal' => $subtotal,
                'shipping' => $shipping,
                'store_discount' => 0.0,
                'platform_discount' => 0.0,
                'payment_fee' => $paymentFee,
                'total' => round($subtotal + $shipping + $paymentFee, 2),
            ],
            'payment_methods' => CheckoutOptions::toArray()['payment'],
            'vouchers_supported' => false,
            'can_place' => $lines->isNotEmpty() && $lines->every(fn (array $line) => $line['problem'] === null),
        ];
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
        $subtotal = $lines->sum('line_total');

        $order = Order::create([
            'order_number' => $this->generateOrderNumber(),
            'seller_id' => $sellerId,
            'buyer_profile_id' => $buyer->id,
            'recipient_name' => $address['recipient_name'],
            'recipient_contact_no' => $address['contact_number'] ?? null,
            'shipping_street' => $address['address'],
            'shipping_municipality_name' => $address['city'] ?? null,
            'shipping_province_name' => $address['province'] ?? null,
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

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'category' => $product->category,
                'sku' => $variant?->sku ?? $product->sku,
                'variant' => $line['variation'],
                'variant_id' => $variant?->id,
                'variant_sku' => $variant?->sku,
                'variant_options' => $line['variant_options'],
                'unit_price' => $line['unit_price'],
                'quantity' => $line['quantity'],
                'subtotal' => $line['line_total'],
            ]);

            // Safe under the row locks taken in checkout(): no other
            // request can have read/modified this stock value since.
            if ($variant) {
                ProductVariant::whereKey($variant->id)->decrement('stock', $line['quantity']);
            } else {
                Product::whereKey($product->id)->decrement('stock', $line['quantity']);
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
            $candidate = 'SN-'.random_int(10000, 99999);
        } while (Order::where('order_number', $candidate)->exists());

        return $candidate;
    }
}
