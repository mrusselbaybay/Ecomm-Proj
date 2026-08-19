<script setup>
<<<<<<< HEAD
import { ref, computed, onMounted, onUnmounted } from 'vue';

=======
import { computed, ref } from 'vue';
>>>>>>> 036ce43 (feat: add buyer reviews returns and account profile)
import ProductDetails from './ProductDetails.vue';
import Cart from './Cart.vue';
import Checkout from './Checkout.vue';
<<<<<<< HEAD
import Header from './Header.vue';
import Footer from './Footer.vue';
import ProductCard from './ProductCard.vue';
=======
import Orders from './Orders.vue';
<<<<<<< HEAD
>>>>>>> 87c29c1 (feat: add buyer checkout orders tracking and cancellation)

=======
import Account from './Account.vue';
>>>>>>> 036ce43 (feat: add buyer reviews returns and account profile)
import { useBuyer } from '../composables/useBuyer';
import {
    categories,
    metaFor,
    discountPercent,
    formatPrice
} from '../composables/useCategoryMeta';

/*
|--------------------------------------------------------------------------
| Shared Buyer State
|--------------------------------------------------------------------------
*/

const {
<<<<<<< HEAD
    removeFromCart
=======
    cartItemCount,
    removeFromCart,
    addOrder
>>>>>>> 87c29c1 (feat: add buyer checkout orders tracking and cancellation)
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
const showOrders = ref(false);
const showAccount = ref(false);
const checkoutItems = ref([]);
const checkoutSource = ref(null);

/*
|--------------------------------------------------------------------------
| Product Catalog
|--------------------------------------------------------------------------
|
| NOTE: Hardcoded for now — there is no products/catalog API yet.
| price/oldPrice/rating/reviewCount are placeholder values. Swap this
| array for a real API response (e.g. from GET /api/buyer/products) once
| the backend exists; the rest of this component (filtering, cart,
| favorites, flash deals) already reads from this shape and will keep
| working unchanged.
|
*/

const products = [
    {
        id: 1,
        name: 'Wireless Earbuds Pro',
        price: 1499,
        oldPrice: 2299,
        rating: 4.7,
        reviewCount: 128,
        category: 'Electronics',
        seller: 'NEXMART Electronics',
        recommended: true
    },
    {
        id: 2,
        name: 'Smart Fitness Watch',
        price: 2999,
        oldPrice: 4499,
        rating: 4.5,
        reviewCount: 96,
        category: 'Electronics',
        seller: 'NEXMART Electronics',
        bestSeller: true
    },
    {
        id: 3,
        name: 'Knit Performance Sneakers',
        price: 1899,
        rating: 4.6,
        reviewCount: 214,
        category: 'Fashion',
        seller: 'NEXMART Fashion',
        recommended: true
    },
    {
        id: 4,
        name: 'Everyday Canvas Tote Bag',
        price: 599,
        oldPrice: 899,
        rating: 4.3,
        reviewCount: 58,
        category: 'Fashion',
        seller: 'NEXMART Fashion'
    },
    {
        id: 5,
        name: 'Nordic Wooden Desk Lamp',
        price: 799,
        oldPrice: 1199,
        rating: 4.4,
        reviewCount: 71,
        category: 'Home & Living',
        seller: 'NEXMART Home',
        recommended: true
    },
    {
        id: 6,
        name: 'Ceramic Dinnerware Set',
        price: 1299,
        rating: 4.8,
        reviewCount: 143,
        category: 'Home & Living',
        seller: 'NEXMART Home',
        bestSeller: true
    },
    {
        id: 7,
        name: 'Hydrating Face Serum',
        price: 549,
        oldPrice: 799,
        rating: 4.6,
        reviewCount: 302,
        category: 'Beauty',
        seller: 'NEXMART Beauty',
        bestSeller: true
    },
    {
        id: 8,
        name: 'Bamboo Toothbrush Set',
        price: 199,
        rating: 4.2,
        reviewCount: 44,
        category: 'Beauty',
        seller: 'NEXMART Beauty'
    },
    {
        id: 9,
        name: 'Eco-Friendly Textured Yoga Mat',
        price: 899,
        oldPrice: 1299,
        rating: 4.5,
        reviewCount: 87,
        category: 'Sports',
        seller: 'NEXMART Sports',
        recommended: true
    },
    {
        id: 10,
        name: 'Insulated Steel Water Bottle',
        price: 449,
        rating: 4.7,
        reviewCount: 165,
        category: 'Sports',
        seller: 'NEXMART Sports',
        bestSeller: true
    },
    {
        id: 11,
        name: 'Fresh Produce Basket',
        price: 349,
        rating: 4.1,
        reviewCount: 22,
        category: 'Groceries',
        seller: 'NEXMART Groceries'
    },
    {
        id: 12,
        name: 'Organic Rice, 5kg',
        price: 429,
        oldPrice: 549,
        rating: 4.4,
        reviewCount: 39,
        category: 'Groceries',
        seller: 'NEXMART Groceries',
        recommended: true
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
            product.category ===
                selectedCategory.value;

        const matchesSearch =
            !search ||
            product.name
                .toLowerCase()
                .includes(search) ||
            product.category
                .toLowerCase()
                .includes(search);

        return matchesCategory && matchesSearch;
    });
});

