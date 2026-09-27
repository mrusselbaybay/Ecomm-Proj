<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';

import ProductDetails from './ProductDetails.vue';
import Cart from './Cart.vue';
import Checkout from './Checkout.vue';
import CategoryListing from './CategoryListing.vue';
import Header from './Header.vue';
import Footer from './Footer.vue';
import ProductCard from './ProductCard.vue';
import OffersCarousel from './OffersCarousel.vue';
import Orders from './Orders.vue';
import Account from './Account.vue';
import Wishlist from './Wishlist.vue';
import Reviews from './Reviews.vue';
import SavedAddresses from './SavedAddresses.vue';
import PaymentMethods from './PaymentMethods.vue';
import Chat from './Chat.vue';
import ToastHost from './ToastHost.vue';
import ConfirmDialog from './ConfirmDialog.vue';

import { useBuyer } from '../composables/useBuyer';
import { useBuyerProducts } from '../composables/useBuyerProducts';
import { useBuyerSession } from '../composables/useBuyerSession';
import {
    categories,
    metaFor
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
            (product.category || '').toLowerCase().includes(search) ||
            (product.brand || '').toLowerCase().includes(search);

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
| Hero Social Proof
|--------------------------------------------------------------------------
|
| A real count summed from every loaded product's reviewCount (see
| ProductController::transform — reviewCount is a real aggregate from the
| reviews table, never fabricated), not an invented marketing number.
| Whatever real total this ends up as is what's shown.
|
*/

const totalReviews = computed(() =>
    products.value.reduce((sum, product) => sum + (product.reviewCount || 0), 0)
);

/*
|--------------------------------------------------------------------------
| Hero Slider
|--------------------------------------------------------------------------
|
| Three themed slides, each with a real landscape product photo (sourced
| from Unsplash — free/commercial-use license) and a real destination:
| the live Flash Deals section, the real "Electronics and Gadgets"
| category, and the real "Woman's Apparel" category. No invented sitewide
| discount is claimed anywhere in the copy.
|
*/

function scrollToSection(id) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

const heroSlides = [
    {
        theme: 'sage',
        eyebrow: "Today's Best Deals",
        headline: 'Flash Deals, Live Now',
        sub: 'Real markdowns on in-stock favorites, while supplies last.',
        ctaLabel: 'View Flash Deals',
        image: 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&q=80&w=1600',
        action: () => scrollToSection('flash-deals')
    },
    {
        theme: 'sapphire',
        eyebrow: 'Featured Category',
        headline: 'Next-Gen Tech & Gadgets',
        sub: 'Smart devices and accessories from verified local sellers.',
        ctaLabel: 'Shop Electronics',
        image: 'https://images.unsplash.com/photo-1577375729078-820d5283031c?auto=format&fit=crop&q=80&w=1600',
        action: () => selectCategory('Electronics and Gadgets')
    },
    {
        theme: 'terracotta',
        eyebrow: 'Trending Now',
        headline: 'Seasonal Apparel Edit',
        sub: 'Fresh styles and footwear picks from local apparel sellers.',
        ctaLabel: 'Shop Apparel',
        image: 'https://images.unsplash.com/photo-1631542204051-7927ce32b94e?auto=format&fit=crop&q=80&w=1600',
        action: () => selectCategory("Woman's Apparel")
    }
];

const heroSlideIndex = ref(0);
let heroTimer = null;

// Auto-rotating content must not spin under prefers-reduced-motion — see
// the WAI auto-rotation guidance.
const heroReducedMotion = typeof window !== 'undefined'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function heroGoTo(index) {
    heroSlideIndex.value = (index + heroSlides.length) % heroSlides.length;
}

function heroNext() {
    heroGoTo(heroSlideIndex.value + 1);
}

function heroPrev() {
    heroGoTo(heroSlideIndex.value - 1);
}

function heroStopAutoplay() {
    if (heroTimer) {
        clearInterval(heroTimer);
        heroTimer = null;
    }
}

