<script setup>
import { ref, computed } from 'vue';

import ProductDetails from './ProductDetails.vue';
import Cart from './Cart.vue';
import Checkout from './Checkout.vue';

import { useBuyer } from '../composables/useBuyer';

/*
|--------------------------------------------------------------------------
| Shared Buyer State
|--------------------------------------------------------------------------
*/

const {
    cartItemCount,
    removeFromCart
} = useBuyer();

/*
|--------------------------------------------------------------------------
| Dashboard State
|--------------------------------------------------------------------------
*/

const searchQuery = ref('');
const selectedCategory = ref('All');

const selectedProduct = ref(null);
const showCart = ref(false);
const checkoutItems = ref([]);
const checkoutSource = ref(null);

/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/

const categories = [
    'All',
    'Electronics',
    'Fashion',
    'Home & Living',
    'Beauty',
    'Sports',
    'Groceries'
];

/*
|--------------------------------------------------------------------------
| Sample Products
|--------------------------------------------------------------------------
*/

const products = [
    {
        id: 1,
        name: 'Sample Product 1',
        price: 299,
        category: 'Electronics',
        seller: 'NEXMART Electronics'
    },
    {
        id: 2,
        name: 'Sample Product 2',
        price: 499,
        category: 'Fashion',
        seller: 'NEXMART Fashion'
    },
    {
        id: 3,
        name: 'Sample Product 3',
        price: 799,
        category: 'Home & Living',
        seller: 'NEXMART Home'
    }
];

/*
|--------------------------------------------------------------------------
| Filter Products
|--------------------------------------------------------------------------
*/

const filteredProducts = computed(() => {
    const search = searchQuery.value
        .trim()
        .toLowerCase();

    return products.filter(product => {
        const matchesCategory =
            selectedCategory.value === 'All' ||
            product.category === selectedCategory.value;

        const matchesSearch =
            !search ||
            product.name.toLowerCase().includes(search) ||
            product.category.toLowerCase().includes(search);

        return matchesCategory && matchesSearch;
    });
});

/*
|--------------------------------------------------------------------------
| Cart Count
|--------------------------------------------------------------------------
*/

const cartCount = computed(() => {
    return cartItemCount.value;
});

/*
|--------------------------------------------------------------------------
| Category
|--------------------------------------------------------------------------
*/

function selectCategory(category) {
    selectedCategory.value = category;
}

/*
|--------------------------------------------------------------------------
| Product Details
|--------------------------------------------------------------------------
*/

function viewProduct(product) {
    selectedProduct.value = product;
    showCart.value = false;
    checkoutItems.value = [];
}

function backToProducts() {
    selectedProduct.value = null;
}

/*
|--------------------------------------------------------------------------
| Cart
|--------------------------------------------------------------------------
*/

function openCart() {
    selectedProduct.value = null;
    checkoutItems.value = [];
    showCart.value = true;
}

function closeCart() {
    showCart.value = false;
}

/*
|--------------------------------------------------------------------------
| Buy Now
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Buy Now DOES NOT add the product to the cart.
|--------------------------------------------------------------------------
*/

function buyNow(item) {
    if (!item || !item.product) {
        return;
    }

    checkoutItems.value = [
        {
            cartId: null,
            productId: item.product.id,
            name: item.product.name,
            price: Number(item.product.price),
            category: item.product.category,
            seller:
                item.product.seller ||
                'NEXMART Seller',
            variation: item.variation,
            quantity: Number(item.quantity)
        }
    ];

    checkoutSource.value = 'buy-now';

    showCart.value = false;
}
/*
|--------------------------------------------------------------------------
| Checkout From Cart
|--------------------------------------------------------------------------
*/

function checkoutFromCart(items) {
    if (
        !Array.isArray(items) ||
        items.length === 0
    ) {
        return;
    }

    checkoutItems.value = items.map(
        item => ({
            cartId: item.cartId,
            productId: item.productId,
            name: item.name,
            price: Number(item.price),
            category: item.category,
            seller:
                item.seller ||
                'NEXMART Seller',
            variation: item.variation,
            quantity: Number(item.quantity)
        })
    );

    checkoutSource.value = 'cart';

    showCart.value = false;
    selectedProduct.value = null;
}


