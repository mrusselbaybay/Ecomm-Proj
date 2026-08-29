<script setup>
import { computed } from 'vue';
import { useBuyer } from '../composables/useBuyer';
import { metaFor, formatPrice } from '../composables/useCategoryMeta';
import Header from './Header.vue';
import Footer from './Footer.vue';
import ProductCard from './ProductCard.vue';

const props = defineProps({
    // Real catalog items, e.g. Dashboard's bestSellers/recommendedProducts.
    // Rendered as "People Also Bought" only when non-empty — never fabricated.
    recommendedProducts: {
        type: Array,
        default: () => []
    }
});

const emit = defineEmits([
    'back',
    'checkout',
    'search',
    'select-category',
    'browse-all',
    'browse-categories',
    'select-product',
    'view-profile'
]);

const {
    cart,
    sellers,
    selectedItems,
    selectedItemCount,
    cartSubtotal,
    allItemsSelected,

    removeFromCart,
    increaseCartQuantity,
    decreaseCartQuantity,

    toggleCartItem,
    toggleSellerItems,
    toggleSelectAll
} = useBuyer();

/*
|--------------------------------------------------------------------------
| Seller Items
|--------------------------------------------------------------------------
|
| NEXMART splits checkout into one order per seller (see CheckoutService),
| so — unlike the single-list reference design — items stay grouped by
| seller here, each with its own select-all row. This is required
| functionality, not a stylistic choice.
|
*/

function sellerItems(seller) {
    return cart.value.filter(item => item.seller === seller);
}

function isSellerSelected(seller) {
    const items = sellerItems(seller);

    return items.length > 0 && items.every(item => item.selected);
}

function handleSellerSelection(seller, event) {
    toggleSellerItems(seller, event.target.checked);
}

function handleItemSelection(cartId) {
    toggleCartItem(cartId);
}

function handleSelectAll(event) {
    toggleSelectAll(event.target.checked);
}

/*
|--------------------------------------------------------------------------
| Checkout
|--------------------------------------------------------------------------
*/

function checkout() {
    if (selectedItems.value.length === 0) {
        return;
    }

    emit('checkout', selectedItems.value);
}

