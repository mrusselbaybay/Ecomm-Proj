<script setup>
import { useBuyer } from '../composables/useBuyer';
import { metaFor } from '../composables/useCategoryMeta';
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
    'select-product'
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
*/

function sellerItems(seller) {
    return cart.value.filter(
        item => item.seller === seller
    );
}

/*
|--------------------------------------------------------------------------
| Seller Selection
|--------------------------------------------------------------------------
*/

function isSellerSelected(seller) {
    const items = sellerItems(seller);

    return (
        items.length > 0 &&
        items.every(item => item.selected)
    );
}

function handleSellerSelection(seller, event) {
    toggleSellerItems(
        seller,
        event.target.checked
    );
}

/*
|--------------------------------------------------------------------------
| Item Selection
|--------------------------------------------------------------------------
*/

function handleItemSelection(cartId) {
    toggleCartItem(cartId);
}

/*
|--------------------------------------------------------------------------
| Select All
|--------------------------------------------------------------------------
*/

function handleSelectAll(event) {
    toggleSelectAll(
        event.target.checked
    );
}

/*
|--------------------------------------------------------------------------
| Currency
|--------------------------------------------------------------------------
*/

function formatPrice(price) {
    return `₱${Number(price).toFixed(2)}`;
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

/*
|--------------------------------------------------------------------------
| Checkout
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| Cart no longer displays a temporary checkout alert.
|
| It sends ONLY the selected cart items to Dashboard.vue.
| Dashboard then opens the same Checkout.vue used by Buy Now.
|
*/

function checkout() {
    if (selectedItems.value.length === 0) {
        alert('Please select at least one item.');
        return;
    }

    const checkoutItems = selectedItems.value.map(
        item => ({
            cartId: item.cartId,
            productId: item.productId,
            name: item.name,
            price: Number(item.price),
            category: item.category,
            variation: item.variation,
            quantity: Number(item.quantity),
            seller:
                item.seller ||
                'NEXMART Seller'
        })
    );

    emit(
        'checkout',
        checkoutItems
    );
}
</script>

<template>

    <div class="buyer-page">

        <Header
            @select-category="handleHeaderSelectCategory"
            @cart-click="() => {}"
            @logo-click="emit('back')"
            @search="handleHeaderSearch"
        />

        <div class="buyer-cart-page">

            <div class="cart-page-content">

                <!-- ======================================================== -->
                <!-- BREADCRUMB -->
                <!-- ======================================================== -->

                <nav class="product-breadcrumb">
                    <button
                        type="button"
                        class="breadcrumb-link"
                        @click="emit('back')"
                    >
                        Home
                    </button>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current breadcrumb-current--active">
                        Shopping Cart
                    </span>
                </nav>

                <h1 class="cart-title">
                    Your Shopping Cart
                    <span class="cart-title-count">({{ cart.length }})</span>
                </h1>

                <!-- ======================================================== -->
                <!-- EMPTY CART -->
                <!-- ======================================================== -->

                <div
                    v-if="cart.length === 0"
                    class="empty-cart"
                >

                    <div class="empty-cart-icon">
                        🛒
                    </div>

                    <h2>
                        Your cart is empty
                    </h2>

                    <p>
                        Add some products to your cart first.
                    </p>

                    <button
                        type="button"
                        class="continue-shopping-button"
                        @click="emit('back')"
                    >
                        Continue Shopping
                    </button>

                </div>

                <!-- ======================================================== -->
                <!-- CART CONTENT -->
                <!-- ======================================================== -->

                <div
                    v-else
                    class="cart-layout"
                >

                    <!-- Items Column -->
                    <div class="cart-items-column">

                        <!-- Select All -->
                        <div class="cart-select-all-bar">

                            <label>

                                <input
                                    type="checkbox"
                                    :checked="allItemsSelected"
                                    @change="handleSelectAll"
                                >

                                <span>
                                    Select All
                                </span>

                            </label>

                        </div>

                        <!-- Sellers -->
                        <div
                            v-for="seller in sellers"
                            :key="seller"
                            class="cart-seller-group"
                        >

                            <!-- Seller Bar -->
                            <div class="cart-select-all-bar cart-seller-bar">

                                <label>

                                    <input
                                        type="checkbox"
                                        :checked="isSellerSelected(seller)"
                                        @change="handleSellerSelection(seller, $event)"
                                    >

                                    <strong>
                                        {{ seller }}
                                    </strong>

                                </label>

                            </div>

                            <!-- Seller's Items -->
                            <div
                                v-for="item in sellerItems(seller)"
                                :key="item.cartId"
                                class="cart-item-card"
                            >

                                <!-- Select -->
                                <div class="cart-item-select">
                                    <input
                                        type="checkbox"
                                        :checked="item.selected"
                                        @change="handleItemSelection(item.cartId)"
                                    >
                                </div>

                                <!-- Image -->
                                <div
                                    class="cart-item-card-image"
                                    :class="'accent-' + metaFor(item.category).accent"
                                >
                                    <span
                                        class="product-image-icon"
                                        v-html="metaFor(item.category).icon"
                                    ></span>
                                </div>

                                <!-- Body -->
                                <div class="cart-item-card-body">

                                    <div class="cart-item-card-top">

                                        <div class="cart-item-card-info">
                                            <h3>
                                                {{ item.name }}
                                            </h3>
                                            <p class="cart-item-card-meta">
                                                {{ item.category }} | Variation: {{ item.variation }}
                                            </p>
                                        </div>

                                        <button
                                            type="button"
                                            class="cart-item-remove"
                                            title="Remove item"
                                            @click="removeFromCart(item.cartId)"
                                        >
                                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M3 6h18" />
                                                <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                                            </svg>
                                        </button>

                                    </div>

                                    <div class="cart-item-card-bottom">

                                        <div class="cart-item-quantity">

                                            <button
                                                type="button"
                                                @click="decreaseCartQuantity(item.cartId)"
                                            >
                                                −
                                            </button>

                                            <span>
                                                {{ item.quantity }}
                                            </span>

                                            <button
                                                type="button"
                                                @click="increaseCartQuantity(item.cartId)"
                                            >
                                                +
                                            </button>

                                        </div>

                                        <span class="cart-item-card-price">
                                            {{ formatPrice(item.price * item.quantity) }}
                                        </span>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>

                    <!-- Summary Sidebar -->
                    <div class="cart-summary-sidebar">

                        <div class="cart-summary-card">

                            <h2>Order Summary</h2>

                            <div class="cart-summary-rows">

                                <div class="cart-summary-row">
                                    <span>Selected Items</span>
                                    <span class="value">{{ selectedItemCount }}</span>
                                </div>

                                <div class="cart-summary-row">
                                    <span>Shipping estimate</span>
                                    <span class="value--accent">Free</span>
                                </div>

                                <div class="cart-summary-row">
                                    <span>Tax estimate</span>
                                    <span class="value">Calculated at checkout</span>
                                </div>

                                <div class="cart-summary-divider"></div>

                                <div class="cart-summary-total">
                                    <span>Subtotal</span>
                                    <span class="value">{{ formatPrice(cartSubtotal) }}</span>
                                </div>

                            </div>

                            <button
                                type="button"
                                class="cart-checkout-button"
                                :disabled="selectedItems.length === 0"
                                @click="checkout"
                            >
                                Proceed to Checkout
                            </button>

                            <div class="cart-summary-notes">

                                <div class="cart-summary-note">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                                    </svg>
                                    <span>Secure Checkout Guaranteed</span>
                                </div>

                                <div class="cart-summary-note">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 12a9 9 0 1 0 3-6.7" />
                                        <path d="M3 4v5h5" />
                                    </svg>
                                    <span>30-Day Free Returns</span>
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- ======================================================== -->
                <!-- PEOPLE ALSO BOUGHT -->
                <!-- ======================================================== -->

                <section
                    v-if="recommendedProducts.length > 0"
                    style="margin-top: 64px; padding-bottom: 48px;"
                >

                    <div class="buyer-section-head">
                        <h2>People Also Bought</h2>
                    </div>

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

        </div>

        <Footer
            @browse-all="emit('browse-all')"
            @browse-categories="emit('browse-categories')"
            @cart-click="() => {}"
        />

    </div>

</template>