/*
|--------------------------------------------------------------------------
| Curated Sections (Flash Deals / Recommended / Best Sellers)
|--------------------------------------------------------------------------
*/

const flashDeals = computed(() => {
    return products
        .filter(product => product.oldPrice)
        .slice(0, 4);
});

const recommendedProducts = computed(() => {
    return products.filter(product => product.recommended);
});

const bestSellers = computed(() => {
    return products.filter(product => product.bestSeller);
});

/*
|--------------------------------------------------------------------------
| Flash Deal Countdown
|--------------------------------------------------------------------------
|
| Cosmetic only — counts down from a fixed duration on page load. There is
| no real deal-expiry timestamp from the backend yet, so this does not
| reflect an actual sale end time.
|
*/

const dealHours = ref(2);
const dealMinutes = ref(45);
const dealSeconds = ref(18);

let countdownTimer = null;

function tickCountdown() {
    if (dealSeconds.value > 0) {
        dealSeconds.value--;
        return;
    }

    if (dealMinutes.value > 0) {
        dealMinutes.value--;
        dealSeconds.value = 59;
        return;
    }

    if (dealHours.value > 0) {
        dealHours.value--;
        dealMinutes.value = 59;
        dealSeconds.value = 59;
        return;
    }

    // Reached zero — loop back so the demo banner keeps showing urgency.
    dealHours.value = 2;
    dealMinutes.value = 45;
    dealSeconds.value = 18;
}

function pad(value) {
    return String(value).padStart(2, '0');
}

onMounted(() => {
    countdownTimer = setInterval(tickCountdown, 1000);
});