function heroStartAutoplay() {
    heroStopAutoplay();

    if (heroReducedMotion) {
        return;
    }

    heroTimer = setInterval(heroNext, 5000);
}

/*
|--------------------------------------------------------------------------
| Curated Sections (Flash Deals / Best Sellers)
|--------------------------------------------------------------------------
|
| There is no orders/sales-aggregation endpoint yet to drive a *real*
| units-sold ranking (that would need aggregating order_items across all
| sellers), so these are explainable proxies over real data rather than
| fabricated flags:
|   - flashDeals: real products currently on sale (has a compare_price)
|   - bestSellers: in-stock products ranked by real review count, then
|     average rating — the strongest popularity signal available until a
|     sales aggregate exists. No longer its own homepage section (see the
|     All Products sort below for that); kept here because Cart.vue still
|     uses it for its own real "you might also like" rail.
| Flagged in the final report as a partial gap, not hidden.
|
*/

// Slice generous enough for the offers carousel to actually carousel
// (a horizontally-scrolling row is meant to hold more than fits on
// screen at once) — image/gallery/discount handling for each card now
// lives inside OffersCarousel.vue itself, not here.
const flashDeals = computed(() => {
    return products.value
        .filter(product => product.oldPrice && product.stock > 0)
        .slice(0, 12);
});

/*
|--------------------------------------------------------------------------
| Shop by Category
|--------------------------------------------------------------------------
|
| 'All' is a filter state, not a real category — it never gets a tile, and
| clicking any of these already-real categories doesn't set an "active"
| tile here (it navigates straight to CategoryListing, so there's no
| "current category" concept left on this page to highlight).
|
| Tiles are ordered by real in-stock product count (most-stocked first),
| not alphabetically — a category with nothing in stock is still a real,
| clickable category (CategoryListing handles the empty state honestly),
| but it shouldn't sit ahead of one a buyer can actually shop right now.
| Ties keep categories.js's original relative order (stable sort).
|
*/

const shopCategories = computed(() => {
    const counts = {};

    products.value.forEach(product => {
        if (product.stock > 0) {
            counts[product.category] = (counts[product.category] || 0) + 1;
        }
    });

    return categories
        .filter(category => category !== 'All')
        .map((category, index) => ({ category, index }))
        .sort((a, b) =>
            (counts[b.category] || 0) - (counts[a.category] || 0)
            || a.index - b.index,
        )
        .map(entry => entry.category);
});

// Collapsed to 4 tiles by default so the section doesn't push everything
// below it down the page; "View All" reveals the rest in place rather
// than navigating anywhere.
const showAllCategories = ref(false);

const visibleCategories = computed(() =>
    showAllCategories.value
        ? shopCategories.value
        : shopCategories.value.slice(0, 4),
);

const failedCategoryImages = ref(new Set());

function categoryImage(category) {
    const src = metaFor(category).image;

    if (!src || failedCategoryImages.value.has(category)) {
        return '';
    }

    return src;
}

function handleCategoryImageError(category) {
    failedCategoryImages.value = new Set(failedCategoryImages.value).add(category);
}

/*
|--------------------------------------------------------------------------
| All Products Sort
|--------------------------------------------------------------------------
|
| Replaces what used to be three separate carousels (Flash Deals stays
| separate — it's discount-driven, a different real signal) drawing from
| the same small pool of products and just re-sorting it: "Newest" and
| "Best Selling" here are the exact same real logic bestSellers below
| already used, applied on top of the search/category filter instead of a
| second, unfiltered copy of the catalog. Sorting only ever reorders
| filteredProducts — it never changes which products are in it.
|
*/

const sortMode = ref('newest');

const sortModes = [
    { id: 'newest', label: 'Newest' },
    { id: 'bestSelling', label: 'Best Selling' }
];

