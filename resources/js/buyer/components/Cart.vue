<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useBuyer } from '../composables/useBuyer';
import { useBuyerSession } from '../composables/useBuyerSession';
import { formatPrice } from '../composables/useCategoryMeta';
import { useConfirm } from '../composables/useConfirm';
import { shippingOptions } from '../composables/useShipping';
import { useToasts } from '../composables/useToasts';
import CartItemCard from './CartItemCard.vue';
import Footer from './Footer.vue';
import Header from './Header.vue';
import ProductCard from './ProductCard.vue';

defineProps({
    // Real catalog items (Dashboard passes bestSellers). Rendered as
    // "You might also like" (most-reviewed products) only when non-empty — never fabricated.
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

const { buyerProfile, isLoadingSession } = useBuyerSession();
const { success, info } = useToasts();
const { confirm } = useConfirm();

/*
|--------------------------------------------------------------------------
| Revalidate the (localStorage) cart against the live catalog on open
|--------------------------------------------------------------------------
*/

// The saved lines render immediately; while the re-check runs, quantity
// controls and checkout are locked (prices or stock may still change) and
// a small status line says so.
onMounted(() => {
    validateCartAgainstCatalog();
});

const isCheckingCatalog = computed(() => cart.value.length > 0 && isValidatingCart.value);

/*
|--------------------------------------------------------------------------
| Per-seller helpers
|--------------------------------------------------------------------------
|
| BuyTheWay splits checkout into one order per seller (CheckoutService), so
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
| Summary estimate
|--------------------------------------------------------------------------
|
| CheckoutService charges one flat shipping fee per seller order, so the
| estimate uses Standard delivery for each seller among the selected items.
| The buyer can still pick Express at checkout, and the server recalculates
| every price and fee, so the total is labelled as an estimate.
|
*/

const checkoutSellerCount = computed(
    () => new Set(selectedValidItems.value.map((item) => item.seller)).size,
);

const estimatedShipping = computed(() => lowestShippingFee * checkoutSellerCount.value);

const estimatedTotal = computed(() => cartSubtotal.value + estimatedShipping.value);

/*
|--------------------------------------------------------------------------
| Mobile checkout bar
|--------------------------------------------------------------------------
|
| Below the lg breakpoint the summary stacks under the items. A slim bar
| keeps the estimated total and the checkout action in reach, and hides
| whenever the summary itself is on screen so nothing is covered twice.
|
*/

const summaryCard = ref(null);
const isCompact = ref(false);
const isSummaryVisible = ref(true);

let compactQuery = null;
let summaryObserver = null;

function onCompactChange(event) {
    isCompact.value = event.matches;
}

function observeSummary() {
    summaryObserver?.disconnect();
    summaryObserver = null;

    if (!summaryCard.value || typeof IntersectionObserver === 'undefined') {
        isSummaryVisible.value = true;

        return;
    }

    summaryObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            isSummaryVisible.value = entry.isIntersecting;
        });
    });

    summaryObserver.observe(summaryCard.value);
}

watch(summaryCard, observeSummary);

onMounted(() => {
    compactQuery = window.matchMedia('(max-width: 1023px)');
    isCompact.value = compactQuery.matches;
    compactQuery.addEventListener('change', onCompactChange);
});

onBeforeUnmount(() => {
    compactQuery?.removeEventListener('change', onCompactChange);
    summaryObserver?.disconnect();
});

const showCheckoutBar = computed(
    () => isCompact.value && !isSummaryVisible.value && cart.value.length > 0,
);

/*
|--------------------------------------------------------------------------
| Checkout
|--------------------------------------------------------------------------
*/

const isCheckingOut = ref(false);

const canCheckout = computed(
    () => !checkoutBlockReason.value
        && !isCheckingOut.value
        && !isValidatingCart.value
        && selectedValidItems.value.length > 0,
);

// Checkout needs a signed-in buyer (the API rejects guests), so ask before
// the buyer fills anything in rather than failing at Place Order. The cart
// lives in localStorage, so nothing is lost by leaving to sign in.
const needsSignIn = ref(false);

const lowestShippingFee = Math.min(...shippingOptions.map((option) => option.fee));