onUnmounted(() => {
    if (countdownTimer) {
        clearInterval(countdownTimer);
    }
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
    showOrders.value = false;
    showAccount.value = false;
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
    showOrders.value = false;
    showAccount.value = false;
    showCart.value = true;
    checkoutItems.value = [];
}

function closeCart() {
    showCart.value = false;
}

/*
|--------------------------------------------------------------------------
| Orders
|--------------------------------------------------------------------------
*/

function openOrders() {
    selectedProduct.value = null;
    showCart.value = false;
    showAccount.value = false;
    showOrders.value = true;
    checkoutItems.value = [];
    checkoutSource.value = null;
}

function closeOrders() {
    showOrders.value = false;
}

/*
|--------------------------------------------------------------------------
| Account
|--------------------------------------------------------------------------
*/

function openAccount() {
    selectedProduct.value = null;
    showCart.value = false;
    showOrders.value = false;
    showAccount.value = true;
    checkoutItems.value = [];
    checkoutSource.value = null;
}

function closeAccount() {
    showAccount.value = false;
}

/*
|--------------------------------------------------------------------------
| Buy Now
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Buy Now DOES NOT add the product to the cart.
|
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
    showAccount.value = false;
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

    checkoutItems.value = items.map(item => ({
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
    }));

    checkoutSource.value = 'cart';
    showCart.value = false;
    showAccount.value = false;
    selectedProduct.value = null;
}

/*
|--------------------------------------------------------------------------
| Place Order
|--------------------------------------------------------------------------
*/

function handleOrderPlaced(orderPayload) {
    const savedOrder = addOrder(orderPayload);

    if (!savedOrder) {
        alert('Unable to save the order.');
        return;
    }

    console.log('Order saved:', savedOrder);

    /*
     * Remove only the purchased cart items.
     */
    if (checkoutSource.value === 'cart') {
        checkoutItems.value.forEach(item => {
            if (item.cartId) {
                removeFromCart(item.cartId);
            }
        });
    }

    checkoutItems.value = [];
    checkoutSource.value = null;
    showCart.value = false;
    showAccount.value = false;
    selectedProduct.value = null;

    /* Show the newly created order. */
    showOrders.value = true;
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

/*
|--------------------------------------------------------------------------
| Newsletter (local-only mock — no subscribers API yet)
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Related Products (for the Product Details page)
|--------------------------------------------------------------------------
|
| Other products in the same category, excluding the product itself.
| Real catalog data, not fabricated — falls back to an empty array (which
| ProductDetails already handles by simply not rendering the section).
|
*/

const relatedProducts = computed(() => {
    if (!selectedProduct.value) {
        return [];
    }

    return products
        .filter(product =>
            product.category === selectedProduct.value.category &&
            product.id !== selectedProduct.value.id
        )
        .slice(0, 5);
});

/*
|--------------------------------------------------------------------------
| Header / Footer Events (bubbled up from Header.vue, Footer.vue, and from
| ProductDetails.vue's own embedded Header/Footer when viewing a product)
|--------------------------------------------------------------------------
*/

function handleSearch(query) {
    searchQuery.value = query;
    backToProducts();
}

function handleSelectCategory(category) {
    selectCategory(category);
    backToProducts();
}

function handleBrowseAll() {
    selectedCategory.value = 'All';
    backToProducts();
}
</script>

<template>
    <Checkout
        v-if="checkoutItems.length > 0"
        :items="checkoutItems"
        @back="backFromCheckout"
        @place-order="handleOrderPlaced"
<<<<<<< HEAD
        @search="handleSearch"
        @select-category="handleSelectCategory"
        @browse-all="handleBrowseAll"
        @browse-categories="handleBrowseAll"
=======
>>>>>>> 036ce43 (feat: add buyer reviews returns and account profile)
    />

    <Cart
        v-else-if="showCart"
<<<<<<< HEAD
        :recommended-products="bestSellers"
        @back="closeCart"
        @checkout="checkoutFromCart"
        @search="handleSearch"
        @select-category="handleSelectCategory"
        @browse-all="handleBrowseAll"
        @browse-categories="handleBrowseAll"
        @select-product="viewProduct"
=======
        @back="closeCart"
        @checkout="checkoutFromCart"
>>>>>>> 036ce43 (feat: add buyer reviews returns and account profile)
    />

    <ProductDetails
        v-else-if="selectedProduct"
        :product="selectedProduct"
        :related-products="relatedProducts"
        @back="backToProducts"
        @buy-now="buyNow"
        @select-product="viewProduct"
        @search="handleSearch"
        @select-category="handleSelectCategory"
        @open-cart="openCart"
        @browse-all="handleBrowseAll"
        @browse-categories="handleBrowseAll"
    />

    <Orders
        v-else-if="showOrders"
        @back="closeOrders"
    />

    <Account
        v-else-if="showAccount"
        @back="closeAccount"
    />

    <div
        v-else
        class="buyer-page"
    >
<<<<<<< HEAD

<<<<<<< HEAD
        <Header
            v-model:search-query="searchQuery"
            :active-category="selectedCategory"
            @select-category="selectCategory"
            @cart-click="openCart"
        />
=======
        <!-- Header -->
=======
>>>>>>> 036ce43 (feat: add buyer reviews returns and account profile)
        <header class="buyer-header">
            <div class="buyer-logo">
                NEXMART
            </div>

            <div class="buyer-search">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search products..."
                >
                <button
                    type="button"
                    title="Search"
                >
                    🔍
                </button>
            </div>

            <nav class="buyer-actions">
                <button
                    type="button"
                    title="Messages"
                >
                    💬
                </button>

                <button
                    type="button"
                    title="Orders"
                    @click="openOrders"
                >
                    Orders
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
                    aria-label="Open Buyer account"
                    @click="openAccount"
                >
                    👤
                </button>
            </nav>
        </header>
>>>>>>> 87c29c1 (feat: add buyer checkout orders tracking and cancellation)

        <main class="buyer-main">
<<<<<<< HEAD

            <!-- Hero -->
            <section class="buyer-hero">

                <img
                    class="buyer-hero-image"
                    src="https://images.unsplash.com/photo-1556910103-1c02745aae4d?auto=format&fit=crop&q=80&w=2000"
                    alt=""
                    aria-hidden="true"
                >

                <div class="buyer-hero-overlay"></div>

                <div class="buyer-hero-content">

                    <span class="buyer-hero-badge">
                        NEXMART Marketplace
                    </span>

                    <h1>
                        Shop Local, <br>
                        <span>Delivered To You</span>
                    </h1>

                    <p>
                        Discover electronics, fashion, home essentials and more from verified local sellers, all in one place.
                    </p>

                    <a
                        href="#buyer-products"
                        class="buyer-hero-cta"
                    >
                        Browse Products
                    </a>

                </div>

            </section>

            <!-- Categories -->
            <section>

                <div class="buyer-section-head">
                    <h2>
                        Popular Categories
                    </h2>
                </div>

                <div class="category-grid">

=======
            <section class="welcome-section">
                <h1>Welcome to NEXMART!</h1>
                <p>
                    Discover products from verified local sellers.
                </p>
            </section>

            <section class="buyer-section">
                <div class="section-header">
                    <h2>Categories</h2>
                </div>

                <div class="category-list">
>>>>>>> 036ce43 (feat: add buyer reviews returns and account profile)
                    <button
                        v-for="category in categories"
                        :key="category"
                        type="button"
<<<<<<< HEAD
                        class="category-card"
                        :class="[
                            'accent-' + metaFor(category).accent,
                            { active: selectedCategory === category }
                        ]"
=======
                        class="category-button"
                        :class="{
                            active:
                                selectedCategory === category
                        }"