function handleOrderPlaced() {
    /*
    |--------------------------------------------------------------------------
    | Remove purchased cart items
    |--------------------------------------------------------------------------
    |
    | Only remove products when checkout came from the cart.
    | Buy Now does not affect the existing cart.
    |
    */

    if (checkoutSource.value === 'cart') {
        checkoutItems.value.forEach(item => {
            if (item.cartId) {
                removeFromCart(item.cartId);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Reset Checkout
    |--------------------------------------------------------------------------
    */

    checkoutItems.value = [];
    checkoutSource.value = null;

    showCart.value = false;
    selectedProduct.value = null;
}
/*
|--------------------------------------------------------------------------
| Checkout Back
|--------------------------------------------------------------------------
*/

function backFromCheckout() {
    checkoutItems.value = [];
    checkoutSource.value = null;
}

/*
|--------------------------------------------------------------------------
| Clear Filters
|--------------------------------------------------------------------------
*/

function clearFilters() {
    searchQuery.value = '';
    selectedCategory.value = 'All';
}
</script>

<template>

    <!-- ================================================================ -->
    <!-- CHECKOUT -->
    <!-- ================================================================ -->

    <Checkout
    v-if="checkoutItems.length > 0"
    :items="checkoutItems"
    @back="backFromCheckout"
    @place-order="handleOrderPlaced"
    />

    <!-- ================================================================ -->
    <!-- CART -->
    <!-- ================================================================ -->

    <Cart
    v-else-if="showCart"
    @back="closeCart"
    @checkout="checkoutFromCart"
    />

    <!-- ================================================================ -->
    <!-- PRODUCT DETAILS -->
    <!-- ================================================================ -->

    <ProductDetails
        v-else-if="selectedProduct"
        :product="selectedProduct"
        @back="backToProducts"
        @buy-now="buyNow"
    />

    <!-- ================================================================ -->
    <!-- BUYER DASHBOARD -->
    <!-- ================================================================ -->

    <div
        v-else
        class="buyer-page"
    >

        <!-- Header -->
        <header class="buyer-header">

            <div class="buyer-logo">
                NEXMART
            </div>

            <!-- Search -->
            <div class="buyer-search">

                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search products..."
                />

                <button
                    type="button"
                    title="Search"
                >
                    🔍
                </button>

            </div>

            <!-- Buyer Actions -->
            <nav class="buyer-actions">

                <button
                    type="button"
                    title="Messages"
                >
                    💬
                </button>

                <button
                    type="button"
                    title="Cart"
                    class="cart-button"
                    @click="openCart"
                >
                    🛒

                    <span
                        v-if="cartCount > 0"
                        class="cart-count"
                    >
                        {{ cartCount }}
                    </span>
                </button>

                <button
                    type="button"
                    title="Account"
                >
                    👤
                </button>

            </nav>

        </header>

        <!-- Main -->
        <main class="buyer-main">

            <!-- Welcome -->
            <section class="welcome-section">

                <h1>
                    Welcome to NEXMART!
                </h1>

                <p>
                    Discover products from verified local sellers.
                </p>

            </section>

            <!-- Categories -->
            <section class="buyer-section">

                <div class="section-header">
                    <h2>
                        Categories
                    </h2>
                </div>

                <div class="category-list">

                    <button
                        v-for="category in categories"
                        :key="category"
                        type="button"
                        class="category-button"
                        :class="{
                            active: selectedCategory === category
                        }"
                        @click="selectCategory(category)"
                    >
                        {{ category }}
                    </button>

                </div>

            </section>

            <!-- Products -->
            <section class="buyer-section">

                <div class="section-header">
                    <h2>
                        Products
                    </h2>
                </div>

                <!-- No Products -->
                <div
                    v-if="filteredProducts.length === 0"
                    class="empty-products"
                >

                    <p>
                        No products found.
                    </p>

                    <button
                        type="button"
                        @click="clearFilters"
                    >
                        Clear Filters
                    </button>

                </div>

                <!-- Products -->
                <div
                    v-else
                    class="product-grid"
                >

                    <article
                        v-for="product in filteredProducts"
                        :key="product.id"
                        class="product-card"
                    >

                        <div class="product-image">
                            Product Image
                        </div>

                        <div class="product-info">

                            <span class="product-category">
                                {{ product.category }}
                            </span>

                            <h3>
                                {{ product.name }}
                            </h3>

                            <p class="product-price">
                                ₱{{ Number(product.price).toFixed(2) }}
                            </p>

                            <button
                                type="button"
                                class="view-product-button"
                                @click="viewProduct(product)"
                            >
                                View Product
                            </button>

                        </div>

                    </article>

                </div>

            </section>

        </main>

    </div>

</template>