<?php

namespace App\Services;

use App\Models\BuyerAddress;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Profile;
use App\Services\Vouchers\PlatformVoucherService;
use App\Services\Vouchers\VoucherService;
use App\Support\StreetCleaner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly PlatformVoucherService $platformVouchers,
    ) {}

    /**
     * Flat per-parcel shipping options — the single source of truth for
     * fees. Checkout.vue fetches these via shippingOptions() rather than
     * hardcoding them. Charged once per seller order (each seller ships
     * their own parcel), not once per cart.
     */
    private const SHIPPING_OPTIONS = [
        'standard' => ['name' => 'Standard Delivery', 'description' => 'Estimated 3-5 days', 'fee' => 60.0],
        'express' => ['name' => 'Express Delivery', 'description' => 'Estimated 1-2 days', 'fee' => 120.0],
        'same_day' => ['name' => 'Same Day Delivery', 'description' => 'Delivered today, order by 2 PM', 'fee' => 220.0],
    ];

    public static function shippingFee(string $method): ?float
    {
        return self::SHIPPING_OPTIONS[$method]['fee'] ?? null;
    }

    /**
     * Shipping options for a cart, with Same Day only available when every
     * seller ships from the buyer's own province.
     *
     * @param  array<int, string>  $productIds
     * @return array{sellerCount: int, options: array<int, array{id: string, name: string, description: string, fee: float, available: bool, unavailableReason: ?string}>}
     */
    public function shippingOptions(Profile $buyer, array $productIds, ?string $addressId = null): array
    {
        $sellerIds = Product::query()->whereIn('id', $productIds)->distinct()->pluck('seller_id')->all();
        $sameDay = $this->sameDayAvailable($this->destination($buyer, $addressId), Profile::query()->with('address')->whereIn('id', $sellerIds)->get());

        $options = collect(self::SHIPPING_OPTIONS)->map(fn (array $o, string $id) => [
            'id' => $id,
            ...$o,
            'available' => $id !== 'same_day' || $sameDay,
            'unavailableReason' => $id === 'same_day' && ! $sameDay
                ? 'Only available when the seller is in your province.'
                : null,
        ])->values()->all();

        return ['sellerCount' => count($sellerIds), 'options' => $options];
    }

    /** @param  Collection<int, Profile>  $sellers */
    private function sameDayAvailable(array $destination, Collection $sellers): bool
    {
        $province = $destination['province_code'];

        return $province !== null && $sellers->isNotEmpty()
            && $sellers->every(fn (Profile $s) => $s->address?->province_code === $province);
    }

    /**
     * Structured delivery destination for routing: the chosen saved address
     * (buyer_addresses) when one is given, otherwise the buyer's profile
     * address (public.addresses) — the pre-address-book behaviour.
     *
     * @return array{address: ?BuyerAddress, region_name: ?string, province_code: ?string, province_name: ?string, municipality_name: ?string, barangay: ?string, latitude: ?float, longitude: ?float}
     *
     * @throws ValidationException when the saved address isn't the buyer's or lacks PSGC fields.
     */
    public function destination(Profile $buyer, ?string $addressId): array
    {
        if ($addressId) {
            $saved = BuyerAddress::where('buyer_profile_id', $buyer->id)->whereKey($addressId)->first();

            if (! $saved) {
                throw ValidationException::withMessages(['delivery_address' => 'That saved address no longer exists. Please pick another.']);
            }

            if (! $saved->isRoutable()) {
                throw ValidationException::withMessages(['delivery_address' => 'Please complete the province, city and barangay of this address first.']);
            }

            return [
                'address' => $saved,
                'region_name' => $saved->region_name,
                'province_code' => $saved->province_code,
                'province_name' => $saved->province,
                'municipality_name' => $saved->city,
                'barangay' => $saved->barangay,
                'latitude' => $saved->latitude,
                'longitude' => $saved->longitude,
            ];
        }

        $profile = $buyer->loadMissing('address')->address;

        return [
            'address' => null,
            'region_name' => $profile?->region_name,
            'province_code' => $profile?->province_code,
            'province_name' => $profile?->province_name,
            'municipality_name' => $profile?->municipality_name,
            'barangay' => $profile?->barangay,
            'latitude' => $profile?->latitude,
            'longitude' => $profile?->longitude,
        ];
    }

    /**
     * @param  array{items: array<int, array{product_id: string, variant_id?: string, quantity: int, variation?: string}>,
     *                delivery_address: array{recipient_name: string, contact_number?: string, address: string},
     *                shipping_method?: string, payment_method?: string} $payload
     * @return Collection<int, Order> The created orders (one per seller), loaded with items.
     *
     * @throws ValidationException if any item is invalid, out of stock, or
     *                             the requested quantity exceeds what's available.
     */
    public function checkout(Profile $buyer, array $payload): Collection
    {
        $shippingMethod = $payload['shipping_method'] ?? 'standard';

        if (! isset(self::SHIPPING_OPTIONS[$shippingMethod])) {
            throw ValidationException::withMessages(['shipping_method' => 'Please select a valid shipping option.']);
        }

        $shippingFee = self::SHIPPING_OPTIONS[$shippingMethod]['fee'];
        $address = $payload['delivery_address'];
        $destination = $this->destination($buyer, $address['address_id'] ?? null);

        return DB::transaction(function () use ($buyer, $payload, $shippingMethod, $shippingFee, $address, $destination) {
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

            $voucherSelection = $this->voucherSelection($buyer, $payload);

            $itemsBySeller = collect($payload['items'])
                ->map(function (array $line) use ($products, $variants) {
                    $product = $products->get($line['product_id']);

                    if (! $product || $product->status !== 'active') {
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
                        if (! $variant || $variant->product_id !== $product->id) {
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

            // Loaded once for every seller in this checkout rather than
            // re-queried per order line — createOrderForSeller() needs
            // each seller's structured address to snapshot the parcel's
            // pickup location (see below).
            $sellers = Profile::query()
                ->with('address')
                ->whereIn('id', $itemsBySeller->keys())
                ->get()
                ->keyBy('id');

            if ($shippingMethod === 'same_day' && ! $this->sameDayAvailable($destination, $sellers)) {
                throw ValidationException::withMessages([
                    'shipping_method' => 'Same Day Delivery is only available when the seller is in your province.',
                ]);
            }

            // Seller vouchers first (per seller order), then the cart-wide
            // platform pass on top of them. Both lock what they use.
            $itemsBySeller = $itemsBySeller->map(fn (Collection $lines) => $lines->values());
            $sellerApplied = [];
            foreach ($itemsBySeller as $sellerId => $lines) {
                $ids = $voucherSelection[(string) $sellerId] ?? [];
                $sellerApplied[$sellerId] = $this->vouchers->applySelection(
                    $buyer,
                    (string) $sellerId,
                    $lines->map(fn (array $l) => ['product_id' => $l['product']->id, 'subtotal' => $l['unit_price'] * $l['quantity']])->all(),
                    $shippingFee,
                    $ids['discount'] ?? null,
                    $ids['shipping'] ?? null,
                );
            }

            $platformApplied = $this->platformVouchers->applySelection(
                $buyer,
                $destination['region_name'],
                $this->platformContext($itemsBySeller, $sellerApplied),
                $shippingFee,
                $payload['platform_vouchers']['discount_voucher_id'] ?? null,
                $payload['platform_vouchers']['shipping_voucher_id'] ?? null,
            );
            $checkoutId = (string) Str::uuid();

            $orders = collect();

            foreach ($itemsBySeller as $sellerId => $lines) {
                $order = $this->createOrderForSeller(
                    $buyer,
                    $sellers->get((string) $sellerId),
                    (string) $sellerId,
                    $lines,
                    $address,
                    $destination,
                    $shippingMethod,
                    $shippingFee,
                    (string) ($payload['payment_method'] ?? 'cod'),
                    $sellerApplied[$sellerId],
                    $platformApplied['orders'][$sellerId] ?? null,
                    $checkoutId,
                );

                $orders->push($order);
            }

            // Feeds the "most recently used" ordering / default fallback.
            $destination['address']?->forceFill(['last_used_at' => now()])->save();

            return $orders;
        });
    }

    /**
     * delivery_address.address arrives as one flattened line ("street,
     * barangay, municipality, province, region"), while the structured
     * shipping_* columns are filled separately — and every reader
     * re-joins street + those columns. Strip the trailing segments that
     * duplicate them so the joined address isn't repeated twice.
     */
    private function streetPart(string $line, array $destination): string
    {
        return (string) StreetCleaner::clean($line, [
            $destination['region_name'],
            $destination['province_name'],
            $destination['municipality_name'],
            $destination['barangay'],
        ]);
    }

    private function createOrderForSeller(
        Profile $buyer,
        ?Profile $seller,
        string $sellerId,
        Collection $lines,
        array $address,
        array $destination,
        string $shippingMethod,
        float $shippingFee,
        string $paymentMethod,
        array $applied,
        ?array $platform,
        string $checkoutId,
    ): Order {
        $subtotal = $lines->sum(fn (array $line) => $line['unit_price'] * $line['quantity']);

        $itemDiscount = $applied['discount']['amount'] ?? 0.0;
        $sellerShipping = $applied['shipping']['amount'] ?? 0.0;
        // Seller-funded: `discount` is the seller's voucher cost (items + shipping).
        $discount = round($itemDiscount + $sellerShipping, 2);
        // Platform-funded share of this order (comes out of the platform's cut, not the seller's).
        $platformShipping = $platform['shipping'] ?? 0.0;
        $platformDiscount = round(($platform['discount'] ?? 0.0) + $platformShipping, 2);
        $shippingDiscount = round($sellerShipping + $platformShipping, 2);

        $order = Order::create([
            'order_number' => $this->generateOrderNumber(),
            'seller_id' => $sellerId,
            'buyer_profile_id' => $buyer->id,
            'recipient_name' => $address['recipient_name'],
            'recipient_contact_no' => $address['contact_number'] ?? null,
            'shipping_street' => $this->streetPart($address['address'], $destination),
            // The structured destination — the chosen saved address, or
            // the buyer's profile address (see destination()) — because
            // `delivery_address.address` above only ever arrives as one
            // flattened human-readable line.
            //
            // Region flags a cross-region parcel "To Transfer" when it
            // differs from the holding company's region; province +
            // municipality (+ barangay) are what
            // App\Services\ParcelIntakeService::matchingArea() matches a
            // parcel to a delivery area on, so leaving them null makes
            // both intake sorting and "Auto assign" fall through to
            // "no area covers this address" for every order.
            'shipping_region_name' => $destination['region_name'],
            'shipping_province_name' => $destination['province_name'],
            'shipping_municipality_name' => $destination['municipality_name'],
            'shipping_barangay' => $destination['barangay'],
            // Exact pins (when set) snapshotted so a later address edit
            // never moves this order on the tracking map.
            'shipping_latitude' => $destination['latitude'],
            'shipping_longitude' => $destination['longitude'],
            'pickup_latitude' => $seller?->address?->latitude,
            'pickup_longitude' => $seller?->address?->longitude,
            // The seller's own structured address, same source shape as
            // the buyer's above — App\Services\TransferTriggerService
            // compares this against shipping_* to decide whether the
            // parcel crosses a municipality/province/region boundary.
            'pickup_region_name' => $seller?->address?->region_name,
            'pickup_province_name' => $seller?->address?->province_name,
            'pickup_municipality_name' => $seller?->address?->municipality_name,
            'pickup_barangay' => $seller?->address?->barangay,
            'status' => 'New',
            'payment_method' => $paymentMethod,
            'payment_status' => 'Unpaid',
            'subtotal' => $subtotal,
            'shipping_fee' => $shippingFee,
            'tax' => 0,
            'discount' => $discount,
            'shipping_discount' => $shippingDiscount,
            'platform_discount' => $platformDiscount,
            'total' => round($subtotal + $shippingFee - $discount - $platformDiscount, 2),
            'shipping_service' => $shippingMethod,
            'placed_at' => now(),
        ]);

        // Build every order_items row up front and insert them in one
        // statement rather than one INSERT round-trip per line — against a
        // remote Postgres that latency adds up fast on a multi-item order.
        $now = now();
        $itemRows = [];
        $variantDecrements = [];
        $productDecrements = [];

        foreach ($lines as $i => $line) {
            $lineDiscount = $applied['discount']['allocations'][$i] ?? 0.0;
            /** @var Product $product */
            $product = $line['product'];
            /** @var ProductVariant|null $variant */
            $variant = $line['variant'];
            $quantity = $line['quantity'];
            $unitPrice = $line['unit_price'];

            $itemRows[] = [
                'id' => (string) Str::uuid(),
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'category' => $product->category,
                'sku' => $variant?->sku ?? $product->sku,
                'variant' => $line['variant_label'],
                'variant_id' => $variant?->id,
                'variant_sku' => $variant?->sku,
                // insert() bypasses the model's array cast, so encode here.
                'variant_options' => $line['variant_options'] !== null
                    ? json_encode($line['variant_options'])
                    : null,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'subtotal' => $unitPrice * $quantity,
                'voucher_code' => $lineDiscount > 0 ? $applied['discount']['voucher']->code : null,
                'voucher_discount' => $lineDiscount,
                'platform_discount' => $platform['allocations'][$i] ?? 0.0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Collapse duplicate lines for the same product/variant so
            // stock moves in one UPDATE per distinct row, not per line.
            if ($variant) {
                $variantDecrements[$variant->id] = ($variantDecrements[$variant->id] ?? 0) + $quantity;
            } else {
                $productDecrements[$product->id] = ($productDecrements[$product->id] ?? 0) + $quantity;
            }
        }

        OrderItem::insert($itemRows);

        foreach (array_filter([$applied['discount'], $applied['shipping']]) as $use) {
            $this->vouchers->redeem($order, $buyer, $use['voucher'], $use['amount']);
        }
        // Platform vouchers: one redemption per seller order, one use per checkout.
        foreach (['discount', 'shipping'] as $slot) {
            if (($platform[$slot] ?? 0) > 0) {
                $this->vouchers->redeem($order, $buyer, $platform["{$slot}_voucher"], $platform[$slot], $checkoutId);
            }
        }

        // Hand the freshly-built rows straight back as the `items` relation
        // so the controller's response doesn't trigger a re-SELECT.
        $itemModels = OrderItem::hydrate($itemRows);

        // Safe under the row locks taken in checkout(): no other request
        // can have read/modified these stock values since.
        foreach ($variantDecrements as $variantId => $quantity) {
            ProductVariant::whereKey($variantId)->decrement('stock', $quantity);
        }

        foreach ($productDecrements as $productId => $quantity) {
            Product::whereKey($productId)->decrement('stock', $quantity);
        }

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'status' => 'New',
            'note' => 'Order placed by buyer.',
            'changed_by' => $buyer->id,
        ]);

        return $order->setRelation('items', $itemModels);
    }

    /**
     * seller id => ['discount' => ?voucher id, 'shipping' => ?voucher id].
     * Legacy clients (mobile) send a wallet id per line as items.*.coupon_id
     * instead; that becomes the line's seller's discount voucher.
     *
     * @return array<string, array{discount: ?string, shipping: ?string}>
     */
    private function voucherSelection(Profile $buyer, array $payload): array
    {
        $selection = [];
        foreach ($payload['vouchers'] ?? [] as $row) {
            $selection[$row['seller_id']] = [
                'discount' => $row['discount_voucher_id'] ?? null,
                'shipping' => $row['shipping_voucher_id'] ?? null,
            ];
        }

        $walletIds = collect($payload['items'])->pluck('coupon_id')->filter()->unique()->values()->all();
        if ($walletIds === [] || isset($payload['vouchers'])) {
            return $selection;
        }

        $mapped = $this->vouchers->vouchersForWalletIds($buyer, $walletIds);
        if (count($mapped) !== count($walletIds)) {
            throw ValidationException::withMessages(['items' => 'A selected coupon is not in your wallet.']);
        }
        foreach ($mapped as $voucherId => $sellerId) {
            if (isset($selection[$sellerId]['discount'])) {
                throw ValidationException::withMessages(['items' => 'Only one voucher can be applied per shop.']);
            }
            $selection[$sellerId] = ['discount' => $voucherId, 'shipping' => null];
        }

        return $selection;
    }

    /**
     * Per seller order: what the platform pass needs to know about the
     * lines and the seller vouchers already applied to them.
     *
     * @param  Collection<string, Collection<int, array>>  $itemsBySeller
     */
    private function platformContext(Collection $itemsBySeller, array $sellerApplied): array
    {
        return PlatformVoucherService::context(
            $itemsBySeller->map(fn (Collection $lines) => $lines->map(fn (array $l) => [
                'product_id' => $l['product']->id,
                'category' => $l['product']->category,
                'subtotal' => $l['unit_price'] * $l['quantity'],
            ])->all())->all(),
            $sellerApplied,
        );
    }

    private function generateOrderNumber(): string
    {
        do {
            $candidate = 'SN-'.random_int(10000, 99999);
        } while (Order::where('order_number', $candidate)->exists());

        return $candidate;
    }
}