>>>>>>> 036ce43 (feat: add buyer reviews returns and account profile)
                        @click="selectCategory(category)"
                    >
                        <span
                            class="category-card-icon"
                            v-html="metaFor(category).icon"
                        ></span>

                        <span class="category-card-label">
                            {{ category }}
                        </span>
                    </button>
                </div>
            </section>

<<<<<<< HEAD
            <!-- Flash Deals -->
            <section v-if="flashDeals.length > 0">

                <div class="flash-deals-card">

                    <div class="flash-deals-head">

                        <div class="flash-deals-title">

                            <h2>
                                Flash Deals
                            </h2>

                            <div class="flash-deals-countdown">

                                <span class="flash-deals-countdown-label">
                                    Ending In:
                                </span>

                                <div class="flash-deals-countdown-digits">
                                    <span>{{ pad(dealHours) }}</span>
                                    <span>{{ pad(dealMinutes) }}</span>
                                    <span>{{ pad(dealSeconds) }}</span>
                                </div>

                            </div>

                        </div>

                    </div>

                    <div class="flash-deals-grid">

                        <div
                            v-for="deal in flashDeals"
                            :key="deal.id"
                            class="flash-deal-card"
                            :class="'accent-' + metaFor(deal.category).accent"
                            @click="viewProduct(deal)"
                        >

                            <div class="flash-deal-image">
                                <span class="flash-deal-badge">
                                    -{{ discountPercent(deal) }}%
                                </span>
                                <span
                                    class="flash-deal-icon"
                                    v-html="metaFor(deal.category).icon"
                                ></span>
                            </div>

                            <div class="flash-deal-info">

                                <h3>
                                    {{ deal.name }}
                                </h3>

                                <div class="flash-deal-price-row">
                                    <span class="flash-deal-price">
                                        {{ formatPrice(deal.price) }}
                                    </span>
                                    <span class="flash-deal-old-price">
                                        {{ formatPrice(deal.oldPrice) }}
                                    </span>
                                </div>

                                <div class="flash-deal-stock-row">
                                    <span class="flash-deal-stock-label">
                                        Selling Fast
                                    </span>
                                    <span class="flash-deal-stock-value">
                                        {{ discountPercent(deal) }}%
                                    </span>
                                </div>

                                <div class="flash-deal-stock-bar">
                                    <div
                                        class="flash-deal-stock-fill"
                                        :style="{ width: discountPercent(deal) + '%' }"
                                    ></div>
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </section>

            <!-- Recommended For You -->
            <section v-if="recommendedProducts.length > 0">

                <div class="buyer-section-head">
                    <h2>
                        Recommended For You
                    </h2>
                </div>

                <div class="product-grid">

                    <ProductCard
                        v-for="product in recommendedProducts"
                        :key="product.id"
                        :product="product"
                        @view="viewProduct"
                    />

                </div>

            </section>

            <!-- Info Banner -->
            <section class="info-banner">

                <div class="info-banner-left">

                    <div class="info-banner-icon">
                        🚚
                    </div>

                    <div class="info-banner-text">

                        <h3>
                            Reliable Delivery, Every Order
                        </h3>

                        <p>
                            We work with trusted local sellers and couriers to get your orders to your door safely.
                        </p>

                    </div>

                </div>

            </section>

            <!-- Best Sellers -->
            <section v-if="bestSellers.length > 0">

                <div class="buyer-section-head">
                    <h2>
                        Best Sellers
                    </h2>
                </div>

                <div class="product-grid">

                    <ProductCard
                        v-for="product in bestSellers"
                        :key="product.id"
                        :product="product"
                        @view="viewProduct"
                    />

                </div>

            </section>

            <!-- All Products -->
            <section id="buyer-products">

                <div class="buyer-section-head">
                    <h2>
                        All Products
                    </h2>

                    <span class="buyer-section-tag">
                        {{ filteredProducts.length }} items
                    </span>
                </div>

                <!-- No Products -->