/*
|--------------------------------------------------------------------------
| Header relay
|--------------------------------------------------------------------------
|
| Same pattern as ProductDetails.vue's embedded Header — Cart has no
| products/dashboard state of its own, so these bubble up to Dashboard.
|
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

const cartTotal = computed(() => cartSubtotal.value);
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

        <main class="max-w-7xl mx-auto px-4 lg:px-8 py-12 w-full">

            <!-- Breadcrumb -->
            <div class="flex items-center gap-2 text-sm text-slate-500 mb-8">
                <button
                    type="button"
                    class="hover:text-teal-600 transition-colors"
                    @click="emit('back')"
                >
                    Home
                </button>
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m9 18 6-6-6-6" />
                </svg>
                <span class="text-slate-900 font-medium">Shopping Cart</span>
            </div>

            <h1 class="text-3xl font-bold text-slate-900 mb-8">
                Your Shopping Cart
                <span class="text-slate-400 font-normal">({{ cart.length }})</span>
            </h1>

            <!-- Empty Cart -->
            <div
                v-if="cart.length === 0"
                class="max-w-lg mx-auto text-center bg-white rounded-3xl border border-slate-100 p-12"
                style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
            >
                <div class="text-6xl mb-4">🛒</div>
                <h2 class="text-xl font-bold text-slate-900 mb-2">Your cart is empty</h2>
                <p class="text-slate-500 mb-6">Add some products to your cart first.</p>
                <button
                    type="button"
                    class="bg-teal-600 hover:bg-teal-700 text-white font-bold text-sm px-8 py-3 rounded-full transition-colors"
                    @click="emit('back')"
                >
                    Continue Shopping
                </button>
            </div>

            <!-- Cart Content -->
            <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-12 items-start">

                <!-- Items Column -->
                <div class="lg:col-span-2 space-y-6">

                    <!-- Select All -->
                    <label class="flex items-center gap-3 bg-white rounded-2xl border border-slate-100 px-6 py-4 cursor-pointer">
                        <input
                            type="checkbox"
                            :checked="allItemsSelected"
                            class="w-[18px] h-[18px] accent-teal-600 cursor-pointer"
                            @change="handleSelectAll"
                        >
                        <span class="text-sm font-semibold text-slate-900">Select All</span>
                    </label>

                    <!-- Per-Seller Groups -->
                    <div
                        v-for="seller in sellers"
                        :key="seller"
                        class="bg-white rounded-3xl border border-slate-100 overflow-hidden"
                        style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                    >

                        <!-- Seller Header -->
                        <label class="flex items-center gap-3 px-6 py-4 bg-teal-50 border-b border-slate-100 cursor-pointer">
                            <input
                                type="checkbox"
                                :checked="isSellerSelected(seller)"
                                class="w-[18px] h-[18px] accent-teal-600 cursor-pointer"
                                @change="handleSellerSelection(seller, $event)"
                            >
                            <strong class="text-sm text-slate-900">{{ seller }}</strong>
                        </label>

                        <!-- Seller's Items -->
                        <div
                            v-for="item in sellerItems(seller)"
                            :key="item.cartId"
                            class="flex flex-col md:flex-row gap-6 p-6 border-b border-slate-100 last:border-b-0"
                        >
                            <!-- Select -->
                            <div class="flex md:flex-col items-start gap-4">
                                <input
                                    type="checkbox"
                                    :checked="item.selected"
                                    class="w-[18px] h-[18px] accent-teal-600 cursor-pointer mt-1"
                                    @change="handleItemSelection(item.cartId)"
                                >
                            </div>

                            <!-- Image -->
                            <div class="w-full md:w-32 h-32 bg-slate-100 rounded-2xl overflow-hidden shrink-0 flex items-center justify-center">
                                <img
                                    v-if="item.image"
                                    :src="item.image"
                                    :alt="item.name"
                                    class="w-full h-full object-cover"
                                >
                                <span
                                    v-else
                                    class="w-12 h-12 text-slate-400"
                                    v-html="metaFor(item.category).icon"
                                ></span>
                            </div>

                            <!-- Body -->
                            <div class="flex-1 flex flex-col justify-between">

                                <div class="flex justify-between items-start gap-4">
                                    <div>
                                        <h3 class="text-lg font-bold text-slate-900">
                                            {{ item.name }}
                                        </h3>
                                        <p class="text-sm text-slate-500 mt-1">
                                            {{ item.category }}
                                            <template v-if="item.variation"> | {{ item.variation }}</template>
                                        </p>
                                    </div>

                                    <button
                                        type="button"
                                        class="text-slate-400 hover:text-red-500 transition-colors shrink-0"
                                        title="Remove item"
                                        @click="removeFromCart(item.cartId)"
                                    >
                                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 6h18" />
                                            <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                                        </svg>
                                    </button>
                                </div>

                                <div class="flex items-center justify-between mt-6">
                                    <div class="flex items-center border border-slate-200 rounded-xl overflow-hidden">
                                        <button
                                            type="button"
                                            class="px-3 py-1.5 hover:bg-slate-50 transition-colors"
                                            @click="decreaseCartQuantity(item.cartId)"
                                        >
                                            −
                                        </button>
                                        <span class="px-4 py-1.5 font-bold text-sm border-x border-slate-200">
                                            {{ item.quantity }}
                                        </span>
                                        <button
                                            type="button"
                                            class="px-3 py-1.5 hover:bg-slate-50 transition-colors"
                                            @click="increaseCartQuantity(item.cartId)"
                                        >
                                            +
                                        </button>
                                    </div>

                                    <span class="text-lg font-bold text-slate-900">
                                        {{ formatPrice(item.price * item.quantity) }}
                                    </span>
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- Summary Sidebar -->
                <div class="lg:sticky lg:top-32 space-y-6">

                    <div class="bg-slate-900 text-white rounded-3xl p-8" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">

                        <h2 class="text-xl font-bold mb-8">Order Summary</h2>

                        <div class="space-y-4 mb-8">
                            <div class="flex justify-between text-slate-400">
                                <span>Selected Items</span>
                                <span class="text-white font-medium">{{ selectedItemCount }}</span>
                            </div>

                            <div class="flex justify-between text-slate-400">
                                <span>Shipping estimate</span>
                                <span class="text-teal-400 font-bold">Free</span>
                            </div>

                            <div class="flex justify-between text-slate-400">
                                <span>Tax estimate</span>
                                <span class="text-white font-medium">Calculated at checkout</span>
                            </div>

                            <div class="h-px bg-slate-800 my-4"></div>

                            <div class="flex justify-between text-lg font-bold">
                                <span>Subtotal</span>
                                <span class="text-teal-400">{{ formatPrice(cartTotal) }}</span>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="block w-full bg-teal-600 hover:bg-teal-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-center py-4 rounded-2xl font-bold text-lg transition-all"
                            :disabled="selectedItems.length === 0"
                            @click="checkout"
                        >
                            Proceed to Checkout
                        </button>

                        <div class="mt-8 flex flex-col gap-4">
                            <div class="flex items-center gap-3 text-sm text-slate-400">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-teal-400 shrink-0">
                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                                </svg>
                                <span>Secure Checkout Guaranteed</span>
                            </div>
                            <div class="flex items-center gap-3 text-sm text-slate-400">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-teal-400 shrink-0">
                                    <path d="M3 12a9 9 0 1 0 3-6.7" />
                                    <path d="M3 4v5h5" />
                                </svg>
                                <span>30-Day Free Returns</span>
                            </div>
                        </div>

                    </div>

                </div>

            </div>

            <!-- People Also Bought -->
            <section
                v-if="recommendedProducts.length > 0"
                class="mt-24"
            >
                <h2 class="text-2xl font-bold text-slate-900 mb-8">People Also Bought</h2>

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

    </div>
</template>