<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';

import ProductDetails from './ProductDetails.vue';
import Cart from './Cart.vue';
import Checkout from './Checkout.vue';
import CategoryListing from './CategoryListing.vue';
import StoreDirectory from './StoreDirectory.vue';
import StorePage from './StorePage.vue';
import Header from './Header.vue';
import Footer from './Footer.vue';
import ProductCard from './ProductCard.vue';
import HeroBanner from './HeroBanner.vue';
import ProductRail from './ProductRail.vue';
import SearchResults from './SearchResults.vue';
import OffersCarousel from './OffersCarousel.vue';
import Orders from './Orders.vue';
import AccountArea from './AccountArea.vue';
import AccountLayout from './AccountLayout.vue';
import Wishlist from './Wishlist.vue';
import Reviews from './Reviews.vue';
import MessagesModal from './MessagesModal.vue';
import ToastHost from './ToastHost.vue';
import ConfirmDialog from './ConfirmDialog.vue';

import { useBuyer } from '../composables/useBuyer';
import { useBuyerProducts } from '../composables/useBuyerProducts';
import { useBuyerSession } from '../composables/useBuyerSession';
import { useBuyerChat } from '../composables/useBuyerChat';
import { useBuyerNav } from '../composables/useBuyerNav';
import { useConfirm } from '../composables/useConfirm';
import {
    SETTINGS_SECTIONS,
    accountFromQuery,
    accountUrl,
    dirtyLabels,
    discardUnsavedChanges,
    hasUnsavedChanges
} from '../composables/useAccountNav';
import { vReveal } from '../composables/useReveal';
import { fetchJson } from '../composables/useStores';
import { applyStats, onReviewChange, withLatestStats } from '../composables/useReviewSync';
import {
    categoryFromQuery,
    categoryUrl,
    filterStateFromQuery,
    rememberFilterState
} from '../composables/useCategoryFilterState';
import {
    categories,
    metaFor
} from '../composables/useCategoryMeta';
import {
    directoryStateFromQuery,
    directoryUrl,
    isDirectoryQuery,
    rememberDirectoryState,
    rememberStoreState,
    storeIdFromQuery,
    storePageUrl,
    storeStateFromQuery
} from '../composables/useStoreBrowseState';
import {
    defaultSearchState,
    rememberSearchState,
    searchStateFromQuery,
    searchTermFromQuery,
    searchUrl
} from '../composables/useSearchState';

/*
|--------------------------------------------------------------------------
| Shared Buyer State
|--------------------------------------------------------------------------
*/

const {
    removeFromCart,
    favoriteCount
} = useBuyer();

const {
    products,
    isLoadingProducts,
    loadError: productsLoadError,
    productsLoadedAt,
    loadProducts,
    refreshProducts
} = useBuyerProducts();

const { loadSession } = useBuyerSession();

const { navRequest } = useBuyerNav();

/*
|--------------------------------------------------------------------------
| Dashboard State
|--------------------------------------------------------------------------
|
| `searchQuery` is what's being typed in the header; `submittedQuery` is
| the search that was actually run. Results only change on submit, so the
| storefront doesn't reshuffle under the buyer on every keystroke.
|
*/

// ?search=<words>&filters (a refreshed or shared results page) runs that
// search with its filters, sort and page (useSearchState.js).
const initialSearch = searchTermFromQuery(window.location.search);

if (initialSearch) {
    rememberSearchState(searchStateFromQuery(window.location.search));
}

const searchQuery = ref(initialSearch);
const submittedQuery = ref(initialSearch);

// Bumped by every header search, so re-running the same words starts a
// fresh results page (filters cleared, page 1).
const searchRun = ref(0);

// A shared / refreshed store link (?store=<id>&...) or store directory link
// (?view=stores&...) opens straight onto that page with its browse state.
const initialStoreId = storeIdFromQuery(window.location.search);
const initialStoresView = !initialStoreId && isDirectoryQuery(window.location.search);

if (initialStoreId) {
    rememberStoreState(initialStoreId, storeStateFromQuery(window.location.search));
}

if (initialStoresView) {
    rememberDirectoryState(directoryStateFromQuery(window.location.search));
}

// A shared / refreshed category link (?category=...&filters) opens straight
// onto that category page with its filters; anything else starts home.
const initialCategory = (() => {
    if (initialStoreId || initialStoresView || initialSearch) {
        return null;
    }

    const fromUrl = categoryFromQuery(window.location.search);

    if (!fromUrl || fromUrl === 'All' || !categories.includes(fromUrl)) {
        return null;
    }

    rememberFilterState(fromUrl, filterStateFromQuery(window.location.search, fromUrl));

    return fromUrl;
})();

const selectedCategory = ref(initialCategory || 'All');

// Set to a specific category name to show CategoryListing (the dedicated
// browse-by-category page) instead of the homepage — see selectCategory()
// below. Stays null while selectedCategory is 'All'.
const browsingCategory = ref(initialCategory);

// The store directory (StoreDirectory.vue) and one store's page
// (StorePage.vue). browsingStore holds whatever is already known about the
// store ({ id } at minimum) so its page can render the name immediately.
const showStores = ref(initialStoresView);
const browsingStore = ref(initialStoreId ? { id: initialStoreId } : null);

const selectedProduct = ref(null);
const showCart = ref(false);
// A refreshed or shared account link (?account=<id>) opens that page.
const initialAccount = accountFromQuery(window.location.search);