=======
            <section class="buyer-section">
                <div class="section-header">
                    <h2>Products</h2>
                </div>

>>>>>>> 036ce43 (feat: add buyer reviews returns and account profile)
                <div
                    v-if="filteredProducts.length === 0"
                    class="empty-products"
                >
<<<<<<< HEAD

                    <span
                        class="empty-products-icon"
                        aria-hidden="true"
                    >
                        🔍
                    </span>

                    <p>
                        No products found.
                    </p>

=======
                    <p>No products found.</p>
>>>>>>> 036ce43 (feat: add buyer reviews returns and account profile)
                    <button
                        type="button"
                        class="clear-filters-button"
                        @click="clearFilters"
                    >
                        Clear Filters
                    </button>
                </div>

                <div
                    v-else
                    class="product-grid"
                >
<<<<<<< HEAD

                    <ProductCard
                        v-for="product in filteredProducts"
                        :key="product.id"
                        :product="product"
                        @view="viewProduct"
                    />

=======
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
                            <h3>{{ product.name }}</h3>
                            <p class="product-price">
                                ₱{{
                                    Number(product.price).toFixed(2)
                                }}
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
>>>>>>> 036ce43 (feat: add buyer reviews returns and account profile)
                </div>
            </section>
        </main>
<<<<<<< HEAD

        <Footer
            @browse-categories="selectCategory('All')"
            @cart-click="openCart"
        />

=======
>>>>>>> 036ce43 (feat: add buyer reviews returns and account profile)
    </div>
</template>
