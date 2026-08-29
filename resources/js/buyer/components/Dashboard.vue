<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';

import ProductDetails from './ProductDetails.vue';
import Cart from './Cart.vue';
import Checkout from './Checkout.vue';
import CategoryListing from './CategoryListing.vue';
import Header from './Header.vue';
import Footer from './Footer.vue';
import ProductCard from './ProductCard.vue';
import Orders from './Orders.vue';
import Account from './Account.vue';
import Wishlist from './Wishlist.vue';
import Reviews from './Reviews.vue';
import SavedAddresses from './SavedAddresses.vue';
import PaymentMethods from './PaymentMethods.vue';
import Chat from './Chat.vue';

import { useBuyer } from '../composables/useBuyer';
import { useBuyerProducts } from '../composables/useBuyerProducts';
import { useBuyerSession } from '../composables/useBuyerSession';
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
    removeFromCart,
    placeOrder,
    checkoutError
} = useBuyer();

const {
    products,
    isLoadingProducts,
    loadError: productsLoadError,
    loadProducts
} = useBuyerProducts();

const { loadSession } = useBuyerSession();

/*
|--------------------------------------------------------------------------
| Dashboard State
|--------------------------------------------------------------------------
*/

const searchQuery = ref('');
const selectedCategory = ref('All');

// Set to a specific category name to show CategoryListing (the dedicated
// browse-by-category page) instead of the homepage — see selectCategory()
// below. Stays null while selectedCategory is 'All'.
const browsingCategory = ref(null);

const selectedProduct = ref(null);
const showCart = ref(false);
const showOrders = ref(false);
const showAccount = ref(false);
const showWishlist = ref(false);
const showReviews = ref(false);
const showAddresses = ref(false);
const showPayments = ref(false);
const checkoutItems = ref([]);
const checkoutSource = ref(null);

/*
|--------------------------------------------------------------------------
| Product Catalog
|--------------------------------------------------------------------------
|
| Backed by GET /api/products (App\Http\Controllers\ProductController) —
| see useBuyerProducts.js. `products` itself is the ref returned by that
| composable; loaded on mount below.
|
| Each product carries a real `rating` (average, or null when it has no
| reviews) and `reviewCount` aggregated server-side from the reviews
| table, plus a normalized `image` URL — no fabricated numbers, and the
| card still renders "No reviews yet" when rating is null.
|
*/

/*
|--------------------------------------------------------------------------
| Filter Products
|--------------------------------------------------------------------------
*/

const filteredProducts = computed(() => {
    const search = searchQuery.value
        .trim()
        .toLowerCase();

    return products.value.filter(product => {
        const matchesCategory =
            selectedCategory.value === 'All' ||
            product.category === selectedCategory.value;

        const matchesSearch =
            !search ||
            product.name.toLowerCase().includes(search) ||
            (product.category || '').toLowerCase().includes(search);

        return matchesCategory && matchesSearch;
    });
});

/*
|--------------------------------------------------------------------------
| Category Listing Page
|--------------------------------------------------------------------------
|
| Every product already in memory (see useBuyerProducts.js) narrowed to
| whatever category is currently being browsed — passed to
| CategoryListing.vue as a prop rather than having that component fetch
| its own copy, so it can never overwrite the shared `products` list the
| homepage itself depends on.
|
*/

const categoryProducts = computed(() => {
    if (!browsingCategory.value) {
        return [];
    }

    return products.value.filter(
        product => product.category === browsingCategory.value
    );
});

/*
|--------------------------------------------------------------------------
| Curated Sections (Flash Deals / Recommended / Best Sellers)
|--------------------------------------------------------------------------
|
| There is no orders/sales-aggregation endpoint yet to drive a *real*
| units-sold ranking (that would need aggregating order_items across all
| sellers), so these are explainable proxies over real data rather than
| fabricated flags:
|   - flashDeals: real products currently on sale (has a compare_price)
|   - recommendedProducts: most recently listed in-stock products
|   - bestSellers: in-stock products ranked by real review count, then
|     average rating — the strongest popularity signal available until a
|     sales aggregate exists.
| Flagged in the final report as a partial gap, not hidden.
|
*/

const flashDeals = computed(() => {
    return products.value
        .filter(product => product.oldPrice && product.stock > 0)
        .slice(0, 4);
});

const recommendedProducts = computed(() => {
    return products.value
        .filter(product => product.stock > 0)
        .slice(0, 8);
});