// ?messages opens the Messages modal (?messages=<conversation id> on that
// conversation); the parameter is dropped once it has been read.
const initialMessages = (() => {
    const params = new URLSearchParams(window.location.search);

    if (!params.has('messages')) {
        return null;
    }

    const id = params.get('messages') || '';

    return { id: /^[0-9a-f-]{36}$/i.test(id) ? id : null };
})();

const { openChat } = useBuyerChat();

const showOrders = ref(initialAccount === 'orders');
const showAccount = ref(SETTINGS_SECTIONS.includes(initialAccount));
// Which settings section AccountArea shows (it stays mounted across them).
const accountSection = ref(SETTINGS_SECTIONS.includes(initialAccount) ? initialAccount : 'profile');
const showWishlist = ref(initialAccount === 'wishlist');
const showReviews = ref(initialAccount === 'reviews');
const showAddresses = ref(false);
const checkoutItems = ref([]);
const checkoutSource = ref(null);

/*
|--------------------------------------------------------------------------
| Product Catalog
|--------------------------------------------------------------------------
|
| Backed by GET /api/products (App\Http\Controllers\ProductController) —
| see useBuyerProducts.js. Each product carries a real `rating` (average,
| or null when it has no reviews) and `reviewCount` aggregated server-side,
| plus a normalized `image` URL — no fabricated numbers anywhere below.
|
*/

const inStockProducts = computed(() => products.value.filter(product => product.stock > 0));

// The catalog narrowed to the category being browsed — passed to
// CategoryListing.vue as a prop so it never fetches (and can never
// overwrite) the shared products list itself.
const categoryProducts = computed(() => {
    if (!browsingCategory.value) {
        return [];
    }

    return products.value.filter(product => product.category === browsingCategory.value);
});

function isOnSale(product) {
    const oldPrice = Number(product.oldPrice);

    return Number.isFinite(oldPrice) && oldPrice > Number(product.price);
}

function byNewest(a, b) {
    return new Date(b.created_at || 0) - new Date(a.created_at || 0);
}

/*
|--------------------------------------------------------------------------
| Hero Shortcuts
|--------------------------------------------------------------------------
|
| The hero (HeroBanner.vue) only links to sections that exist on this page:
| the category grid, the live deals row, and the full catalog.
|
*/

const prefersReducedMotion = typeof window !== 'undefined'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function scrollToSection(id) {
    document.getElementById(id)?.scrollIntoView({
        behavior: prefersReducedMotion ? 'auto' : 'smooth',
        block: 'start'
    });
}

/*
|--------------------------------------------------------------------------
| Discovery Sections
|--------------------------------------------------------------------------
|
| All explainable selections over real data:
|   - flashDeals: in-stock products with a real, sane compare price
|   - newArrivals: newest listings first
|   - topRated: products that actually have reviews, best average first
|   - spotlight: the category with the most in-stock products right now
|   - bestSellers: most-reviewed in-stock products — a popularity proxy
|     until a sales aggregate exists; Cart.vue uses it for its
|     "you might also like" rail.
|
*/

const flashDeals = computed(() => inStockProducts.value.filter(isOnSale).slice(0, 12));

const newArrivals = computed(() => [...inStockProducts.value].sort(byNewest).slice(0, 12));

const topRated = computed(() =>
    products.value
        .filter(product => typeof product.rating === 'number' && product.reviewCount > 0)
        .sort((a, b) => b.rating - a.rating || b.reviewCount - a.reviewCount)
        .slice(0, 12)
);

const bestSellers = computed(() => {
    return [...inStockProducts.value]
        .sort((a, b) =>
            (b.reviewCount || 0) - (a.reviewCount || 0)
            || (b.rating || 0) - (a.rating || 0)
            || (b.stock || 0) - (a.stock || 0),
        )
        .slice(0, 8);
});

// Shown in the All products subtitle ("N products from M sellers").
const sellerCount = computed(() =>
    new Set(products.value.map(product => product.seller).filter(Boolean)).size
);

/*
|--------------------------------------------------------------------------
| Shop by Category
|--------------------------------------------------------------------------
|
| 'All' is a filter state, not a real category — it never gets a tile.
| Tiles are ordered by real in-stock product count (most-stocked first);
| ties keep categories.js's original order (stable sort).
|
*/

const categoryCounts = computed(() => {
    const counts = {};

    for (const product of inStockProducts.value) {
        counts[product.category] = (counts[product.category] || 0) + 1;
    }

    return counts;
});