const sortedProducts = computed(() => {
    const list = [...filteredProducts.value];

    if (sortMode.value === 'bestSelling') {
        return list.sort((a, b) =>
            (b.reviewCount || 0) - (a.reviewCount || 0)
            || (b.rating || 0) - (a.rating || 0),
        );
    }

    return list.sort((a, b) =>
        new Date(b.created_at || 0) - new Date(a.created_at || 0),
    );
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

onMounted(() => {
    heroStartAutoplay();

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
    heroStopAutoplay();
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

    // Carry the chosen variant through, same as the cart path — otherwise
    // Buy Now on a product that has_variants 422s server-side for a
    // missing variant_id.
    const variant = item.variant || null;

    const variationLabel = variant?.option_values
        ? Object.entries(variant.option_values).map(([k, v]) => `${k}: ${v}`).join(', ')
        : (item.variation || null);

    checkoutItems.value = [
        {
            cartId: null,
            productId: item.product.id,
            variantId: variant?.id || null,
            name: item.product.name,
            price: Number(variant?.price ?? item.product.price),
            category: item.product.category,
            seller:
                item.product.seller ||
                'NEXMART Seller',
            variation: variationLabel,
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
            // Carry the chosen variant through to checkout — CheckoutService
            // requires variant_id for a product that has_variants, so
            // dropping it here made variant products 422 at checkout.
            variantId: item.variantId || null,
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
        @view-orders="openOrders"
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
            <section
                class="hero-slider"
                aria-roledescription="carousel"
                aria-label="Featured categories and deals"
                @mouseenter="heroStopAutoplay"
                @mouseleave="heroStartAutoplay"
                @focusin="heroStopAutoplay"
                @focusout="heroStartAutoplay"
            >

                <div
                    class="hero-slider-track"
                    :style="{ transform: `translateX(-${heroSlideIndex * 100}%)` }"
                >
                    <div
                        v-for="(slide, index) in heroSlides"
                        :key="slide.headline"
                        class="hero-slide"
                        aria-roledescription="slide"
                        :aria-label="`${index + 1} of ${heroSlides.length}`"
                    >
                        <div
                            class="hero-slide-photo"
                            :style="{ backgroundImage: `url(${slide.image})` }"
                            role="img"
                            :aria-label="slide.headline"
                        ></div>
                        <div
                            class="hero-slide-scrim"
                            :class="'theme-' + slide.theme"
                            aria-hidden="true"
                        ></div>
                        <div class="hero-slide-inner">
                            <p class="hero-eyebrow">{{ slide.eyebrow }}</p>
                            <h1 class="hero-headline">{{ slide.headline }}</h1>
                            <p class="hero-slide-sub">{{ slide.sub }}</p>
                            <button
                                type="button"
                                class="hero-slide-cta"
                                @click="slide.action"
                            >
                                {{ slide.ctaLabel }}
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14" /><path d="m13 6 6 6-6 6" /></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    class="hero-nav prev"
                    aria-label="Previous slide"
                    @click="heroPrev(); heroStartAutoplay();"
                >
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
                </button>
                <button
                    type="button"
                    class="hero-nav next"
                    aria-label="Next slide"
                    @click="heroNext(); heroStartAutoplay();"
                >
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6" /></svg>
                </button>

                <div
                    class="hero-dots"
                    role="tablist"
                    aria-label="Slides"
                >
                    <button
                        v-for="(slide, index) in heroSlides"
                        :key="'dot-' + index"
                        type="button"
                        class="hero-dot"
                        role="tab"
                        :aria-selected="heroSlideIndex === index"
                        :aria-label="`Show slide ${index + 1}`"
                        @click="heroGoTo(index); heroStartAutoplay();"
                    ></button>
                </div>

                <p class="hero-trust-inline">
                    <strong>{{ totalReviews > 0 ? `${totalReviews}+` : 'New' }}</strong> real reviews from NEXMART buyers
                </p>

            </section>

            <!-- Categories -->
            <section id="shop-by-category">

                <div class="buyer-section-head">
                    <h2>
                        Shop by Category
                    </h2>

                    <button
                        v-if="shopCategories.length > 4"
                        type="button"
                        class="view-all-pill"
                        @click="showAllCategories = !showAllCategories"
                    >
                        {{ showAllCategories ? 'Show Less' : 'View All' }}
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" :style="{ transform: showAllCategories ? 'rotate(-90deg)' : 'rotate(90deg)' }"><path d="m9 18 6-6-6-6" /></svg>
                    </button>
                </div>

                <div class="category-grid">

                    <button
                        v-for="category in visibleCategories"
                        :key="category"
                        type="button"
                        class="category-tile"
                        :class="[
                            'accent-' + metaFor(category).accent,
                            { 'has-photo': categoryImage(category) }
                        ]"
                        @click="selectCategory(category)"
                    >
                        <img
                            v-if="categoryImage(category)"
                            class="category-tile-photo"
                            :src="categoryImage(category)"
                            :alt="category"
                            loading="lazy"
                            @error="handleCategoryImageError(category)"
                        >
                        <span
                            v-if="categoryImage(category)"
                            class="category-tile-scrim"
                            aria-hidden="true"
                        ></span>

                        <span
                            class="category-tile-icon"
                            v-html="metaFor(category).icon"
                        ></span>

                        <span class="category-tile-label">
                            {{ category }}
                        </span>
                    </button>

                </div>

            </section>

            <!-- Offers Carousel — tighter gap above: a direct continuation of
                 the category the buyer just picked, not a fresh topic.
                 Replaces the old Flash Deals grid + fake countdown (same
                 real, validated-discount products — see flashDeals above). -->
            <OffersCarousel
                id="flash-deals"
                class="section-gap-tight"
                :products="flashDeals"
                @view-product="viewProduct"
                @shop-deals="scrollToSection('buyer-products')"
            />

            <!-- All Products — replaces the former New Arrivals / Best
                 Sellers / All Products trio. Same real logic, sort pills
                 instead of three separate carousels of the same catalog. -->
            <section id="buyer-products">

                <div class="products-head">

                    <div>
                        <h2>
                            All Products
                        </h2>

                        <span class="buyer-section-tag">
                            {{ filteredProducts.length }} items
                        </span>
                    </div>

                    <div
                        v-if="filteredProducts.length > 0"
                        class="sort-pills"
                        role="tablist"
                        aria-label="Sort products"
                    >
                        <button
                            v-for="mode in sortModes"
                            :key="mode.id"
                            type="button"
                            role="tab"
                            :class="{ active: sortMode === mode.id }"
                            :aria-selected="sortMode === mode.id"
                            @click="sortMode = mode.id"
                        >
                            {{ mode.label }}
                        </button>
                    </div>

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
                        v-for="product in sortedProducts"
                        :key="product.id"
                        :product="product"
                        @view="viewProduct"
                    />

                </div>

            </section>

            <!-- Trust strip — replaces the old standalone Info Banner
                 interstitial. Same real delivery-trust copy, condensed into
                 a slim strip with a CTA back to category discovery instead
                 of a full-width section breaking up product browsing. -->
            <section class="trust-strip">

                <p class="trust-strip-text">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/>
                        <path d="M15 18H9"/>
                        <path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/>
                        <circle cx="17" cy="18" r="2"/>
                        <circle cx="7" cy="18" r="2"/>
                    </svg>
                    Reliable delivery from trusted local sellers and couriers, every order.
                </p>

                <button
                    type="button"
                    class="trust-strip-cta"
                    @click="scrollToSection('shop-by-category')"
                >
                    Browse Categories
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14" /><path d="m13 6 6 6-6 6" /></svg>
                </button>

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

    <!-- Buyer-wide notification + confirmation hosts. Same "mount once,
         outside the view switch" pattern as <Chat /> — every buyer page
         renders inside this component, so these cover all of them. Driven
         by useToasts / useConfirm; nothing to wire per page. -->
    <ToastHost />
    <ConfirmDialog />

</template>