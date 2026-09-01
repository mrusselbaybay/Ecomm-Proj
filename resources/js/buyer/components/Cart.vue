<script setup>
import { computed, onMounted, ref } from 'vue';
import { useBuyer } from '../composables/useBuyer';
import { formatPrice } from '../composables/useCategoryMeta';
import { useConfirm } from '../composables/useConfirm';
import { useToasts } from '../composables/useToasts';
import CartItemCard from './CartItemCard.vue';
import Footer from './Footer.vue';
import Header from './Header.vue';
import ProductCard from './ProductCard.vue';
import ProductReviewsDrawer from './ProductReviewsDrawer.vue';

defineProps({
    // Real catalog items (Dashboard passes bestSellers). Rendered as
    // "People Also Bought" only when non-empty — never fabricated.
    recommendedProducts: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits([
    'back',
    'checkout',
    'search',
    'select-category',
    'browse-all',
    'browse-categories',
    'select-product',
    'view-profile',
]);

const {
    cart,
    sellers,
    selectedItems,
    selectedValidItems,
    selectedBlockedItems,
    selectedItemCount,
    cartSubtotal,
    allItemsSelected,
    cartHasIssues,
    checkoutBlockReason,
    effectivePrice,

    setCartQuantity,
    removeFromCart,
    removeUnavailableItems,
    clearCart,
    toggleCartItem,
    toggleSellerItems,
    toggleSelectAll,
    deselectBlockedItems,

    isValidatingCart,
    validateCartAgainstCatalog,
} = useBuyer();

const { success, info } = useToasts();
const { confirm } = useConfirm();

/*
|--------------------------------------------------------------------------
| Revalidate the (localStorage) cart against the live catalog on open
|--------------------------------------------------------------------------
*/

const hasValidatedOnce = ref(false);

onMounted(async () => {
    await validateCartAgainstCatalog();
    hasValidatedOnce.value = true;
});

const showSkeleton = computed(
    () => cart.value.length > 0 && isValidatingCart.value && !hasValidatedOnce.value,
);

/*
|--------------------------------------------------------------------------
| Per-seller helpers
|--------------------------------------------------------------------------
|
| NEXMART splits checkout into one order per seller (CheckoutService), so
| items stay grouped by seller, each group with its own select-all row and
| subtotal. Required behaviour, not styling.
|
*/

function sellerItems(seller) {
    return cart.value.filter((item) => item.seller === seller);
}

function isSellerSelected(seller) {
    const items = sellerItems(seller);

    return items.length > 0 && items.every((item) => item.selected);
}

function sellerSubtotal(seller) {
    return sellerItems(seller)
        .filter((item) => item.selected && !isBlocked(item))
        .reduce((total, item) => total + effectivePrice(item) * item.quantity, 0);
}

const BLOCKING = ['unavailable', 'out_of_stock', 'variant_unavailable', 'insufficient_stock'];

function isBlocked(item) {
    return BLOCKING.includes(item.status);
}

/*
|--------------------------------------------------------------------------
| Selection
|--------------------------------------------------------------------------
*/

const totalUnits = computed(() => cart.value.reduce((total, item) => total + item.quantity, 0));

function handleSelectAll(event) {
    toggleSelectAll(event.target.checked);
}

function handleSellerSelection(seller, event) {
    toggleSellerItems(seller, event.target.checked);
}

/*
|--------------------------------------------------------------------------
| Quantity / removal
|--------------------------------------------------------------------------
*/

function handleQuantity(cartId, quantity) {
    setCartQuantity(cartId, quantity);
}

async function handleRemove(item) {
    const confirmed = await confirm({
        title: 'Remove this item?',
        message: `"${item.name}" will be removed from your cart. You can add it again from the product page.`,
        confirmLabel: 'Remove',
        cancelLabel: 'Keep it',
        tone: 'danger',
    });

    if (!confirmed) {
        return;
    }

    removeFromCart(item.cartId);
    success('Item removed from your cart.');
}

async function handleClearCart() {
    const confirmed = await confirm({
        title: 'Clear your cart?',
        message: `All ${cart.value.length} ${cart.value.length === 1 ? 'item' : 'items'} will be removed from your cart. This can't be undone.`,
        confirmLabel: 'Clear cart',
        cancelLabel: 'Cancel',
        tone: 'danger',
    });

    if (!confirmed) {
        return;
    }

    clearCart();
    success('Your cart has been cleared.');
}

async function handleRemoveUnavailable() {
    const count = cart.value.filter((item) => isBlocked(item)).length;

    const confirmed = await confirm({
        title: 'Remove unavailable items?',
        message: `${count} ${count === 1 ? 'item that can' : 'items that can'}'t be purchased right now will be removed from your cart.`,
        confirmLabel: 'Remove them',
        cancelLabel: 'Cancel',
        tone: 'danger',
    });

    if (!confirmed) {
        return;
    }

    const removed = removeUnavailableItems();
    success(`Removed ${removed} unavailable ${removed === 1 ? 'item' : 'items'}.`);
}

function handleDeselectBlocked() {
    deselectBlockedItems();
    info('Unavailable items were deselected.');
}

/*
|--------------------------------------------------------------------------
| Reviews drawer
|--------------------------------------------------------------------------
*/

const reviewsOpen = ref(false);
const reviewsProduct = ref(null);

function openReviews(item) {
    reviewsProduct.value = {
        id: item.productId,
        name: item.name,
        rating: item.rating,
        reviewCount: item.reviewCount,
    };
    reviewsOpen.value = true;
}

/*
|--------------------------------------------------------------------------
| Checkout
|--------------------------------------------------------------------------
*/

const isCheckingOut = ref(false);

const canCheckout = computed(
    () => !checkoutBlockReason.value && !isCheckingOut.value && selectedValidItems.value.length > 0,
);

function checkout() {
    if (!canCheckout.value) {
        return;
    }

    isCheckingOut.value = true;

    // Hand checkout the re-checked price so its summary matches what the
    // server will charge (CheckoutService still recalculates from the DB).
    emit(
        'checkout',
        selectedValidItems.value.map((item) => ({
            cartId: item.cartId,
            productId: item.productId,
            variantId: item.variantId || null,
            name: item.name,
            price: effectivePrice(item),
            category: item.category,
            seller: item.seller,
            variation: item.variation,
            quantity: item.quantity,
        })),
    );
}

/*
|--------------------------------------------------------------------------
| Header / Footer relay (Cart has no dashboard state of its own)
|--------------------------------------------------------------------------
*/

function handleHeaderSearch(query) {
    emit('search', query);
}

function handleHeaderSelectCategory(category) {
    emit('select-category', category);
}

function selectRecommendedProduct(product) {
    emit('select-product', product);
}
</script>

<template>
    <div class="buyer-page min-h-screen bg-slate-50 text-slate-800">
        <Header
            @select-category="handleHeaderSelectCategory"
            @cart-click="() => {}"
            @account-click="emit('view-profile')"
            @logo-click="emit('back')"
            @search="handleHeaderSearch"
        />

        <main class="max-w-7xl mx-auto px-4 lg:px-8 py-10 w-full">
            <!-- Breadcrumb -->
            <nav class="flex items-center gap-2 text-sm text-slate-500 mb-6">
                <button
                    type="button"
                    class="hover:text-teal-700 transition-colors"
                    @click="emit('back')"
                >
                    Home
                </button>
                <svg
                    viewBox="0 0 24 24"
                    width="12"
                    height="12"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="m9 18 6-6-6-6" />
                </svg>
                <span class="text-slate-900 font-medium">Shopping Cart</span>
            </nav>

            <div class="flex items-center justify-between gap-4 mb-6">
                <h1 class="text-2xl sm:text-3xl font-bold text-slate-900">
                    Your Shopping Cart
                    <span class="text-slate-400 font-normal">({{ totalUnits }})</span>
                </h1>

                <button
                    v-if="cart.length > 0"
                    type="button"
                    class="text-sm font-semibold text-slate-500 hover:text-red-600 transition-colors"
                    @click="handleClearCart"
                >
                    Clear cart
                </button>
            </div>

            <!-- ============================================================ -->
            <!-- EMPTY CART -->
            <!-- ============================================================ -->

            <div v-if="cart.length === 0">
                <div class="max-w-lg mx-auto text-center bg-white rounded-3xl border border-slate-100 p-10 sm:p-12 shadow-sm">
                    <div class="w-16 h-16 mx-auto mb-5 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center">
                        <svg
                            viewBox="0 0 24 24"
                            width="30"
                            height="30"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.75"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <circle cx="8" cy="21" r="1" />
                            <circle cx="19" cy="21" r="1" />
                            <path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12" />
                        </svg>
                    </div>
                    <h2 class="text-xl font-bold text-slate-900 mb-2">
                        Your cart is empty
                    </h2>
                    <p class="text-slate-500 mb-6">
                        Browse NEXMART and add items you like — they'll wait for you here, even after a refresh.
                    </p>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center min-h-[44px] bg-teal-600 hover:bg-teal-700 text-white font-bold text-sm px-8 rounded-full transition-colors"
                        @click="emit('back')"
                    >
                        Continue shopping
                    </button>
                </div>

                <section
                    v-if="recommendedProducts.length > 0"
                    class="mt-16"
                >
                    <h2 class="text-xl font-bold text-slate-900 mb-6">
                        Popular on NEXMART
                    </h2>
                    <div class="product-grid">
                        <ProductCard
                            v-for="product in recommendedProducts"
                            :key="product.id"
                            :product="product"
                            @view="selectRecommendedProduct"
                        />
                    </div>
                </section>
            </div>

            <!-- ============================================================ -->
            <!-- CART CONTENT -->
            <!-- ============================================================ -->

            <div
                v-else
                class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start"
            >
                <div class="lg:col-span-2 space-y-5">
                    <!-- Skeleton while first revalidation runs -->
                    <div
                        v-if="showSkeleton"
                        class="bg-white rounded-2xl border border-slate-100 divide-y divide-slate-100"
                        aria-hidden="true"
                    >
                        <div
                            v-for="n in 3"
                            :key="n"
                            class="flex gap-4 p-5"
                        >
                            <div class="w-24 h-24 rounded-xl bg-slate-100 animate-pulse shrink-0" />
                            <div class="flex-1 space-y-3 py-1">
                                <div class="h-4 bg-slate-100 rounded animate-pulse w-2/3" />
                                <div class="h-3 bg-slate-100 rounded animate-pulse w-1/3" />
                                <div class="h-8 bg-slate-100 rounded animate-pulse w-40" />
                            </div>
                        </div>
                    </div>

                    <template v-else>
                        <!-- Cart-wide issues banner -->
                        <div
                            v-if="cartHasIssues"
                            class="bg-amber-50 border border-amber-200 rounded-2xl p-4"
                            role="status"
                        >
                            <div class="flex items-start gap-3">
                                <svg
                                    viewBox="0 0 24 24"
                                    width="18"
                                    height="18"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2.25"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    class="text-amber-600 shrink-0 mt-0.5"
                                    aria-hidden="true"
                                >
                                    <path d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                                </svg>
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-amber-900">
                                        Some items need your attention
                                    </p>
                                    <p class="text-xs text-amber-800 mt-0.5">
                                        We re-checked your cart against the latest stock and prices. Nothing was removed — review the flagged items below.
                                    </p>
                                    <div class="flex flex-wrap gap-2 mt-3">
                                        <button
                                            v-if="selectedBlockedItems.length > 0"
                                            type="button"
                                            class="min-h-[36px] px-3 rounded-lg bg-white border border-amber-300 text-xs font-bold text-amber-800 hover:bg-amber-100 transition-colors"
                                            @click="handleDeselectBlocked"
                                        >
                                            Deselect unavailable items
                                        </button>
                                        <button
                                            type="button"
                                            class="min-h-[36px] px-3 rounded-lg bg-white border border-amber-300 text-xs font-bold text-amber-800 hover:bg-amber-100 transition-colors"
                                            @click="handleRemoveUnavailable"
                                        >
                                            Remove unavailable items
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Select all -->
                        <div class="flex items-center justify-between bg-white rounded-2xl border border-slate-100 px-5 py-3.5">
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input
                                    type="checkbox"
                                    class="w-5 h-5 accent-teal-600 cursor-pointer"
                                    :checked="allItemsSelected"
                                    aria-label="Select all items"
                                    @change="handleSelectAll"
                                >
                                <span class="text-sm font-semibold text-slate-900">Select all</span>
                            </label>
                            <span
                                class="text-xs font-semibold text-slate-500"
                                role="status"
                                aria-atomic="true"
                            >
                                {{ selectedItems.length }} of {{ cart.length }} selected
                            </span>
                        </div>

                        <!-- Per-seller groups -->
                        <div
                            v-for="seller in sellers"
                            :key="seller"
                            class="bg-white rounded-2xl border border-slate-100 overflow-hidden shadow-sm"
                        >
                            <div class="flex items-center justify-between gap-3 px-5 py-3.5 bg-teal-50/70 border-b border-slate-100">
                                <label class="flex items-center gap-3 cursor-pointer min-w-0">
                                    <input
                                        type="checkbox"
                                        class="w-5 h-5 accent-teal-600 cursor-pointer shrink-0"
                                        :checked="isSellerSelected(seller)"
                                        :aria-label="`Select all items from ${seller}`"
                                        @change="handleSellerSelection(seller, $event)"
                                    >
                                    <svg
                                        viewBox="0 0 24 24"
                                        width="15"
                                        height="15"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="text-teal-700 shrink-0"
                                        aria-hidden="true"
                                    >
                                        <path d="M3 9 5 3h14l2 6M4 9v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9M3 9h18" />
                                    </svg>
                                    <strong class="text-sm text-slate-900 truncate">{{ seller }}</strong>
                                </label>
                                <span
                                    v-if="sellerSubtotal(seller) > 0"
                                    class="text-xs font-semibold text-slate-500 tabular-nums shrink-0"
                                >
                                    Subtotal {{ formatPrice(sellerSubtotal(seller)) }}
                                </span>
                            </div>

                            <CartItemCard
                                v-for="item in sellerItems(seller)"
                                :key="item.cartId"
                                :item="item"
                                :validating="isValidatingCart"
                                @update-quantity="handleQuantity"
                                @remove="handleRemove"
                                @toggle-select="toggleCartItem"
                                @view-reviews="openReviews"
                            />
                        </div>

                        <button
                            type="button"
                            class="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 hover:text-teal-800 transition-colors"
                            @click="emit('back')"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                width="16"
                                height="16"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <path d="m15 18-6-6 6-6" />
                            </svg>
                            Continue shopping
                        </button>
                    </template>
                </div>

                <!-- ======================================================== -->
                <!-- ORDER SUMMARY -->
                <!-- ======================================================== -->

                <aside class="lg:sticky lg:top-32">
                    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
                        <h2 class="text-lg font-bold text-slate-900 mb-5">
                            Order Summary
                        </h2>

                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-slate-500">Selected items</dt>
                                <dd class="font-semibold text-slate-900 tabular-nums">{{ selectedItemCount }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-slate-500">Merchandise subtotal</dt>
                                <dd class="font-semibold text-slate-900 tabular-nums">{{ formatPrice(cartSubtotal) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-slate-500">Shipping</dt>
                                <dd class="text-slate-500 text-right text-xs max-w-[55%]">
                                    Calculated at checkout<br>
                                    <span class="text-[11px]">(charged per seller)</span>
                                </dd>
                            </div>
                        </dl>

                        <div class="h-px bg-slate-100 my-4" />

                        <div class="flex justify-between items-baseline mb-1">
                            <span class="text-base font-bold text-slate-900">Estimated total</span>
                            <span class="text-lg font-bold text-teal-700 tabular-nums">{{ formatPrice(cartSubtotal) }}</span>
                        </div>
                        <p class="text-[11px] text-slate-400 mb-5">
                            Excludes shipping, shown at checkout.
                        </p>

                        <button
                            type="button"
                            class="w-full min-h-[48px] bg-teal-600 hover:bg-teal-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-center rounded-xl font-bold text-[15px] transition-colors"
                            :disabled="!canCheckout"
                            :aria-describedby="checkoutBlockReason ? 'checkout-block-reason' : undefined"
                            @click="checkout"
                        >
                            <template v-if="isCheckingOut">Processing…</template>
                            <template v-else-if="selectedValidItems.length > 0">
                                Proceed to Checkout ({{ selectedValidItems.length }})
                            </template>
                            <template v-else>Proceed to Checkout</template>
                        </button>

                        <p
                            v-if="checkoutBlockReason"
                            id="checkout-block-reason"
                            class="flex items-start gap-1.5 text-xs text-amber-700 mt-2"
                            role="status"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                width="14"
                                height="14"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="shrink-0 mt-px"
                                aria-hidden="true"
                            >
                                <circle cx="12" cy="12" r="10" />
                                <path d="M12 8v4m0 4h.01" />
                            </svg>
                            {{ checkoutBlockReason }}
                        </p>

                        <div class="mt-5 space-y-2.5">
                            <p class="flex items-center gap-2 text-xs text-slate-500">
                                <svg
                                    viewBox="0 0 24 24"
                                    width="15"
                                    height="15"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    class="text-teal-600 shrink-0"
                                    aria-hidden="true"
                                >
                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                                </svg>
                                Prices and stock are re-checked before your order is placed.
                            </p>
                        </div>
                    </div>
                </aside>
            </div>

            <!-- People Also Bought -->
            <section
                v-if="cart.length > 0 && recommendedProducts.length > 0"
                class="mt-20"
            >
                <h2 class="text-xl font-bold text-slate-900 mb-6">
                    People Also Bought
                </h2>
                <div class="product-grid">
                    <ProductCard
                        v-for="product in recommendedProducts"
                        :key="product.id"
                        :product="product"
                        @view="selectRecommendedProduct"
                    />
                </div>
            </section>
        </main>

        <Footer
            @browse-all="emit('browse-all')"
            @browse-categories="emit('browse-categories')"
            @cart-click="() => {}"
        />

        <ProductReviewsDrawer
            :show="reviewsOpen"
            :product="reviewsProduct"
            @close="reviewsOpen = false"
        />
    </div>
</template>