function checkout() {
    if (!canCheckout.value) {
        return;
    }

    if (!isLoadingSession.value && !buyerProfile.value) {
        needsSignIn.value = true;

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
            image: item.image || null,
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
    <div
        class="buyer-page min-h-screen bg-slate-50 text-slate-800"
        :class="{ 'pb-20': showCheckoutBar }"
    >
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
                        Browse the catalog and add what you like. Your cart is saved on this device, even after a refresh.
                    </p>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center min-h-[44px] bg-teal-600 hover:bg-teal-700 text-white font-bold text-sm px-8 rounded-xl transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600"
                        @click="emit('browse-all')"
                    >
                        Browse products
                    </button>
                </div>

                <section
                    v-if="recommendedProducts.length > 0"
                    class="mt-16"
                >
                    <h2 class="text-xl font-bold text-slate-900 mb-6">
                        Worth a look
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
                class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_360px] gap-8 items-start"
            >
                <div class="min-w-0 space-y-5">
                    <!-- Re-check in progress: the saved lines are already shown -->
                    <p
                        v-if="isCheckingCatalog"
                        class="flex items-center gap-2.5 rounded-xl bg-white border border-slate-100 px-4 py-3 text-sm text-slate-600"
                        role="status"
                    >
                        <span
                            class="h-4 w-4 shrink-0 rounded-full border-2 border-slate-300 border-t-teal-600 motion-safe:animate-spin"
                            aria-hidden="true"
                        />
                        Checking latest stock and prices. Quantities and checkout unlock in a moment.
                    </p>

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

                        <TransitionGroup
                            name="cart-row"
                            tag="div"
                            class="divide-y divide-slate-100"
                        >
                            <CartItemCard
                                v-for="item in sellerItems(seller)"
                                :key="item.cartId"
                                :item="item"
                                :validating="isValidatingCart"
                                @update-quantity="handleQuantity"
                                @remove="handleRemove"
                                @toggle-select="toggleCartItem"
                            />
                        </TransitionGroup>
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
                </div>

                <!-- ======================================================== -->
                <!-- ORDER SUMMARY -->
                <!-- ======================================================== -->

                <aside
                    ref="summaryCard"
                    class="lg:sticky lg:top-32"
                    aria-labelledby="cart-summary-title"
                >
                    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
                        <h2
                            id="cart-summary-title"
                            class="text-lg font-bold text-slate-900 mb-5"
                        >
                            Order Summary
                        </h2>

                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-slate-500">Items selected</dt>
                                <dd class="font-semibold text-slate-900 tabular-nums">{{ selectedItemCount }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-slate-500">Subtotal</dt>
                                <dd class="font-semibold text-slate-900 tabular-nums">
                                    <Transition
                                        name="cart-amount"
                                        mode="out-in"
                                    >
                                        <span :key="cartSubtotal">{{ formatPrice(cartSubtotal) }}</span>
                                    </Transition>
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-slate-500">
                                    Shipping
                                    <span
                                        v-if="checkoutSellerCount > 0"
                                        class="block text-xs text-slate-400"
                                    >
                                        Standard, {{ checkoutSellerCount }} {{ checkoutSellerCount === 1 ? 'seller' : 'sellers' }} × {{ formatPrice(lowestShippingFee) }}
                                    </span>
                                </dt>
                                <dd class="font-semibold text-slate-900 tabular-nums">
                                    {{ checkoutSellerCount > 0 ? formatPrice(estimatedShipping) : formatPrice(0) }}
                                </dd>
                            </div>
                        </dl>

                        <div class="h-px bg-slate-100 my-4" />

                        <div class="flex justify-between items-baseline gap-4 mb-1">
                            <span class="text-base font-bold text-slate-900">Estimated total</span>
                            <span class="text-xl font-bold text-slate-900 tabular-nums">
                                <Transition
                                    name="cart-amount"
                                    mode="out-in"
                                >
                                    <span :key="estimatedTotal">{{ formatPrice(estimatedTotal) }}</span>
                                </Transition>
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 mb-5">
                            May change at checkout: shipping depends on the delivery option you choose, and prices and stock are re-checked when you place the order.
                        </p>

                        <button
                            type="button"
                            class="w-full min-h-[48px] bg-teal-600 hover:bg-teal-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-center rounded-xl font-bold text-[15px] transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600"
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

                        <div
                            v-if="needsSignIn && !buyerProfile"
                            class="mt-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-700"
                            role="status"
                        >
                            <p class="font-semibold text-slate-900">Sign in to check out</p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Your cart is saved on this device and will be here when you come back.
                            </p>
                            <a
                                href="/login"
                                class="mt-2 inline-flex min-h-[40px] items-center rounded-lg bg-slate-900 px-4 text-sm font-bold text-white hover:bg-slate-800"
                            >
                                Sign in
                            </a>
                        </div>

                    </div>
                </aside>
            </div>

            <!-- You might also like -->
            <section
                v-if="cart.length > 0 && recommendedProducts.length > 0"
                class="mt-20"
            >
                <h2 class="text-xl font-bold text-slate-900 mb-6">
                    You might also like
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

        <Transition name="cart-bar">
            <div
                v-if="showCheckoutBar"
                class="fixed inset-x-0 bottom-0 z-40 flex items-center justify-between gap-3 border-t border-slate-200 bg-white px-4 pt-2.5 pb-[calc(12px+env(safe-area-inset-bottom))] shadow-[0_-6px_18px_rgba(15,23,42,0.06)]"
            >
                <div class="min-w-0">
                    <p class="text-base font-bold tabular-nums text-slate-900">{{ formatPrice(estimatedTotal) }}</p>
                    <p class="text-xs text-slate-500">Estimated total</p>
                </div>
                <button
                    type="button"
                    class="min-h-[44px] shrink-0 rounded-xl bg-teal-600 px-5 text-sm font-bold text-white transition-colors hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600"
                    :disabled="!canCheckout"
                    :aria-describedby="checkoutBlockReason ? 'checkout-block-reason' : undefined"
                    @click="checkout"
                >
                    Checkout ({{ selectedValidItems.length }})
                </button>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.cart-row-enter-active,
.cart-row-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.cart-row-enter-from,
.cart-row-leave-to {
    opacity: 0;
    transform: translateX(-12px);
}

.cart-amount-enter-active,
.cart-amount-leave-active {
    transition: opacity 0.12s ease;
}

.cart-amount-enter-from,
.cart-amount-leave-to {
    opacity: 0.35;
}

.cart-bar-enter-active,
.cart-bar-leave-active {
    transition: transform 0.2s ease, opacity 0.2s ease;
}

.cart-bar-enter-from,
.cart-bar-leave-to {
    transform: translateY(100%);
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .cart-row-enter-active,
    .cart-row-leave-active,
    .cart-amount-enter-active,
    .cart-amount-leave-active,
    .cart-bar-enter-active,
    .cart-bar-leave-active {
        transition: none;
    }
}
</style>