const shopCategories = computed(() =>
    categories
        .filter(category => category !== 'All')
        .map((category, index) => ({ category, index }))
        .sort((a, b) =>
            (categoryCounts.value[b.category] || 0) - (categoryCounts.value[a.category] || 0)
            || a.index - b.index,
        )
        .map(entry => entry.category)
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

// Only worth showing once the catalog is big enough that the spotlight
// adds something — on a small catalog it would just repeat New arrivals.
const SPOTLIGHT_MIN_CATALOG = 16;

const spotlightCategory = computed(() => {
    const top = shopCategories.value[0];

    if (inStockProducts.value.length < SPOTLIGHT_MIN_CATALOG) {
        return null;
    }

    return top && (categoryCounts.value[top] || 0) >= 4 ? top : null;
});

const spotlightProducts = computed(() => {
    if (!spotlightCategory.value) {
        return [];
    }

    return inStockProducts.value
        .filter(product => product.category === spotlightCategory.value)
        .sort((a, b) => (b.rating ?? -1) - (a.rating ?? -1) || byNewest(a, b))
        .slice(0, 4);
});

/*
|--------------------------------------------------------------------------
| All Products
|--------------------------------------------------------------------------
|
| The full catalog with a sort control and incremental "Show more" so the
| page stays light. Sorting only reorders — it never changes which
| products are listed.
|
*/

const PAGE_SIZE = 20;

const sortMode = ref('newest');
const visibleCount = ref(PAGE_SIZE);

const sortModes = [
    { id: 'newest', label: 'Newest' },
    { id: 'priceAsc', label: 'Price: low to high' },
    { id: 'priceDesc', label: 'Price: high to low' },
    { id: 'rating', label: 'Top rated' },
    { id: 'reviews', label: 'Most reviewed' }
];

const sortedProducts = computed(() => {
    const list = [...products.value];

    switch (sortMode.value) {
        case 'priceAsc':
            return list.sort((a, b) => a.price - b.price);
        case 'priceDesc':
            return list.sort((a, b) => b.price - a.price);
        case 'rating':
            return list.sort((a, b) =>
                (b.rating ?? -1) - (a.rating ?? -1) || (b.reviewCount || 0) - (a.reviewCount || 0)
            );
        case 'reviews':
            return list.sort((a, b) =>
                (b.reviewCount || 0) - (a.reviewCount || 0) || (b.rating || 0) - (a.rating || 0)
            );
        default:
            return list.sort(byNewest);
    }
});

const visibleProducts = computed(() => sortedProducts.value.slice(0, visibleCount.value));

watch(sortMode, () => {
    visibleCount.value = PAGE_SIZE;
});

const skeletonCards = Array.from({ length: 10 });

/*
|--------------------------------------------------------------------------
| Scroll Position
|--------------------------------------------------------------------------
|
| Views are swapped with v-if, so the storefront / results / category page
| remount when the buyer comes back from a product. Remember where they
| were and put them back there; every other view change starts at the top.
|
*/

const currentView = computed(() => {
    if (showOrders.value) {
        return 'orders';
    }

    if (showAccount.value) {
        return `account-${accountSection.value}`;
    }

    if (showWishlist.value) {
        return 'wishlist';
    }

    if (showReviews.value) {
        return 'reviews';
    }

    if (showAddresses.value) {
        return 'addresses';
    }

    if (checkoutItems.value.length > 0) {
        return 'checkout';
    }

    if (showCart.value) {
        return 'cart';
    }

    if (selectedProduct.value) {
        return `product-${selectedProduct.value.id}`;
    }

    if (browsingStore.value) {
        return `store-${browsingStore.value.id}`;
    }

    if (showStores.value) {
        return 'stores';
    }

    if (browsingCategory.value) {
        return `category-${browsingCategory.value}`;
    }

    if (submittedQuery.value) {
        return `search-${submittedQuery.value}`;
    }

    return 'home';
});

const savedScroll = new Map();
let restoreTarget = null;
let browseViewBeforeProduct = null;

// flush: 'pre' runs before the DOM swaps, so window.scrollY is still the
// outgoing view's position when it's recorded.
watch(currentView, async (next, previous) => {
    if (previous) {
        savedScroll.set(previous, window.scrollY);
    }

    await nextTick();

    if (restoreTarget === next && savedScroll.has(next)) {
        window.scrollTo(0, savedScroll.get(next));
    } else {
        window.scrollTo(0, 0);
    }

    restoreTarget = null;
});

/*
|--------------------------------------------------------------------------
| Browser History
|--------------------------------------------------------------------------
|
| Each view change pushes a history entry holding a plain snapshot of the
| view state, so the browser's Back / Forward buttons move between buyer
| views (product -> results -> storefront ...) instead of leaving the app,
| and the scroll watcher above restores where the buyer was.
|
*/

let applyingHistory = false;

function plain(value) {
    return value ? JSON.parse(JSON.stringify(value)) : null;
}

function viewSnapshot() {
    return {
        view: currentView.value,
        showCart: showCart.value,
        showOrders: showOrders.value,
        showAccount: showAccount.value,
        accountSection: accountSection.value,
        showWishlist: showWishlist.value,
        showReviews: showReviews.value,
        showAddresses: showAddresses.value,
        product: plain(selectedProduct.value),
        browsingCategory: browsingCategory.value,
        showStores: showStores.value,
        browsingStore: plain(browsingStore.value),
        submittedQuery: submittedQuery.value,
        checkoutItems: plain(checkoutItems.value) || [],
        checkoutSource: checkoutSource.value
    };
}

function applySnapshot(state) {
    applyingHistory = true;
    restoreTarget = state.view;

    showCart.value = state.showCart;
    showOrders.value = state.showOrders;
    showAccount.value = state.showAccount;
    accountSection.value = state.accountSection || 'profile';
    showWishlist.value = state.showWishlist;
    showReviews.value = state.showReviews;
    showAddresses.value = state.showAddresses;
    selectedProduct.value = state.product
        ? products.value.find(product => product.id === state.product.id) || state.product
        : null;
    completeSelectedProduct();
    browsingCategory.value = state.browsingCategory;
    showStores.value = Boolean(state.showStores);
    browsingStore.value = state.browsingStore || null;
    selectedCategory.value = state.browsingCategory || 'All';
    submittedQuery.value = state.submittedQuery;
    searchQuery.value = state.submittedQuery;
    checkoutItems.value = state.checkoutItems;
    checkoutSource.value = state.checkoutSource;

    // Nothing changed (e.g. a duplicate entry) — don't swallow the next push.
    nextTick(() => {
        applyingHistory = false;
    });
}

watch(currentView, () => {
    if (applyingHistory) {
        return;
    }

    window.history.pushState(viewSnapshot(), '', urlForCurrentView());
}, { flush: 'post' });

// Category pages, the store directory and store pages carry their browse
// state in the URL (each page keeps the current entry up to date); every
// other view uses the bare page URL.
function urlForCurrentView() {
    if (showAccount.value) {
        return accountUrl(accountSection.value);
    }

    if (['orders', 'wishlist', 'reviews'].includes(currentView.value)) {
        return accountUrl(currentView.value);
    }

    if (browsingStore.value && currentView.value.startsWith('store-')) {
        return storePageUrl(browsingStore.value.id);
    }

    if (currentView.value === 'stores') {
        return directoryUrl();
    }

    if (currentView.value.startsWith('search-')) {
        return searchUrl(submittedQuery.value);
    }

    return browsingCategory.value && currentView.value.startsWith('category-')
        ? categoryUrl(browsingCategory.value)
        : window.location.pathname;
}

function handlePopState(event) {
    // The entry's URL is the truth for a store page's / the directory's state.
    if (event.state?.browsingStore && storeIdFromQuery(window.location.search) === event.state.browsingStore.id) {
        rememberStoreState(event.state.browsingStore.id, storeStateFromQuery(window.location.search));
    } else if (event.state?.showStores && isDirectoryQuery(window.location.search)) {
        rememberDirectoryState(directoryStateFromQuery(window.location.search));
    }

    // The entry's URL is the truth for a search's filters, sort and page.
    if (event.state?.submittedQuery && searchTermFromQuery(window.location.search) === event.state.submittedQuery) {
        rememberSearchState(searchStateFromQuery(window.location.search));
    }

    // The entry's URL is the truth for a category page's filters.
    if (event.state?.browsingCategory && categoryFromQuery(window.location.search) === event.state.browsingCategory) {
        rememberFilterState(
            event.state.browsingCategory,
            filterStateFromQuery(window.location.search, event.state.browsingCategory)
        );
    }

    if (event.state?.view) {
        applySnapshot(event.state);
    }
}

onMounted(() => {
    window.history.scrollRestoration = 'manual';
    window.history.replaceState(viewSnapshot(), '', urlForCurrentView());
    window.addEventListener('popstate', handlePopState);

    if (initialMessages) {
        openChat({ conversationId: initialMessages.id });
    }

    loadCatalogFor(currentView.value);
    // Populates buyerProfile if a Supabase session already exists; browsing
    // itself stays public either way — see useBuyerSession.js.
    loadSession();
});

onUnmounted(() => {
    window.removeEventListener('popstate', handlePopState);
    stopReviewSync();
});

function retryProducts() {
    loadProducts({ per_page: 100 });
}

/*
|--------------------------------------------------------------------------
| In-memory Catalogue (loaded when a view needs it)
|--------------------------------------------------------------------------
|
| The storefront, category pages, deals and product pages work from one
| in-memory list (per_page bumped to the API's max, see
| ProductController@index). Search results, the store directory and store
| pages query the server themselves, so opening one of those (a shared
| search link, a refresh) no longer downloads the catalogue first: that
| request used to compete with — and on a single-worker server, queue in
| front of — the results the buyer asked for. It loads the first time a
| view that needs it opens.
|
*/

let catalogRequested = false;

// Coming back to a catalogue view after this long reloads the list in the
// background (ratings, prices and stock change while the tab stays open);
// the buyer keeps seeing the current list until the new one arrives.
const CATALOG_MAX_AGE_MS = 60 * 1000;

function viewNeedsCatalog(view) {
    return !(view.startsWith('search-') || view.startsWith('store-') || view === 'stores');
}

function loadCatalogFor(view) {
    if (!viewNeedsCatalog(view)) {
        return;
    }

    if (!catalogRequested) {
        catalogRequested = true;
        loadProducts({ per_page: 100 });

        return;
    }

    if (productsLoadedAt.value && Date.now() - productsLoadedAt.value > CATALOG_MAX_AGE_MS) {
        refreshProducts();
    }
}

watch(currentView, view => loadCatalogFor(view));

/*
|--------------------------------------------------------------------------
| Category
|--------------------------------------------------------------------------
|
| Choosing 'All' goes/stays home. Choosing any real category navigates to
| the dedicated CategoryListing page for it — from the category rail, the
| header's category bar or drawer, on ANY page.
|
*/

// Drops every full-screen sub-view back to the dashboard. Anything that
// navigates the buyer "somewhere else" — a category, a global search, the
// logo — has to run this first, otherwise the old view stays mounted on
// top and the click looks like it did nothing.
function closeAllSubViews() {
    selectedProduct.value = null;
    showCart.value = false;
    showOrders.value = false;
    showAccount.value = false;
    showWishlist.value = false;
    showReviews.value = false;
    showAddresses.value = false;
    checkoutItems.value = [];
    checkoutSource.value = null;
}

// The store pages are browse views like a category page: leaving for a
// category, a global search or home closes them.
function closeStoreViews() {
    showStores.value = false;
    browsingStore.value = null;
}

function selectCategory(category) {
    selectedCategory.value = category;
    submittedQuery.value = '';
    searchQuery.value = '';

    closeAllSubViews();
    closeStoreViews();

    browsingCategory.value = category === 'All' ? null : category;
}

/*
|--------------------------------------------------------------------------
| Product Details
|--------------------------------------------------------------------------
*/

function viewProduct(product) {
    // Remember which browse view the product was opened from, so "back"
    // restores that view's scroll position instead of jumping to the top.
    if (!currentView.value.startsWith('product-')) {
        browseViewBeforeProduct = currentView.value;
    }

    closeAllSubViews();
    selectedProduct.value = product;
    completeSelectedProduct();
}

// The product page shows straight away with what the buyer clicked, then
// the live product replaces it, so the rating, review count, price and stock
// are current even when the card came from a list loaded a while ago.
// Search results carry card fields only (options / variants are null — see
// ProductController fields=card): those are marked detailsPending until the
// full product arrives. A complete copy is refreshed quietly.
let productRequestSeq = 0;

async function completeSelectedProduct() {
    const product = selectedProduct.value;

    if (!product) {
        return;
    }

    const isComplete = product.variants != null;
    const seq = ++productRequestSeq;
    const startedAt = Date.now();

    if (!isComplete) {
        selectedProduct.value = { ...product, detailsPending: true, detailsError: '' };
    }

    try {
        const body = await fetchJson(`/api/products/${encodeURIComponent(product.id)}`);

        // Only the newest request for the product still on screen counts.
        if (seq === productRequestSeq && selectedProduct.value?.id === product.id && body?.data) {
            selectedProduct.value = withLatestStats(body.data, startedAt);
        }
    } catch (err) {
        if (isComplete) {
            // The page already shows a complete product; keep it.
            return;
        }

        if (seq === productRequestSeq && selectedProduct.value?.id === product.id) {
            selectedProduct.value = {
                ...selectedProduct.value,
                detailsPending: false,
                detailsError: err?.status === 404
                    ? 'This product is no longer available.'
                    : 'We couldn’t load this product’s options. Go back and open it again.'
            };
        }
    }
}

const stopReviewSync = onReviewChange((stats) => {
    applyStats(selectedProduct.value, stats);
});

function backToProducts() {
    restoreTarget = browseViewBeforeProduct;
    selectedProduct.value = null;
}

/*
|--------------------------------------------------------------------------
| Stores
|--------------------------------------------------------------------------
|
| The directory and a store page sit at the same level as a category page,
| so opening either leaves category / search browsing. Opening a store from
| the directory keeps the directory underneath; its breadcrumb returns
| there with the directory's own state intact.
|
*/

function leaveCatalogBrowsing() {
    closeAllSubViews();
    browsingCategory.value = null;
    selectedCategory.value = 'All';
    submittedQuery.value = '';
    searchQuery.value = '';
}

function openStores() {
    leaveCatalogBrowsing();
    browsingStore.value = null;
    showStores.value = true;
}

function openStore(store) {
    if (!store?.id) {
        return;
    }

    leaveCatalogBrowsing();
    browsingStore.value = plain(store);
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
            image: variant?.image?.url
                || (Array.isArray(item.product.images) ? item.product.images[0] : null)
                || item.product.image
                || null,
            category: item.product.category,
            seller:
                item.product.seller ||
                'BuyTheWay Seller',
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
            image: item.image || null,
            category: item.category,
            seller:
                item.seller ||
                'BuyTheWay Seller',
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
    | checkoutItems is deliberately kept: Checkout stays mounted to show its
    | confirmation view, and its "View my orders" / "Continue shopping"
    | buttons leave through openOrders() / backFromCheckout().
    |
    */

    if (checkoutSource.value === 'cart') {
        checkoutItems.value.forEach(item => {
            if (item.cartId) {
                removeFromCart(item.cartId);
            }
        });
    }
}

// A line the server rejected (e.g. just sold out) can be dropped without
// leaving checkout. Nothing is removed from the cart itself.
function removeCheckoutItem(key) {
    checkoutItems.value = checkoutItems.value.filter(
        item => `${item.productId}-${item.variantId || 'simple'}` !== key
    );
}

function editCartFromCheckout() {
    openCart();
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
| Clear Search
|--------------------------------------------------------------------------
*/

function clearSearch() {
    searchQuery.value = '';
    submittedQuery.value = '';
    selectedCategory.value = 'All';
}

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
    submittedQuery.value = (query || '').trim();
    rememberSearchState(defaultSearchState(submittedQuery.value));
    searchRun.value += 1;

    // A search from the header is global — leave whatever sub-view the
    // buyer was on (Orders, Order Details, Order Tracking, Account, ...)
    // and land back on the product grid with the query applied.
    selectedCategory.value = 'All';
    browsingCategory.value = null;
    closeAllSubViews();
    closeStoreViews();
}

function handleSelectCategory(category) {
    selectCategory(category);
}

function handleBrowseAll() {
    selectedCategory.value = 'All';
    searchQuery.value = '';
    submittedQuery.value = '';
    browsingCategory.value = null;
    closeAllSubViews();
    closeStoreViews();
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

function openAccount(section = 'profile') {
    closeAllSubViews();
    accountSection.value = SETTINGS_SECTIONS.includes(section) ? section : 'profile';
    showAccount.value = true;
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

// The address book is now a section of the account settings.
function openAddresses() {
    openAccount('addresses');
}


// Payment methods are a section of the account settings.
function openPayments() {
    openAccount('payments');
}
/*
|--------------------------------------------------------------------------
| Cross-page Navigation (see useBuyerNav.js)
|--------------------------------------------------------------------------
*/

function goToDeals() {
    handleBrowseAll();

    // Wait for the storefront to mount before scrolling to the deals row.
    setTimeout(() => scrollToSection('flash-deals'), 80);
}

const navHandlers = {
    home: handleBrowseAll,
    cart: openCart,
    account: (payload) => openAccount(payload?.section),
    messages: (payload) => openChat({ conversationId: payload?.conversationId || null }),
    orders: openOrders,
    wishlist: openWishlist,
    reviews: openReviews,
    addresses: openAddresses,
    payments: openPayments,
    deals: goToDeals,
    stores: openStores,
    store: openStore,
    category: (category) => category && selectCategory(category),
    product: (product) => product && viewProduct(product)
};

const { confirm } = useConfirm();

/**
 * Leaving the account area (or switching section) with unsaved edits asks
 * first; choosing to leave discards them.
 */
async function confirmLeaveAccount(targetView) {
    if (!showAccount.value || !hasUnsavedChanges() || targetView === currentView.value) {
        return true;
    }

    const ok = await confirm({
        title: 'Leave without saving?',
        message: `Your changes in ${dirtyLabels().join(' and ')} haven’t been saved.`,
        confirmLabel: 'Leave without saving',
        cancelLabel: 'Keep editing',
        tone: 'danger'
    });

    if (ok) {
        discardUnsavedChanges();
    }

    return ok;
}

watch(navRequest, async (request) => {
    if (!request) {
        return;
    }

    const target = request.view === 'account' ? `account-${request.payload?.section || 'profile'}` : request.view;

    if (!(await confirmLeaveAccount(target))) {
        return;
    }

    navHandlers[request.view]?.(request.payload);
});

// Every page that lives in AccountLayout, and the sidebar item it marks.
const isAccountPage = computed(() => showOrders.value || showAccount.value || showWishlist.value || showReviews.value);

const accountNavActive = computed(() => {
    if (showOrders.value) {
        return 'orders';
    }

    if (showWishlist.value) {
        return 'wishlist';
    }

    if (showReviews.value) {
        return 'reviews';
    }

    return accountSection.value;
});

function guardedFromAccount(action) {
    return async (...args) => {
        if (await confirmLeaveAccount(null)) {
            action(...args);
        }
    };
}
</script>

<template>

    <!-- ================================================================ -->
    <!-- ACCOUNT: settings, orders, wishlist, reviews -->
    <!-- One AccountLayout (header, sidebar, frame) stays mounted while the -->
    <!-- page inside it changes; only the content transitions. -->
    <!-- ================================================================ -->

    <AccountLayout
        v-if="isAccountPage"
        :active="accountNavActive"
        @back="guardedFromAccount(handleBrowseAll)()"
        @search="guardedFromAccount(handleSearch)($event)"
        @select-category="guardedFromAccount(handleSelectCategory)($event)"
        @open-cart="guardedFromAccount(openCart)()"
    >
        <Transition
            name="acc-swap"
            mode="out-in"
        >
            <Orders
                v-if="showOrders"
                key="orders"
                @back="closeOrders"
                @go-home="handleBrowseAll"
                @view-profile="openAccount"
                @view-wishlist="openWishlist"
                @view-reviews="openReviews"
                @view-addresses="openAddresses"
                @view-payments="openPayments"
            />

            <AccountArea
                v-else-if="showAccount"
                key="settings"
                :section="accountSection"
            />

            <Wishlist
                v-else-if="showWishlist"
                key="wishlist"
                @back="closeWishlist"
                @go-home="handleBrowseAll"
                @view-profile="openAccount"
                @view-orders="openOrders"
                @view-reviews="openReviews"
                @view-addresses="openAddresses"
                @view-payments="openPayments"
                @select-product="viewProduct"
            />

            <Reviews
                v-else
                key="reviews"
                @back="closeReviews"
                @go-home="handleBrowseAll"
                @view-profile="openAccount"
                @view-orders="openOrders"
                @view-wishlist="openWishlist"
                @view-addresses="openAddresses"
                @view-payments="openPayments"
            />
        </Transition>
    </AccountLayout>

    <!-- ================================================================ -->
    <!-- CHECKOUT -->
    <!-- ================================================================ -->

    <Checkout
        v-else-if="checkoutItems.length > 0"
        :items="checkoutItems"
        :source="checkoutSource || 'cart'"
        @back="backFromCheckout"
        @place-order="handleOrderPlaced"
        @view-orders="openOrders"
        @view-profile="openAccount"
        @edit-cart="editCartFromCheckout"
        @remove-item="removeCheckoutItem"
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
    <!-- STORE PAGE -->
    <!-- ================================================================ -->

    <StorePage
        v-else-if="browsingStore"
        :key="browsingStore.id"
        :store-id="browsingStore.id"
        :initial-store="browsingStore.name ? browsingStore : null"
        @back="handleBrowseAll"
        @open-stores="openStores"
        @search="handleSearch"
        @select-category="handleSelectCategory"
        @open-cart="openCart"
        @account-click="openAccount"
        @select-product="viewProduct"
        @browse-all="handleBrowseAll"
        @browse-categories="handleBrowseAll"
    />

    <!-- ================================================================ -->
    <!-- STORE DIRECTORY -->
    <!-- ================================================================ -->

    <StoreDirectory
        v-else-if="showStores"
        @back="handleBrowseAll"
        @search="handleSearch"
        @select-category="handleSelectCategory"
        @open-cart="openCart"
        @account-click="openAccount"
        @open-store="openStore"
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
        @retry="retryProducts"
    />

    <!-- ================================================================ -->
    <!-- STOREFRONT + SEARCH RESULTS -->
    <!-- ================================================================ -->

    <div
        v-else
        class="buyer-page"
    >

        <Header
            :search-query="submittedQuery"
            :active-category="submittedQuery ? '' : selectedCategory"
            @update:search-query="searchQuery = $event"
            @search="handleSearch"
            @select-category="selectCategory"
            @cart-click="openCart"
            @account-click="openAccount"
        />

        <main
            id="main-content"
            class="buyer-main"
            tabindex="-1"
        >

            <SearchResults
                v-if="submittedQuery"
                :key="searchRun"
                :query="submittedQuery"
                @view-product="viewProduct"
                @open-store="openStore"
                @clear-search="clearSearch"
            />

            <template v-else>

                <HeroBanner
                    @shop-categories="scrollToSection('shop-by-category')"
                    @browse-all="scrollToSection('buyer-products')"
                />

                <!-- Buyer shortcuts: a browse link to real markdowns, plus the two
                     account destinations buyers return for. Orders and Wishlist
                     handle the signed-out case on their own pages. -->
                <nav
                    class="quicklinks"
                    aria-label="Shortcuts"
                >
                    <button
                        v-if="flashDeals.length > 0"
                        type="button"
                        class="quicklink is-deal"
                        @click="scrollToSection('flash-deals')"
                    >
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.6 2.6A2 2 0 0 0 11.2 2H4a2 2 0 0 0-2 2v7.2a2 2 0 0 0 .6 1.4l8.7 8.7a2.4 2.4 0 0 0 3.4 0l6.6-6.6a2.4 2.4 0 0 0 0-3.4z" /><circle cx="7.5" cy="7.5" r="1.5" /></svg>
                        <span>Shop {{ flashDeals.length }} {{ flashDeals.length === 1 ? 'item' : 'items' }} on sale</span>
                        <svg class="quicklink-arrow" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                    </button>

                    <div class="quicklinks-group">
                        <button
                            type="button"
                            class="quicklink"
                            @click="openOrders"
                        >
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7" /><circle cx="7" cy="17.5" r="1.5" /><circle cx="17" cy="17.5" r="1.5" /></svg>
                            <span>Track an order</span>
                        </button>
                        <button
                            type="button"
                            class="quicklink"
                            :aria-label="favoriteCount > 0 ? `Wishlist, ${favoriteCount} saved` : 'Wishlist'"
                            @click="openWishlist"
                        >
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.2-9.4C1.7 7.9 3.9 4.5 7.4 4.5c2 0 3.5 1.1 4.6 2.7 1.1-1.6 2.6-2.7 4.6-2.7 3.5 0 5.7 3.4 4.6 6.6-1.7 4.8-9.2 9.4-9.2 9.4Z" /></svg>
                            <span>Wishlist</span>
                            <span
                                v-if="favoriteCount > 0"
                                class="quicklink-count"
                                aria-hidden="true"
                            >{{ favoriteCount }}</span>
                        </button>
                    </div>
                </nav>

                <!-- Categories -->
                <section
                    id="shop-by-category"
                    v-reveal
                    aria-labelledby="categories-title"
                >
                    <div class="section-head">
                        <div>
                            <h2
                                id="categories-title"
                                class="section-title"
                            >
                                Shop by category
                            </h2>
                            <p class="section-sub">Sorted by what&rsquo;s in stock right now</p>
                        </div>
                    </div>

                    <ul class="cat-grid">
                        <li
                            v-for="category in shopCategories"
                            :key="category"
                        >
                            <button
                                type="button"
                                class="cat-tile"
                                :class="'accent-' + metaFor(category).accent"
                                @click="selectCategory(category)"
                            >
                                <span class="cat-tile-media">
                                    <img
                                        v-if="categoryImage(category)"
                                        :src="categoryImage(category)"
                                        alt=""
                                        width="240"
                                        height="240"
                                        loading="lazy"
                                        @error="handleCategoryImageError(category)"
                                    >
                                    <span
                                        v-else
                                        class="cat-tile-icon"
                                        aria-hidden="true"
                                        v-html="metaFor(category).icon"
                                    ></span>
                                </span>
                                <span class="cat-tile-label">{{ category }}</span>
                                <span class="cat-tile-count">
                                    <template v-if="isLoadingProducts">&nbsp;</template>
                                    <template v-else-if="categoryCounts[category]">{{ categoryCounts[category] }} in stock</template>
                                    <template v-else>Nothing in stock yet</template>
                                </span>
                            </button>
                        </li>
                    </ul>
                </section>

                <!-- Deals — seller-set markdowns on in-stock items -->
                <OffersCarousel
                    id="flash-deals"
                    :products="flashDeals"
                    @view-product="viewProduct"
                    @shop-deals="sortMode = 'priceAsc'; scrollToSection('buyer-products')"
                />

                <ProductRail
                    id="new-arrivals"
                    title="New arrivals"
                    subtitle="The latest listings from sellers"
                    action-label="See all"
                    :products="newArrivals"
                    @view-product="viewProduct"
                    @action="sortMode = 'newest'; scrollToSection('buyer-products')"
                />

                <!-- Category spotlight — the most-stocked category -->
                <section
                    v-if="spotlightCategory && spotlightProducts.length >= 2"
                    v-reveal
                    class="spotlight"
                    aria-labelledby="spotlight-title"
                >
                    <button
                        type="button"
                        class="spotlight-feature"
                        :class="'accent-' + metaFor(spotlightCategory).accent"
                        @click="selectCategory(spotlightCategory)"
                    >
                        <img
                            v-if="categoryImage(spotlightCategory)"
                            :src="categoryImage(spotlightCategory)"
                            alt=""
                            width="800"
                            height="800"
                            loading="lazy"
                            @error="handleCategoryImageError(spotlightCategory)"
                        >
                        <span class="spotlight-copy">
                            <span class="eyebrow is-light">Most stocked right now</span>
                            <span
                                id="spotlight-title"
                                class="spotlight-title"
                            >{{ spotlightCategory }}</span>
                            <span class="spotlight-meta">{{ categoryCounts[spotlightCategory] }} products in stock</span>
                            <span class="btn btn-light">
                                Shop the category
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                            </span>
                        </span>
                    </button>

                    <ul class="spotlight-grid">
                        <li
                            v-for="product in spotlightProducts"
                            :key="product.id"
                        >
                            <ProductCard
                                :product="product"
                                @view="viewProduct"
                            />
                        </li>
                    </ul>
                </section>

                <ProductRail
                    v-if="topRated.length >= 2"
                    title="Top rated by buyers"
                    subtitle="Highest average rating from buyers who ordered them"
                    :products="topRated"
                    @view-product="viewProduct"
                />

                <!-- All products -->
                <section
                    id="buyer-products"
                    v-reveal
                    aria-labelledby="all-products-title"
                >
                    <div class="section-head">
                        <div>
                            <h2
                                id="all-products-title"
                                class="section-title"
                            >
                                All products
                            </h2>
                            <p class="section-sub">
                                <template v-if="isLoadingProducts">Loading the catalog&hellip;</template>
                                <template v-else>{{ products.length }} {{ products.length === 1 ? 'product' : 'products' }} from {{ sellerCount }} {{ sellerCount === 1 ? 'seller' : 'sellers' }}</template>
                            </p>
                        </div>

                        <label
                            v-if="products.length > 1"
                            class="select-field"
                        >
                            <span class="select-field-label">Sort by</span>
                            <select v-model="sortMode">
                                <option
                                    v-for="mode in sortModes"
                                    :key="mode.id"
                                    :value="mode.id"
                                >
                                    {{ mode.label }}
                                </option>
                            </select>
                        </label>
                    </div>

                    <ul
                        v-if="isLoadingProducts"
                        class="product-grid"
                        aria-hidden="true"
                    >
                        <li
                            v-for="(_, index) in skeletonCards"
                            :key="index"
                            class="pcard-skeleton"
                        >
                            <span class="skeleton is-media"></span>
                            <span class="skeleton is-line"></span>
                            <span class="skeleton is-line is-short"></span>
                        </li>
                    </ul>

                    <div
                        v-else-if="productsLoadError"
                        class="state-block"
                        role="alert"
                    >
                        <h3>We couldn&rsquo;t load products</h3>
                        <p>{{ productsLoadError }}</p>
                        <button
                            type="button"
                            class="btn btn-primary"
                            @click="retryProducts"
                        >
                            Try again
                        </button>
                    </div>

                    <div
                        v-else-if="products.length === 0"
                        class="state-block"
                    >
                        <h3>No products listed yet</h3>
                        <p>Sellers haven&rsquo;t published anything here yet. Check back soon.</p>
                    </div>

                    <template v-else>
                        <ul class="product-grid">
                            <li
                                v-for="product in visibleProducts"
                                :key="product.id"
                            >
                                <ProductCard
                                    :product="product"
                                    @view="viewProduct"
                                />
                            </li>
                        </ul>

                        <div
                            v-if="visibleProducts.length < sortedProducts.length"
                            class="load-more"
                        >
                            <p>Showing {{ visibleProducts.length }} of {{ sortedProducts.length }}</p>
                            <button
                                type="button"
                                class="btn btn-secondary"
                                @click="visibleCount += PAGE_SIZE"
                            >
                                Show more products
                            </button>
                        </div>
                    </template>
                </section>

            </template>

        </main>

        <Footer
            @browse-all="handleBrowseAll"
            @browse-categories="selectCategory('All')"
            @cart-click="openCart"
        />

    </div>

    <!-- Messaging popup — mounted once here, outside the view switch above,
         so it stays alive on every buyer page. -->

    <!-- Buyer-wide notification + confirmation hosts, mounted once outside
         the view switch so they cover every buyer page. -->
    <MessagesModal />

    <ToastHost />
    <ConfirmDialog />

</template>