const bestSellers = computed(() => {
    return [...products.value]
        .filter(product => product.stock > 0)
        .sort((a, b) =>
            (b.reviewCount || 0) - (a.reviewCount || 0)
            || (b.rating || 0) - (a.rating || 0)
            || (b.stock || 0) - (a.stock || 0),
        )
        .slice(0, 8);
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

    // per_page bumped to the API's max (see ProductController@index) so
    // CategoryListing — which filters this same in-memory list rather
    // than issuing its own request (see categoryProducts above) — has
    // as full a picture of each category as this endpoint can give
    // without pagination support. Real limit worth flagging: a category
    // with more than 100 live products would still only show the first
    // 100 until the catalog endpoint grows real server-side pagination.
    loadProducts({ per_page: 100 });
    // Populates buyerProfile if a Supabase session already exists (e.g.
    // carried over from the auth page); browsing itself stays public
    // either way — see useBuyerSession.js.
    loadSession();
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
|
| Choosing 'All' behaves as it always has (go/stay home, filter the
| homepage's own grid). Choosing any real category now navigates to the
| dedicated CategoryListing page for it — from the homepage's category
| cards, from the header's subnav on ANY page, all the same entry point.
|--------------------------------------------------------------------------
*/

// Drops every full-screen sub-view (cart, orders, account, wishlist,
// reviews, product details, checkout) back to the dashboard. Anything
// that navigates the buyer "somewhere else" — a category, a global
// search, the logo — has to run this first, otherwise the old view stays
// mounted on top and the click looks like it did nothing.
function closeAllSubViews() {
    selectedProduct.value = null;
    showCart.value = false;
    showOrders.value = false;
    showAccount.value = false;
    showWishlist.value = false;
    showReviews.value = false;
    showAddresses.value = false;
    showPayments.value = false;
    checkoutItems.value = [];
    checkoutSource.value = null;
}

function selectCategory(category) {
    selectedCategory.value = category;

    closeAllSubViews();

    browsingCategory.value = category === 'All' ? null : category;
}

/*
|--------------------------------------------------------------------------
| Product Details
|--------------------------------------------------------------------------
*/

function viewProduct(product) {
    closeAllSubViews();
    selectedProduct.value = product;
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
    closeAllSubViews();
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

    return products.value
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

    // A search from the header is global — leave whatever sub-view the
    // buyer was on (Orders, Order Details, Order Tracking, Account, ...)
    // and land back on the product grid with the query applied.
    selectedCategory.value = 'All';
    browsingCategory.value = null;
    closeAllSubViews();
}

function handleSelectCategory(category) {
    selectCategory(category);
}

function handleBrowseAll() {
    selectedCategory.value = 'All';
    browsingCategory.value = null;
    closeAllSubViews();
}

/*
|--------------------------------------------------------------------------
| Account / Orders
|--------------------------------------------------------------------------
|
| Mounts the previously-unwired Orders.vue/Account.vue components (see
| useBuyer.js for the real GET /api/buyer/orders backing Orders.vue).
| Follows the same single-flag view-toggle pattern as showCart above.
|--------------------------------------------------------------------------
*/

function openAccount() {
    closeAllSubViews();
    showAccount.value = true;
}

function closeAccount() {
    showAccount.value = false;
}

function openOrders() {
    closeAllSubViews();
    showOrders.value = true;
}

function closeOrders() {
    showOrders.value = false;
}

function openWishlist() {
    closeAllSubViews();
    showWishlist.value = true;
}

function closeWishlist() {
    showWishlist.value = false;
    showReviews.value = false;
}

function openReviews() {
    closeAllSubViews();
    showReviews.value = true;
}

function closeReviews() {
    showReviews.value = false;
}

function openAddresses() {
    closeAllSubViews();
    showAddresses.value = true;
}

function closeAddresses() {
    showAddresses.value = false;
}

function openPayments() {
    closeAllSubViews();
    showPayments.value = true;
}

function closePayments() {
    showPayments.value = false;
}
</script>

<template>

    <!-- ================================================================ -->
    <!-- ORDERS -->
    <!-- ================================================================ -->

    <Orders
        v-if="showOrders"
        @back="closeOrders"
        @go-home="handleBrowseAll"
        @search="handleSearch"
        @select-category="handleSelectCategory"
        @open-cart="openCart"
        @view-profile="openAccount"
        @view-wishlist="openWishlist"
        @view-reviews="openReviews"
        @view-addresses="openAddresses"
        @view-payments="openPayments"
    />

    <!-- ================================================================ -->
    <!-- ACCOUNT -->
    <!-- ================================================================ -->

    <Account
        v-else-if="showAccount"
        @back="closeAccount"
        @view-orders="openOrders"
        @view-wishlist="openWishlist"
        @view-reviews="openReviews"
        @view-addresses="openAddresses"
        @view-payments="openPayments"
        @search="handleSearch"
        @select-category="handleSelectCategory"
        @open-cart="openCart"
    />

    <!-- ================================================================ -->
    <!-- WISHLIST -->
    <!-- ================================================================ -->

    <Wishlist
        v-else-if="showWishlist"
        @back="closeWishlist"
        @go-home="handleBrowseAll"
        @view-profile="openAccount"
        @view-orders="openOrders"
        @view-reviews="openReviews"
        @view-addresses="openAddresses"
        @view-payments="openPayments"
        @search="handleSearch"
        @select-category="handleSelectCategory"
        @open-cart="openCart"
        @select-product="viewProduct"
    />

    <!-- ================================================================ -->
    <!-- REVIEWS -->
    <!-- ================================================================ -->

    <Reviews
        v-else-if="showReviews"
        @back="closeReviews"
        @go-home="handleBrowseAll"
        @view-profile="openAccount"
        @view-orders="openOrders"
        @view-wishlist="openWishlist"
        @view-addresses="openAddresses"
        @view-payments="openPayments"
        @search="handleSearch"
        @select-category="handleSelectCategory"
        @open-cart="openCart"
    />

    <!-- ================================================================ -->
    <!-- SAVED ADDRESSES -->
    <!-- ================================================================ -->

    <SavedAddresses
        v-else-if="showAddresses"
        @back="closeAddresses"
        @go-home="handleBrowseAll"
        @view-profile="openAccount"
        @view-orders="openOrders"
        @view-wishlist="openWishlist"
        @view-reviews="openReviews"
        @view-payments="openPayments"
        @search="handleSearch"
        @select-category="handleSelectCategory"
        @open-cart="openCart"
    />

    <!-- ================================================================ -->
    <!-- PAYMENT METHODS -->
    <!-- ================================================================ -->

    <PaymentMethods
        v-else-if="showPayments"
        @back="closePayments"
        @go-home="handleBrowseAll"
        @view-profile="openAccount"
        @view-orders="openOrders"
        @view-wishlist="openWishlist"
        @view-reviews="openReviews"
        @view-addresses="openAddresses"
        @search="handleSearch"
        @select-category="handleSelectCategory"
        @open-cart="openCart"
    />

    <!-- ================================================================ -->
    <!-- CHECKOUT -->
    <!-- ================================================================ -->

    <Checkout
        v-else-if="checkoutItems.length > 0"
        :items="checkoutItems"
        @back="backFromCheckout"
        @place-order="handleOrderPlaced"
        @search="handleSearch"
        @select-category="handleSelectCategory"
        @browse-all="handleBrowseAll"
        @browse-categories="handleBrowseAll"
    />

    <!-- ================================================================ -->
    <!-- CART -->
    <!-- ================================================================ -->

    <Cart
        v-else-if="showCart"
        :recommended-products="bestSellers"
        @back="closeCart"
        @checkout="checkoutFromCart"
        @search="handleSearch"
        @select-category="handleSelectCategory"
        @browse-all="handleBrowseAll"
        @browse-categories="handleBrowseAll"
        @select-product="viewProduct"
        @view-profile="openAccount"
    />

    <!-- ================================================================ -->
    <!-- PRODUCT DETAILS -->
    <!-- ================================================================ -->

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
        @view-profile="openAccount"
        @browse-all="handleBrowseAll"
        @browse-categories="handleBrowseAll"
    />

    <!-- ================================================================ -->
    <!-- CATEGORY LISTING -->
    <!-- ================================================================ -->

    <CategoryListing
        v-else-if="browsingCategory"
        :key="browsingCategory"
        :category="browsingCategory"
        :products="categoryProducts"
        :is-loading="isLoadingProducts"
        :load-error="productsLoadError"
        @back="handleBrowseAll"
        @search="handleSearch"
        @select-category="handleSelectCategory"
        @open-cart="openCart"
        @account-click="openAccount"
        @select-product="viewProduct"
        @browse-all="handleBrowseAll"
        @browse-categories="handleBrowseAll"
    />

    <!-- ================================================================ -->
    <!-- BUYER DASHBOARD -->
    <!-- ================================================================ -->

    <div
        v-else
        class="buyer-page"
    >

        <Header
            v-model:search-query="searchQuery"
            :active-category="selectedCategory"
            @select-category="selectCategory"
            @cart-click="openCart"
            @account-click="openAccount"
        />

        <!-- Main -->
        <main class="buyer-main">

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

                    <button
                        v-for="category in categories"
                        :key="category"
                        type="button"
                        class="category-card"
                        :class="[
                            'accent-' + metaFor(category).accent,
                            { active: selectedCategory === category }
                        ]"
                        :aria-pressed="selectedCategory === category"
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

                    <div class="info-banner-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/>
                            <path d="M15 18H9"/>
                            <path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/>
                            <circle cx="17" cy="18" r="2"/>
                            <circle cx="7" cy="18" r="2"/>
                        </svg>
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

                <!-- Loading -->
                <div
                    v-if="isLoadingProducts"
                    class="empty-products"
                >
                    <p>Loading products&hellip;</p>
                </div>

                <!-- Load Error -->
                <div
                    v-else-if="productsLoadError"
                    class="empty-products"
                >
                    <p>{{ productsLoadError }}</p>
                </div>

                <!-- No Products -->
                <div
                    v-else-if="filteredProducts.length === 0"
                    class="empty-products"
                >

                    <span
                        class="empty-products-icon"
                        aria-hidden="true"
                    >
                        🔍
                    </span>

                    <p>
                        No products found.
                    </p>

                    <button
                        type="button"
                        class="clear-filters-button"
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

                    <ProductCard
                        v-for="product in filteredProducts"
                        :key="product.id"
                        :product="product"
                        @view="viewProduct"
                    />

                </div>

            </section>

        </main>

        <Footer
            @browse-categories="selectCategory('All')"
            @cart-click="openCart"
        />

    </div>

    <!-- Messaging popup — mounted once here, outside the view switch above,
         so it stays alive on every buyer page. The header's message icon
         drives it straight through useBuyerChat; nothing to wire per page. -->
    <Chat />

</template>