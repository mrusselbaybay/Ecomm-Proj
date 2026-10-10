<script setup>
/*
|--------------------------------------------------------------------------
| StorePage
|--------------------------------------------------------------------------
|
| One seller's storefront: identity, the store's products, and a short
| About block. Backed by GET /api/stores/{id} (identity + what its products
| vary by) and GET /api/products?seller_id={id} (the products themselves),
| so every search, filter and sort stays scoped to this seller and follows
| the catalog's visibility rule (active products of active sellers only).
|
| - Identity is one compact masthead so products start high on the page.
|   A banner and description render only when the seller has provided
|   them; otherwise a flat band in the store's category tint stands in,
|   never stock imagery. The rating is the average of the store's product
|   reviews and is labelled that way.
| - Products and About are tabs (the tab is part of the URL state). The
|   Products tab leads with a small "Best sellers" (delivered sales) or
|   "On sale" strip when the store has enough to feature, then one toolbar
|   row (search, filters, sort) and one status line (count, chips, Clear
|   all) on the grid's content width.
| - Browse state (search, sort, filters, page) lives in the URL
|   (useStoreBrowseState.js) and is remembered per store.
| - Filters are offered only when this store's products actually differ on
|   them (StoreController::productFacets). Desktop applies changes right
|   away in compact dropdowns; below 1024px a drawer edits a pending copy
|   that "Show results" commits.
| - "Message store" uses the existing buyer messaging API; signed-out
|   buyers get a sign-in link instead.
|
*/
import { ref, reactive, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import Header from './Header.vue';
import Footer from './Footer.vue';
import ProductCard from './ProductCard.vue';
import FilterGroup from './FilterGroup.vue';
import PageNav from './PageNav.vue';
import StarRating from './StarRating.vue';
import StoreLogo from './StoreLogo.vue';
import StoreVoucherStrip from './StoreVoucherStrip.vue';
import { formatPrice, metaFor } from '../composables/useCategoryMeta';
import { useBuyerChat } from '../composables/useBuyerChat';
import { buyerApi } from '../composables/useBuyerApi';
import { useBuyerSession } from '../composables/useBuyerSession';
import { useToasts } from '../composables/useToasts';
import {
    cachedResponse,
    createLatestRequest,
    fetchJson,
    joinedLabel,
    productCountLabel,
    storeEndpoint,
    storeProductsEndpoint
} from '../composables/useStores';
import {
    defaultStoreState,
    queryForStoreState,
    rememberStoreState,
    rememberedStoreState,
    sanitizeStoreState,
    storeIdFromQuery,
    storeProductParams,
    storeStateFromQuery,
    storePageUrl
} from '../composables/useStoreBrowseState';
import { clearBuyerStoreCache } from '../composables/useStores';

const props = defineProps({
    storeId: {
        type: String,
        required: true
    },
    // Whatever the opener already knows (a directory row, or { id, name }
    // from a product page), shown while the full store loads.
    initialStore: {
        type: Object,
        default: null
    }
});

const emit = defineEmits([
    'back',
    'open-stores',
    'search',
    'select-category',
    'open-cart',
    'account-click',
    'select-product',
    'browse-all',
    'browse-categories'
]);

const PER_PAGE = 24;
const SEARCH_DEBOUNCE = 300;
const PRICE_DEBOUNCE = 450;

const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/*
|--------------------------------------------------------------------------
| Store
|--------------------------------------------------------------------------
*/

const cachedStore = cachedResponse(storeEndpoint(props.storeId));

const store = ref(cachedStore?.data || null);
const storeLoading = ref(!cachedStore);
const storeError = ref('');
const storeMissing = ref(false);

// Identity to render right now: the full store, else the opener's copy.
const identity = computed(() => store.value || props.initialStore || null);

const storeRequest = createLatestRequest();

async function loadStore() {
    storeLoading.value = !store.value;
    storeError.value = '';

    try {
        const result = await storeRequest.run(storeEndpoint(props.storeId));

        if (!result.stale) {
            store.value = result.body.data || null;
            storeLoading.value = false;
        }
    } catch (err) {
        storeLoading.value = false;

        if (err?.status === 404) {
            storeMissing.value = true;
        } else {
            storeError.value = err?.message || 'Something went wrong while loading this store.';
        }
    }
}

const facets = computed(() => store.value?.productFacets || null);
const hasRating = computed(() => typeof identity.value?.rating === 'number' && identity.value?.reviewCount > 0);
const joined = computed(() => joinedLabel(identity.value?.joinedAt));

// Tint for the banner fallback, the same accent pair as the logo monogram.
const accentClass = computed(() => `accent-${metaFor(identity.value?.category || '').accent}`);

// Delivery, payment and the platform return rules come from the store API,
// which reads the same definitions checkout enforces (CheckoutOptions /
// PlatformReturnPolicy), so the page can't quote terms checkout won't honour.
const deliveryTerms = computed(() => (store.value?.fulfillment?.shipping || []).map(option => ({
    id: option.id,
    label: `${option.shortName} ${formatPrice(option.fee)}, ${String(option.eta).replace('-', '\u2011')}`
})));
const shippingPerStoreOrder = computed(() => store.value?.fulfillment?.shippingChargedPer === 'seller_order');
const paymentTerms = computed(() => (store.value?.fulfillment?.payment || []).map(method => method.name).join(', '));
const returnRules = computed(() => store.value?.returnRules || []);

const verifiedLabel = computed(() => {
    const date = joinedLabel(identity.value?.verifiedAt);

    return date ? `Verified by BuyTheWay since ${date}` : 'Verified by BuyTheWay';
});

/*
|--------------------------------------------------------------------------
| Browse State <-> URL
|--------------------------------------------------------------------------
*/

const state = reactive(
    storeIdFromQuery(window.location.search) === props.storeId
        ? storeStateFromQuery(window.location.search)
        : rememberedStoreState(props.storeId)
);

rememberStoreState(props.storeId, state);

function syncUrl() {
    rememberStoreState(props.storeId, state);

    const url = `${window.location.pathname}${queryForStoreState(props.storeId, state)}`;

    if (url !== `${window.location.pathname}${window.location.search}`) {
        window.history.replaceState(window.history.state, '', url);
    }
}

watch(state, syncUrl, { deep: true });

/*
|--------------------------------------------------------------------------
| Product Search (debounced, scoped to this store)
|--------------------------------------------------------------------------
*/

const queryInput = ref(state.q);
const searchInput = ref(null);
let searchTimer = null;

function commitSearch() {
    clearTimeout(searchTimer);

    const q = queryInput.value.trim();

    if (q !== state.q && state.tab !== 'products') {
        state.tab = 'products';
    }

    if (q !== state.q) {
        Object.assign(state, { q, page: 1 });
    }
}

watch(queryInput, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(commitSearch, SEARCH_DEBOUNCE);
});

function clearSearch() {
    queryInput.value = '';
    commitSearch();
    searchInput.value?.focus();
}

/*
|--------------------------------------------------------------------------
| Products
|--------------------------------------------------------------------------
*/

function productsEndpoint() {
    return storeProductsEndpoint(props.storeId, storeProductParams(state, PER_PAGE));
}

const cachedProducts = cachedResponse(productsEndpoint());

const products = ref(cachedProducts?.data || []);
const productsMeta = ref(cachedProducts?.meta || null);
const hasProducts = ref(Boolean(cachedProducts));
const productsLoading = ref(!cachedProducts);
const productsError = ref('');

const productsRequest = createLatestRequest();

async function loadProducts() {
    const cached = cachedResponse(productsEndpoint());

    if (cached) {
        products.value = cached.data || [];
        productsMeta.value = cached.meta || null;
        hasProducts.value = true;
    }

    productsLoading.value = true;
    productsError.value = '';

    try {
        const result = await productsRequest.run(productsEndpoint());

        if (result.stale) {
            return;
        }

        products.value = result.body.data || [];
        productsMeta.value = result.body.meta || null;
        hasProducts.value = true;
        productsLoading.value = false;

        if (productsMeta.value && productsMeta.value.total > 0 && state.page > productsMeta.value.last_page) {
            state.page = productsMeta.value.last_page;
        }
    } catch (err) {
        productsError.value = err?.message || 'Something went wrong while loading products.';
        productsLoading.value = false;
    }
}

watch(
    () => JSON.stringify(storeProductParams(state, PER_PAGE)),
    loadProducts
);

const productTotal = computed(() => productsMeta.value?.total ?? 0);
const totalPages = computed(() => productsMeta.value?.last_page ?? 1);

// The store has nothing listed at all (as opposed to nothing matching).
// An unfiltered empty result says the same thing before the store's
// facets have arrived.
const storeIsEmpty = computed(() =>
    facets.value?.total === 0
    || (hasProducts.value && !productsLoading.value && productTotal.value === 0 && !state.q && activeChips.value.length === 0)
);

const rangeStart = computed(() => (productTotal.value ? (state.page - 1) * PER_PAGE + 1 : 0));
const rangeEnd = computed(() => Math.min(state.page * PER_PAGE, productTotal.value));

const countLabel = computed(() => {
    if (!hasProducts.value) {
        return 'Loading products…';
    }

    const total = productTotal.value;
    const range = total > PER_PAGE ? `${rangeStart.value}–${rangeEnd.value} of ${total} products` : productCountLabel(total);

    return state.q ? `${range} matching` : range;
});

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

function capitalize(value) {
    return String(value).charAt(0).toUpperCase() + String(value).slice(1);
}

const filterGroups = computed(() => {
    const f = facets.value;

    if (!f || f.total === 0) {
        return [];
    }

    const groups = [];
    const availability = [];

    if (f.hasOutOfStock) {
        availability.push({ value: 'in_stock', label: 'In stock only' });
    }

    if (f.hasSale) {
        availability.push({ value: 'on_sale', label: 'On sale' });
    }

    if (availability.length) {
        groups.push({ key: 'availability', label: 'Availability', kind: 'checkbox', options: availability });
    }

    if ((f.conditions || []).length > 1) {
        groups.push({
            key: 'condition',
            label: 'Condition',
            kind: 'checkbox',
            options: f.conditions.map(value => ({ value, label: capitalize(value) }))
        });
    }

    if (f.priceMax !== null && f.priceMin !== null && f.priceMax > f.priceMin) {
        groups.push({
            key: 'price',
            label: 'Price',
            kind: 'price',
            bounds: { min: Math.floor(f.priceMin), max: Math.ceil(f.priceMax) }
        });
    }

    if (f.hasRatings) {
        groups.push({
            key: 'rating',
            label: 'Customer rating',
            kind: 'radio',
            options: [
                { value: 0, label: 'Any rating' },
                { value: 4, label: '4 stars & up' },
                { value: 3, label: '3 stars & up' }
            ]
        });
    }

    return groups;
});

function groupValue(source, group) {
    switch (group.key) {
        case 'availability':
            return [source.inStockOnly && 'in_stock', source.onSaleOnly && 'on_sale'].filter(Boolean);
        case 'condition':
            return source.conditions;
        case 'price':
            return { min: source.priceMin, max: source.priceMax };
        default:
            return source.minRating;
    }
}

function setGroupValue(target, group, value) {
    switch (group.key) {
        case 'availability':
            target.inStockOnly = value.includes('in_stock');
            target.onSaleOnly = value.includes('on_sale');
            break;
        case 'condition':
            target.conditions = value;
            break;
        case 'price':
            target.priceMin = value.min;
            target.priceMax = value.max;
            break;
        default:
            target.minRating = Number(value) || 0;
    }

    target.page = 1;
}

function groupHasValue(source, group) {
    const value = groupValue(source, group);

    if (group.key === 'price') {
        return value.min !== '' || value.max !== '';
    }

    return Array.isArray(value) ? value.length > 0 : Boolean(value);
}

function priceLabel(min, max) {
    if (min !== '' && max !== '') {
        return `${formatPrice(min)} – ${formatPrice(max)}`;
    }

    return min !== '' ? `From ${formatPrice(min)}` : `Up to ${formatPrice(max)}`;
}

// Built from the applied state itself, so a filter from an old link can
// always be removed even if this store no longer offers it.
const activeChips = computed(() => {
    const chips = [];

    if (state.inStockOnly) {
        chips.push({ key: 'in_stock', label: 'In stock only', remove: () => Object.assign(state, { inStockOnly: false, page: 1 }) });
    }

    if (state.onSaleOnly) {
        chips.push({ key: 'on_sale', label: 'On sale', remove: () => Object.assign(state, { onSaleOnly: false, page: 1 }) });
    }

    for (const condition of state.conditions) {
        chips.push({
            key: `condition:${condition}`,
            label: capitalize(condition),
            remove: () => Object.assign(state, { conditions: state.conditions.filter(c => c !== condition), page: 1 })
        });
    }

    if (state.priceMin !== '' || state.priceMax !== '') {
        chips.push({ key: 'price', label: `Price: ${priceLabel(state.priceMin, state.priceMax)}`, remove: () => Object.assign(state, { priceMin: '', priceMax: '', page: 1 }) });
    }

    if (state.minRating) {
        chips.push({ key: 'rating', label: `${state.minRating} stars & up`, remove: () => Object.assign(state, { minRating: 0, page: 1 }) });
    }

    return chips;
});

// The status line also lists the search, so one row shows everything
// narrowing the grid and "Clear all" resets it in one step.
const appliedChips = computed(() => [
    ...(state.q ? [{ key: 'q', label: `“${state.q}”`, remove: clearSearch }] : []),
    ...activeChips.value
]);

function clearEverything() {
    queryInput.value = '';
    Object.assign(state, { ...defaultStoreState(), sort: state.sort, tab: state.tab });
}

/*
|--------------------------------------------------------------------------
| Desktop: compact filter dropdowns
|--------------------------------------------------------------------------
*/

const openPanel = ref(null);
const toolbarRoot = ref(null);

function togglePanel(key) {
    openPanel.value = openPanel.value === key ? null : key;

    if (openPanel.value) {
        nextTick(() => {
            toolbarRoot.value?.querySelector(`#store-panel-${key} input:not([disabled])`)?.focus();
        });
    }
}

function panelSummary(group) {
    if (!groupHasValue(state, group)) {
        return '';
    }

    if (group.key === 'price') {
        return priceLabel(state.priceMin, state.priceMax);
    }

    if (group.key === 'rating') {
        return `${state.minRating}+`;
    }

    return String(groupValue(state, group).length);
}

function handleDocumentClick(event) {
    if (openPanel.value && toolbarRoot.value && !toolbarRoot.value.contains(event.target)) {
        openPanel.value = null;
    }
}

/*
|--------------------------------------------------------------------------
| Mobile: filter drawer (pending copy, committed by "Show results")
|--------------------------------------------------------------------------
*/

const drawerOpen = ref(false);
const pending = reactive(defaultStoreState());
const drawerClose = ref(null);
const filterButton = ref(null);

function openDrawer() {
    Object.assign(pending, sanitizeStoreState(JSON.parse(JSON.stringify(state))));
    drawerOpen.value = true;
    document.body.style.overflow = 'hidden';
    nextTick(() => drawerClose.value?.focus());
}

function closeDrawer() {
    if (!drawerOpen.value) {
        return;
    }

    drawerOpen.value = false;
    document.body.style.overflow = '';
    nextTick(() => filterButton.value?.focus());
}

function resetPending() {
    Object.assign(pending, { ...defaultStoreState(), q: state.q, sort: state.sort, tab: state.tab });
}

function applyPending() {
    Object.assign(state, {
        inStockOnly: pending.inStockOnly,
        onSaleOnly: pending.onSaleOnly,
        conditions: [...pending.conditions],
        priceMin: pending.priceMin,
        priceMax: pending.priceMax,
        minRating: pending.minRating,
        page: 1
    });
    closeDrawer();
}

function handleKeydown(event) {
    if (event.key !== 'Escape') {
        return;
    }

    if (drawerOpen.value) {
        closeDrawer();
    } else if (openPanel.value) {
        const key = openPanel.value;

        openPanel.value = null;
        nextTick(() => toolbarRoot.value?.querySelector(`[aria-controls="store-panel-${key}"]`)?.focus());
    }
}

/*
|--------------------------------------------------------------------------
| Sort + Pagination
|--------------------------------------------------------------------------
*/

const sortOptions = computed(() => [
    { id: 'newest', label: 'Newest' },
    ...(facets.value?.hasSold || state.sort === 'popular' ? [{ id: 'popular', label: 'Best selling' }] : []),
    { id: 'price-asc', label: 'Price: low to high' },
    { id: 'price-desc', label: 'Price: high to low' },
    ...(facets.value?.hasRatings || state.sort === 'rating' ? [{ id: 'rating', label: 'Top rated' }] : []),
    { id: 'name-asc', label: 'Name: A to Z' }
]);

function setSort(sort) {
    Object.assign(state, { sort, page: 1 });
}

const productsTop = ref(null);

function goToPage(n) {
    state.page = n;
    nextTick(() => productsTop.value?.scrollIntoView({
        block: 'start',
        behavior: prefersReducedMotion ? 'auto' : 'smooth'
    }));
}

/*
|--------------------------------------------------------------------------
| Tabs
|--------------------------------------------------------------------------
*/

const tabList = ref(null);

// Reviews only appears when the store has product reviews to show, so no
// tab ever opens onto an empty panel.
const hasReviews = computed(() => (identity.value?.reviewCount || 0) > 0);

const tabs = computed(() => [
    { id: 'products', label: 'Products', count: facets.value?.total ?? identity.value?.productCount ?? null },
    ...(hasReviews.value ? [{ id: 'reviews', label: 'Reviews', count: identity.value.reviewCount }] : []),
    { id: 'about', label: 'About' }
]);

// An old link to ?tab=reviews for a store that has none falls back.
watch(store, (value) => {
    if (value && state.tab === 'reviews' && !(value.reviewCount > 0)) {
        state.tab = 'products';
    }
});

function selectTab(id) {
    state.tab = id;
}

// Arrow keys move between tabs and select them (automatic activation).
function handleTabKeydown(event) {
    const ids = tabs.value.map(tab => tab.id);
    const index = ids.indexOf(state.tab);
    const next = {
        ArrowRight: (index + 1) % ids.length,
        ArrowLeft: (index - 1 + ids.length) % ids.length,
        Home: 0,
        End: ids.length - 1
    }[event.key];

    if (next === undefined) {
        return;
    }

    event.preventDefault();
    selectTab(ids[next]);
    nextTick(() => tabList.value?.querySelector(`#store-tab-${ids[next]}`)?.focus());
}

/*
|--------------------------------------------------------------------------
| Reviews tab
|--------------------------------------------------------------------------
|
| The store's product reviews (GET /api/stores/{id}/reviews), loaded the
| first time the tab opens, newest first with "Show more". The summary is
| the same set the store rating is averaged from, and every row says
| which product it reviews.
|
*/

const REVIEWS_PER_PAGE = 10;

const reviews = reactive({
    items: [],
    summary: null,
    page: 0,
    lastPage: 1,
    total: 0,
    rating: 0,
    loading: false,
    error: '',
    loaded: false
});

const reviewsRequest = createLatestRequest();

function reviewsUrl(page) {
    const params = new URLSearchParams({ page: String(page), per_page: String(REVIEWS_PER_PAGE) });

    if (reviews.rating) {
        params.set('rating', String(reviews.rating));
    }

    return `/api/stores/${encodeURIComponent(props.storeId)}/reviews?${params}`;
}

async function loadReviews({ append = false } = {}) {
    const page = append ? reviews.page + 1 : 1;

    reviews.loading = true;
    reviews.error = '';

    try {
        const result = await reviewsRequest.run(reviewsUrl(page));

        if (result.stale) {
            return;
        }

        const body = result.body;

        reviews.items = append ? [...reviews.items, ...(body.data || [])] : (body.data || []);
        reviews.summary = body.summary || null;
        reviews.page = body.meta?.current_page || page;
        reviews.lastPage = body.meta?.last_page || 1;
        reviews.total = body.meta?.total || 0;
        reviews.loaded = true;
    } catch (err) {
        reviews.error = err?.message || 'Could not load reviews.';
    } finally {
        reviews.loading = false;
    }
}

function filterReviews(star) {
    reviews.rating = reviews.rating === star ? 0 : star;
    loadReviews();
}

const reviewBars = computed(() => {
    const summary = reviews.summary;

    if (!summary?.total) {
        return [];
    }

    return [5, 4, 3, 2, 1].map(star => ({
        star,
        count: summary.breakdown?.[star] || 0,
        percent: Math.round(((summary.breakdown?.[star] || 0) / summary.total) * 100)
    }));
});

watch(() => state.tab, (tab) => {
    if (tab === 'reviews' && !reviews.loaded && !reviews.loading) {
        loadReviews();
    }
}, { immediate: true });

function reviewDate(iso) {
    const date = iso ? new Date(iso) : null;

    return date && !Number.isNaN(date.getTime())
        ? date.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
        : '';
}

const openingProductId = ref(null);

// Review rows only carry the product's id and name; the product page
// needs the full product, so fetch it first (and say so if it's gone).
async function openReviewedProduct(product) {
    if (!product?.id || openingProductId.value) {
        return;
    }

    openingProductId.value = product.id;

    try {
        const body = await fetchJson(`/api/products/${encodeURIComponent(product.id)}`);

        emit('select-product', body.data || body);
    } catch (err) {
        toasts.error(err?.status === 404 ? 'That product is no longer available.' : 'Could not open that product. Please try again.');
    } finally {
        openingProductId.value = null;
    }
}

/*
|--------------------------------------------------------------------------
| Featured strip
|--------------------------------------------------------------------------
|
| Only when the store has enough products that a highlight isn't just the
| grid again: best sellers when anything has been delivered, otherwise
| items on sale. Hidden while a search or filter narrows the grid.
|
*/

const FEATURED_LIMIT = 4;
const FEATURED_MIN_PRODUCTS = 6;

const featuredKind = computed(() => {
    const f = facets.value;

    if (!f || f.total < FEATURED_MIN_PRODUCTS) {
        return null;
    }

    if (f.hasSold) {
        return 'popular';
    }

    return f.hasSale ? 'sale' : null;
});

const featured = ref([]);
const featuredRequest = createLatestRequest();

async function loadFeatured() {
    const kind = featuredKind.value;

    if (!kind) {
        featured.value = [];

        return;
    }

    const params = kind === 'popular'
        ? { sort: 'popular', per_page: FEATURED_LIMIT }
        : { on_sale: true, per_page: FEATURED_LIMIT };
    const url = storeProductsEndpoint(props.storeId, params);
    const pick = items => (kind === 'popular' ? items.filter(item => item.soldCount > 0) : items);

    featured.value = pick(cachedResponse(url)?.data || []);

    try {
        const result = await featuredRequest.run(url);

        if (!result.stale) {
            featured.value = pick(result.body.data || []);
        }
    } catch {
        // The strip is optional; the full grid below still lists everything.
        featured.value = [];
    }
}

watch(featuredKind, loadFeatured);

const showFeatured = computed(() =>
    featured.value.length >= 2 && state.page === 1 && !state.q && activeChips.value.length === 0
);

function discountOf(product) {
    const price = Number(product.price);
    const oldPrice = Number(product.oldPrice);

    return oldPrice > price && price > 0 ? Math.round((1 - price / oldPrice) * 100) : 0;
}

function seeAllFeatured() {
    if (featuredKind.value === 'popular') {
        setSort('popular');
    } else {
        Object.assign(state, { onSaleOnly: true, page: 1 });
    }
}

/*
|--------------------------------------------------------------------------
| Follow Store
|--------------------------------------------------------------------------
|
| Signed-in buyers toggle a follow through /api/buyer/follows/{id}; the
| button waits for the server rather than flipping optimistically, and a
| failure leaves the previous state with a toast. Guests get a sign-in
| link instead.
|
*/

const toasts = useToasts();
const { buyerProfile } = useBuyerSession();

const storeReportOpen = ref(false);
const storeReportReason = ref('');
const storeReportDetails = ref('');
const storeReportAnonymous = ref(false);
const storeReportFiles = ref([]);
const storeReportBusy = ref(false);
const storeReportSubmitError = ref('');
const storeReportDropActive = ref(false);
const storeReportEvidenceInput = ref(null);
const storeReportPreviewUrls = new Map();
watch(storeReportOpen, (isOpen) => {
    if (!isOpen) {
        for (const url of storeReportPreviewUrls.values()) URL.revokeObjectURL(url);
        storeReportPreviewUrls.clear();
    }
});
const storeReportReasons = [
    { value: 'fraud_deception', label: 'Fraud and Deception' },
    { value: 'legal_regulatory', label: 'Legal and Regulatory' },
    { value: 'discriminatory_offensive', label: 'Discriminatory or Offensive Conduct' },
    { value: 'policy_violations', label: 'Policy Violations' },
    { value: 'product_issues', label: 'Product Issues' },
    { value: 'pricing_fees', label: 'Pricing and Fees' },
    { value: 'shipping_problems', label: 'Shipping Problems' },
    { value: 'customer_service', label: 'Customer Service and Communication' },
];
const storeActionsMenu = ref(null);
const storeBlockBusy = ref(false);

function closeStoreActions() {
    storeActionsMenu.value?.removeAttribute('open');
}

async function shareStore() {
    closeStoreActions();
    try {
        await navigator.clipboard.writeText(new URL(storePageUrl(props.storeId), window.location.origin).toString());
        toasts.success('✓ Shop Link copied', { timeout: 2500 });
    } catch {
        toasts.error('Could not copy the shop link.');
    }
}

function openStoreReportFromMenu() {
    closeStoreActions();
    openStoreReport();
}

async function blockStore() {
    closeStoreActions();
    if (!buyerProfile.value) {
        toasts.warning('Sign in to block a shop.');
        return;
    }
    if (storeBlockBusy.value || !window.confirm(`Block ${identity.value?.name || 'this shop'}? Its shop and products will be hidden from you. Order conversations remain available.`)) return;

    storeBlockBusy.value = true;
    try {
        await buyerApi(`/buyer/stores/${encodeURIComponent(props.storeId)}/block`, { method: 'POST' });
        clearBuyerStoreCache();
        toasts.success('Shop blocked.');
        emit('back');
    } catch (err) {
        toasts.error(err?.message || 'Could not block this shop.');
    } finally {
        storeBlockBusy.value = false;
    }
}

function openStoreReport() {
    if (!buyerProfile.value) {
        toasts.warning('Sign in to report this store.');
        return;
    }

    storeReportReason.value = '';
    storeReportDetails.value = '';
    storeReportAnonymous.value = false;
    storeReportFiles.value = [];
    storeReportSubmitError.value = '';
    for (const url of storeReportPreviewUrls.values()) URL.revokeObjectURL(url);
    storeReportPreviewUrls.clear();
    storeReportOpen.value = true;
}

function selectStoreReportFiles(event) {
    addStoreReportFiles(event.target.files);
    event.target.value = '';
}

function addStoreReportFiles(fileList) {
    const next = [...storeReportFiles.value];
    for (const file of Array.from(fileList || [])) {
        if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/webm', 'video/quicktime'].includes(file.type)) {
            toasts.error(`${file.name} isn't a supported image or video.`);
            continue;
        }
        if (file.size > 50 * 1024 * 1024) {
            toasts.error(`${file.name} is larger than 50 MB.`);
            continue;
        }
        if (next.length >= 8) {
            toasts.warning('You can attach up to 8 files.');
            break;
        }
        if (!next.some(existing => existing.name === file.name && existing.size === file.size && existing.lastModified === file.lastModified)) next.push(file);
    }
    storeReportFiles.value = next;
}

function removeStoreReportFile(index) {
    const [removed] = storeReportFiles.value.splice(index, 1);
    if (removed && storeReportPreviewUrls.has(removed)) {
        URL.revokeObjectURL(storeReportPreviewUrls.get(removed));
        storeReportPreviewUrls.delete(removed);
    }
}

function storeReportPreviewUrl(file) {
    if (!storeReportPreviewUrls.has(file)) storeReportPreviewUrls.set(file, URL.createObjectURL(file));
    return storeReportPreviewUrls.get(file);
}

function handleStoreReportDrop(event) {
    storeReportDropActive.value = false;
    addStoreReportFiles(event.dataTransfer?.files);
}

async function submitStoreReport() {
    if (!buyerProfile.value || !props.storeId || !storeReportReason.value || !storeReportDetails.value.trim() || storeReportBusy.value) return;
    storeReportBusy.value = true;
    storeReportSubmitError.value = '';

    try {
        const evidence = await Promise.all(storeReportFiles.value.map(async (file) => {
            const form = new FormData();
            form.append('file', file);
            form.append('path', `${buyerProfile.value.id}/reports/${crypto.randomUUID()}-${file.name.replace(/[^A-Za-z0-9_.-]/g, '_')}`);
            return (await buyerApi('/storage/report-evidence', { method: 'POST', body: form })).path;
        }));

        await buyerApi('/buyer/reports', {
            method: 'POST',
            body: JSON.stringify({
                target_type: 'store',
                target_id: props.storeId,
                reason: storeReportReason.value,
                details: storeReportDetails.value.trim(),
                anonymous: storeReportAnonymous.value,
                evidence,
            }),
        });

        storeReportOpen.value = false;
        toasts.success('Shop report submitted for review.');
    } catch (err) {
        storeReportSubmitError.value = err?.message || 'Could not submit this report. Your details are still here; please try again.';
    } finally {
        storeReportBusy.value = false;
    }
}

const isFollowing = ref(false);
const followerCount = ref(null);
const followBusy = ref(false);
const followReady = ref(false);

watch(store, (value) => {
    if (value && typeof value.followerCount === 'number') {
        followerCount.value = value.followerCount;
    }
}, { immediate: true });

const followerLabel = computed(() => {
    const count = followerCount.value;

    if (!count) {
        return '';
    }

    return `${count.toLocaleString('en-PH')} ${count === 1 ? 'follower' : 'followers'}`;
});

function applyFollowStatus(status) {
    isFollowing.value = Boolean(status?.isFollowing);

    if (typeof status?.followerCount === 'number') {
        followerCount.value = status.followerCount;
    }
}

async function loadFollowStatus() {
    if (!buyerProfile.value) {
        followReady.value = true;

        return;
    }

    try {
        applyFollowStatus(await buyerApi(`/buyer/follows/${encodeURIComponent(props.storeId)}`));
    } catch {
        // Status is a nicety; the button still works and will correct
        // itself from the response of the first toggle.
    } finally {
        followReady.value = true;
    }
}

async function toggleFollow() {
    if (followBusy.value) {
        return;
    }

    const following = isFollowing.value;

    followBusy.value = true;

    try {
        applyFollowStatus(await buyerApi(`/buyer/follows/${encodeURIComponent(props.storeId)}`, {
            method: following ? 'DELETE' : 'POST'
        }));

        toasts.success(following ? `Unfollowed ${identity.value?.name || 'this store'}.` : `Following ${identity.value?.name || 'this store'}.`);
    } catch (err) {
        toasts.error(err?.status === 401
            ? 'Your session has ended. Sign in again to follow stores.'
            : err?.status === 403
                ? 'Only buyer accounts can follow stores.'
                : err?.message || 'Could not update your follow. Please try again.');
    } finally {
        followBusy.value = false;
    }
}

watch(buyerProfile, () => loadFollowStatus());

/*
|--------------------------------------------------------------------------
| Message Store
|--------------------------------------------------------------------------
*/

const { messageSeller } = useBuyerChat();

function messageStore() {
    messageSeller({
        sellerId: props.storeId,
        seller: identity.value?.name || '',
        sellerLogo: identity.value?.logo || null,
        sellerCategory: identity.value?.category || null
    });
}

/*
|--------------------------------------------------------------------------
| Lifecycle
|--------------------------------------------------------------------------
*/

onMounted(() => {
    loadStore();
    loadProducts();
    loadFeatured();
    loadFollowStatus();
    document.addEventListener('keydown', handleKeydown);
    document.addEventListener('click', handleDocumentClick);
});

onUnmounted(() => {
    storeRequest.cancel();
    productsRequest.cancel();
    featuredRequest.cancel();
    reviewsRequest.cancel();
    clearTimeout(searchTimer);
    document.removeEventListener('keydown', handleKeydown);
    document.removeEventListener('click', handleDocumentClick);
    document.body.style.overflow = '';
});

const skeletons = Array.from({ length: 8 });
</script>

<template>

    <div class="buyer-page">

        <Header
            active-view="stores"
            @select-category="emit('select-category', $event)"
            @cart-click="emit('open-cart')"
            @account-click="emit('account-click')"
            @logo-click="emit('back')"
            @search="emit('search', $event)"
        />

        <main
            id="main-content"
            class="buyer-main store-page"
            :class="accentClass"
            tabindex="-1"
        >

            <nav
                class="crumbs"
                aria-label="Breadcrumb"
            >
                <ol>
                    <li>
                        <button
                            type="button"
                            @click="emit('back')"
                        >
                            Home
                        </button>
                    </li>
                    <li>
                        <button
                            type="button"
                            @click="emit('open-stores')"
                        >
                            Stores
                        </button>
                    </li>
                    <li aria-current="page">{{ identity?.name || 'Store' }}</li>
                </ol>
            </nav>

            <!-- Store not available -->
            <div
                v-if="storeMissing"
                class="state-block"
                role="alert"
            >
                <h1 class="store-missing-title">This store isn&rsquo;t available</h1>
                <p>It may have closed, or the link may be out of date. Other sellers are still open.</p>
                <button
                    type="button"
                    class="btn btn-primary"
                    @click="emit('open-stores')"
                >
                    Browse stores
                </button>
            </div>

            <div
                v-else-if="storeError && !identity"
                class="state-block"
                role="alert"
            >
                <h1 class="store-missing-title">We couldn&rsquo;t load this store</h1>
                <p>{{ storeError }}</p>
                <button
                    type="button"
                    class="btn btn-primary"
                    @click="loadStore"
                >
                    Try again
                </button>
            </div>

            <template v-else>

                <!-- ============================================== -->
                <!-- IDENTITY -->
                <!-- ============================================== -->

                <header
                    class="store-hero"
                    :class="{ 'has-banner': identity?.banner }"
                >
                    <!-- Seller banner, or a flat band in the store's category tint -->
                    <div
                        class="store-cover"
                        :class="identity?.banner ? null : accentClass"
                    >
                        <img
                            v-if="identity?.banner"
                            :src="identity.banner"
                            alt=""
                            width="1600"
                            height="320"
                            decoding="async"
                        >
                    </div>

                    <div
                        v-if="!identity"
                        class="store-identity is-skeleton"
                        aria-hidden="true"
                    >
                        <span class="skeleton store-logo is-lg"></span>
                        <span class="store-identity-text">
                            <span class="skeleton is-line store-skeleton-title"></span>
                            <span class="skeleton is-line is-short"></span>
                        </span>
                    </div>

                    <div
                        v-else
                        class="store-identity"
                    >
                        <StoreLogo
                            size="lg"
                            :name="identity.name"
                            :src="identity.logo || ''"
                            :category="identity.category || ''"
                            :lazy="false"
                        />

                        <div class="store-identity-text">
                            <div class="store-name-row">
                                <h1 class="store-name">{{ identity.name }}</h1>
                                <span
                                    v-if="identity.isVerified"
                                    class="store-verified"
                                    :title="verifiedLabel"
                                >
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M12 1.8 14.6 4l3.4-.3.9 3.3 3 1.7-1.3 3.2 1.3 3.2-3 1.7-.9 3.3-3.4-.3L12 22.2 9.4 20l-3.4.3-.9-3.3-3-1.7 1.3-3.2L2.1 8.7l3-1.7.9-3.3 3.4.3z" /><path d="m8.2 12.2 2.6 2.6 5-5.2" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                    Verified
                                    <span class="sr-only">: {{ verifiedLabel }}</span>
                                </span>
                                <button
                                    v-if="identity.category"
                                    type="button"
                                    class="store-meta-link"
                                    :aria-label="`Shop all ${identity.category}`"
                                    @click="emit('select-category', identity.category)"
                                >
                                    {{ identity.category }}
                                </button>
                            </div>

                            <p
                                v-if="identity.description"
                                class="store-desc is-clamped"
                            >
                                {{ identity.description }}
                            </p>

                            <ul
                                class="store-meta"
                                aria-label="Store details"
                            >
                                <li
                                    class="store-meta-item store-rating"
                                    :class="{ 'is-empty': !hasRating }"
                                >
                                    <template v-if="hasRating">
                                        <svg class="store-star" viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M12 2.8l2.8 5.9 6.4.8-4.7 4.4 1.2 6.4L12 17.2l-5.7 3.1 1.2-6.4-4.7-4.4 6.4-.8z" /></svg>
                                        <strong>{{ identity.rating.toFixed(1) }}</strong>
                                        <span class="sr-only">out of 5 from</span>
                                        <span>{{ identity.reviewCount }} product {{ identity.reviewCount === 1 ? 'review' : 'reviews' }}</span>
                                    </template>
                                    <template v-else>
                                        No product reviews yet
                                    </template>
                                </li>
                                <li
                                    v-if="identity.location"
                                    class="store-meta-item"
                                >
                                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 5-8 12-8 12s-8-7-8-12a8 8 0 0 1 16 0Z" /><circle cx="12" cy="10" r="3" /></svg>
                                    {{ identity.location }}
                                </li>
                                <li
                                    v-if="followerLabel"
                                    class="store-meta-item"
                                >
                                    {{ followerLabel }}
                                </li>
                                <li
                                    v-if="joined"
                                    class="store-meta-item store-meta-joined"
                                >
                                    Joined {{ joined }}
                                </li>
                            </ul>
                        </div>

                        <div class="store-actions">
                            <button
                                v-if="buyerProfile"
                                type="button"
                                class="btn store-follow"
                                :class="isFollowing ? 'btn-secondary is-following' : 'btn-primary'"
                                :aria-pressed="isFollowing"
                                :aria-busy="followBusy"
                                :disabled="followBusy || !followReady"
                                @click="toggleFollow"
                            >
                                <svg v-if="isFollowing" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                                <svg v-else viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                                <span v-if="followBusy">{{ isFollowing ? 'Unfollowing…' : 'Following…' }}</span>
                                <span v-else>{{ isFollowing ? 'Following' : 'Follow store' }}</span>
                            </button>
                            <a
                                v-else
                                href="/login"
                                class="btn btn-primary store-follow"
                            >
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                                Sign in to follow
                            </a>
                            <button
                                v-if="buyerProfile"
                                type="button"
                                class="btn btn-secondary"
                                aria-haspopup="dialog"
                                @click="messageStore"
                            >
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.9A8 8 0 1 1 21 12Z" /></svg>
                                Chat with seller
                            </button>
                            <a
                                v-else
                                href="/login"
                                class="btn btn-secondary"
                            >
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.9A8 8 0 1 1 21 12Z" /></svg>
                                Sign in to chat
                            </a>
                            <details ref="storeActionsMenu" class="store-actions-menu">
                                <summary aria-label="Shop actions" title="Shop actions">
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
                                </summary>
                                <div class="store-actions-popover" role="menu">
                                    <button type="button" role="menuitem" @click="shareStore">Share shop</button>
                                    <button type="button" role="menuitem" @click="openStoreReportFromMenu">Report this user</button>
                                    <button type="button" role="menuitem" :disabled="storeBlockBusy" @click="blockStore">{{ storeBlockBusy ? 'Blocking…' : 'Block this user' }}</button>
                                </div>
                            </details>
                        </div>
                    </div>

                </header>

                <!-- ============================================== -->
                <!-- PRODUCTS -->
                <!-- ============================================== -->

                <!-- Store nav: sections + search, sticky on desktop -->
                <div class="store-nav">
                <div
                    ref="tabList"
                    class="store-tabs"
                    role="tablist"
                    aria-label="Store sections"
                >
                    <button
                        v-for="tab in tabs"
                        :id="`store-tab-${tab.id}`"
                        :key="tab.id"
                        type="button"
                        role="tab"
                        class="store-tab"
                        :class="{ 'is-active': state.tab === tab.id }"
                        :aria-selected="state.tab === tab.id"
                        :aria-controls="`store-tabpanel-${tab.id}`"
                        :tabindex="state.tab === tab.id ? 0 : -1"
                        @click="selectTab(tab.id)"
                        @keydown="handleTabKeydown"
                    >
                        {{ tab.label }}
                        <span
                            v-if="typeof tab.count === 'number'"
                            class="store-tab-count"
                        >{{ tab.count }}</span>
                    </button>
                </div>

                <form
                    v-if="!storeIsEmpty"
                    class="store-search"
                    role="search"
                    aria-label="This store's products"
                    @submit.prevent="commitSearch"
                >
                    <label
                        for="store-product-search"
                        class="sr-only"
                    >Search products in this store</label>
                    <div class="stores-search-field">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                        <input
                            id="store-product-search"
                            ref="searchInput"
                            v-model="queryInput"
                            type="search"
                            placeholder="Search products in this store"
                            autocomplete="off"
                            enterkeyhint="search"
                        >
                        <button
                            v-if="queryInput"
                            type="button"
                            class="stores-search-clear"
                            aria-label="Clear product search"
                            @click="clearSearch"
                        >
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                        </button>
                    </div>
                </form>
                </div>

                <StoreVoucherStrip :store-id="storeId" :signed-in="Boolean(buyerProfile)" />

                <Transition
                    name="store-tab"
                    mode="out-in"
                >
                <section
                    v-if="state.tab === 'products'"
                    id="store-tabpanel-products"
                    key="products"
                    ref="productsTop"
                    class="store-products"
                    role="tabpanel"
                    aria-labelledby="store-tab-products"
                >
                    <!-- Featured: best sellers or items on sale, compact -->
                    <section
                        v-if="showFeatured"
                        class="store-featured"
                        aria-labelledby="store-featured-title"
                    >
                        <div class="store-featured-head">
                            <h2
                                id="store-featured-title"
                                class="store-featured-title"
                            >
                                {{ featuredKind === 'popular' ? 'Best sellers' : 'On sale now' }}
                            </h2>
                            <button
                                type="button"
                                class="link-btn"
                                @click="seeAllFeatured"
                            >
                                {{ featuredKind === 'popular' ? 'Sort by best selling' : 'See all deals' }}
                            </button>
                        </div>

                        <ol class="store-featured-list">
                            <li
                                v-for="(item, index) in featured"
                                :key="item.id"
                            >
                                <a
                                    :href="`#product-${item.id}`"
                                    class="store-feature"
                                    @click.prevent="emit('select-product', item)"
                                >
                                    <span
                                        v-if="featuredKind === 'popular'"
                                        class="store-feature-rank"
                                        aria-hidden="true"
                                    >{{ index + 1 }}</span>
                                    <span class="store-feature-media">
                                        <img
                                            v-if="item.image"
                                            :src="item.image"
                                            alt=""
                                            width="60"
                                            height="60"
                                            loading="lazy"
                                            decoding="async"
                                        >
                                    </span>
                                    <span class="store-feature-text">
                                        <span class="store-feature-name">{{ item.name }}</span>
                                        <span class="store-feature-price">
                                            {{ formatPrice(item.price) }}
                                            <s
                                                v-if="discountOf(item)"
                                                class="store-feature-old"
                                            ><span class="sr-only">Was </span>{{ formatPrice(item.oldPrice) }}</s>
                                        </span>
                                        <span class="store-feature-note">
                                            <template v-if="featuredKind === 'popular'">{{ item.soldCount }} sold</template>
                                            <template v-else>{{ discountOf(item) }}% off</template>
                                        </span>
                                    </span>
                                </a>
                            </li>
                        </ol>
                    </section>

                    <!-- All products: heading + count, filters, sort -->
                    <div
                        v-if="!storeIsEmpty"
                        ref="toolbarRoot"
                        class="store-toolbar"
                    >
                        <div class="store-toolbar-title">
                            <h2
                                id="store-products-title"
                                class="store-section-title"
                            >
                                {{ state.q || activeChips.length ? 'Results' : 'All products' }}
                            </h2>
                            <p
                                class="cat-count"
                                role="status"
                                aria-live="polite"
                            >
                                {{ countLabel }}
                            </p>
                        </div>

                        <div
                            v-if="filterGroups.length"
                            class="cat-quick"
                        >
                            <div
                                v-for="group in filterGroups"
                                :key="group.key"
                                class="cat-quick-item"
                            >
                                <button
                                    type="button"
                                    class="cat-quick-btn"
                                    :class="{ 'is-active': groupHasValue(state, group), 'is-open': openPanel === group.key }"
                                    :aria-expanded="openPanel === group.key"
                                    :aria-controls="`store-panel-${group.key}`"
                                    @click.stop="togglePanel(group.key)"
                                >
                                    {{ group.label }}
                                    <span
                                        v-if="panelSummary(group)"
                                        class="cat-quick-value"
                                    >{{ panelSummary(group) }}</span>
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
                                </button>
                                <div
                                    v-show="openPanel === group.key"
                                    :id="`store-panel-${group.key}`"
                                    class="cat-quick-panel"
                                    role="region"
                                    :aria-label="`${group.label} filter`"
                                >
                                    <FilterGroup
                                        :group="group"
                                        :model-value="groupValue(state, group)"
                                        :debounce-ms="PRICE_DEBOUNCE"
                                        id-prefix="store-quick"
                                        @update:model-value="setGroupValue(state, group, $event)"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="store-toolbar-end">
                            <button
                                v-if="filterGroups.length"
                                ref="filterButton"
                                type="button"
                                class="cat-filter-btn"
                                aria-controls="store-filter-drawer"
                                :aria-expanded="drawerOpen"
                                @click="openDrawer"
                            >
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4" /></svg>
                                Filters
                                <span
                                    v-if="activeChips.length"
                                    class="count-pill"
                                >{{ activeChips.length }}<span class="sr-only"> applied</span></span>
                            </button>

                            <label class="select-field">
                                <span class="select-field-label">Sort by</span>
                                <select
                                    :value="state.sort"
                                    @change="setSort($event.target.value)"
                                >
                                    <option
                                        v-for="option in sortOptions"
                                        :key="option.id"
                                        :value="option.id"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                            </label>
                        </div>
                    </div>

                    <!-- Applied search + filters, Clear all -->
                    <div
                        v-if="!storeIsEmpty && appliedChips.length"
                        class="store-status"
                    >
                        <div
                            v-if="appliedChips.length"
                            class="chip-row store-chips"
                            role="group"
                            aria-label="Applied filters"
                        >
                            <button
                                v-for="chip in appliedChips"
                                :key="chip.key"
                                type="button"
                                class="chip is-removable"
                                :aria-label="chip.key === 'q' ? `Remove search ${state.q}` : `Remove filter ${chip.label}`"
                                @click="chip.remove()"
                            >
                                {{ chip.label }}
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                            </button>
                            <button
                                type="button"
                                class="link-btn"
                                @click="clearEverything"
                            >
                                Clear all
                            </button>
                        </div>
                    </div>

                    <div class="store-products-body">

                        <ul
                            v-if="!hasProducts && !productsError"
                            class="product-grid listing-grid"
                            aria-hidden="true"
                        >
                            <li
                                v-for="(_, index) in skeletons"
                                :key="index"
                                class="pcard-skeleton"
                            >
                                <span class="skeleton is-media"></span>
                                <span class="skeleton is-line"></span>
                                <span class="skeleton is-line is-short"></span>
                            </li>
                        </ul>

                        <div
                            v-else-if="productsError && !products.length"
                            class="state-block"
                            role="alert"
                        >
                            <h3>We couldn&rsquo;t load this store&rsquo;s products</h3>
                            <p>{{ productsError }}</p>
                            <button
                                type="button"
                                class="btn btn-primary"
                                @click="loadProducts"
                            >
                                Try again
                            </button>
                        </div>

                        <div
                            v-else-if="storeIsEmpty"
                            class="state-block"
                        >
                            <h3>{{ identity?.name || 'This store' }} hasn&rsquo;t listed anything yet</h3>
                            <p>New listings show up here as soon as the seller publishes them.</p>
                            <div class="cat-no-results-actions">
                                <button
                                    v-if="identity?.category"
                                    type="button"
                                    class="btn btn-secondary"
                                    @click="emit('select-category', identity.category)"
                                >
                                    Shop {{ identity.category }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    @click="emit('open-stores')"
                                >
                                    Browse other stores
                                </button>
                            </div>
                        </div>

                        <div
                            v-else-if="productTotal === 0 && !productsLoading"
                            class="state-block cat-no-results"
                        >
                            <h3 v-if="state.q">No products match &ldquo;{{ state.q }}&rdquo; in this store</h3>
                            <h3 v-else>No products match these filters</h3>
                            <p v-if="activeChips.length">
                                {{ activeChips.length === 1 ? 'Remove the filter below' : 'Remove one of the filters below' }} to see more.
                            </p>
                            <p v-else>Check the spelling, or try a shorter word.</p>
                            <div
                                v-if="activeChips.length"
                                class="chip-row"
                            >
                                <button
                                    v-for="chip in activeChips"
                                    :key="`nr-${chip.key}`"
                                    type="button"
                                    class="chip is-removable"
                                    :aria-label="`Remove filter ${chip.label}`"
                                    @click="chip.remove()"
                                >
                                    {{ chip.label }}
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                                </button>
                            </div>
                            <div class="cat-no-results-actions">
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    @click="clearEverything"
                                >
                                    {{ facets?.total ? `Show all ${productCountLabel(facets.total)}` : 'Clear search and filters' }}
                                </button>
                            </div>
                            <button
                                v-if="state.q"
                                type="button"
                                class="link-btn"
                                @click="emit('search', state.q)"
                            >
                                Search all of BuyTheWay for &ldquo;{{ state.q }}&rdquo;
                            </button>
                        </div>

                        <template v-else>
                            <p
                                v-if="productsError"
                                class="stores-inline-error"
                                role="alert"
                            >
                                Couldn&rsquo;t refresh these products. {{ productsError }}
                                <button
                                    type="button"
                                    class="link-btn"
                                    @click="loadProducts"
                                >
                                    Try again
                                </button>
                            </p>

                            <ul
                                class="product-grid listing-grid"
                                :class="{ 'is-refreshing': productsLoading }"
                                :aria-busy="productsLoading"
                            >
                                <li
                                    v-for="product in products"
                                    :key="product.id"
                                >
                                    <ProductCard
                                        :product="product"
                                        hide-seller
                                        @view="emit('select-product', $event)"
                                    />
                                </li>
                            </ul>

                            <PageNav
                                :page="state.page"
                                :total-pages="totalPages"
                                label="Product pages"
                                @change="goToPage"
                            />
                        </template>
                    </div>
                </section>

                <!-- ============================================== -->
                <!-- REVIEWS (product reviews, only when they exist) -->
                <!-- ============================================== -->

                <section
                    v-else-if="state.tab === 'reviews'"
                    id="store-tabpanel-reviews"
                    key="reviews"
                    class="store-reviews"
                    role="tabpanel"
                    aria-labelledby="store-tab-reviews"
                >
                    <div class="store-reviews-summary">
                        <h2 class="store-section-title">Product reviews</h2>
                        <template v-if="reviews.summary?.total">
                            <p class="store-reviews-average">
                                <strong>{{ reviews.summary.average.toFixed(1) }}</strong>
                                <span class="sr-only">out of 5</span>
                            </p>
                            <StarRating
                                :rating="reviews.summary.average"
                                :size="18"
                            />
                            <p class="store-reviews-basis">
                                Average of {{ reviews.summary.total }} {{ reviews.summary.total === 1 ? 'review' : 'reviews' }} across this store&rsquo;s products
                            </p>

                            <div
                                class="store-review-bars"
                                role="group"
                                aria-label="Filter reviews by star rating"
                            >
                                <button
                                    v-for="bar in reviewBars"
                                    :key="bar.star"
                                    type="button"
                                    class="store-review-bar"
                                    :class="{ 'is-active': reviews.rating === bar.star }"
                                    :aria-pressed="reviews.rating === bar.star"
                                    :disabled="!bar.count"
                                    @click="filterReviews(bar.star)"
                                >
                                    <span class="store-review-bar-label">{{ bar.star }} star</span>
                                    <span
                                        class="store-review-bar-track"
                                        aria-hidden="true"
                                    ><span :style="{ width: `${bar.percent}%` }"></span></span>
                                    <span class="store-review-bar-count">{{ bar.count }}</span>
                                </button>
                            </div>
                        </template>
                        <template v-else-if="!reviews.loaded">
                            <span class="skeleton is-line store-skeleton-title"></span>
                            <span class="skeleton is-line is-short"></span>
                        </template>
                    </div>

                    <div class="store-reviews-list">
                        <p
                            v-if="reviews.rating"
                            class="store-reviews-filter"
                            role="status"
                        >
                            Showing {{ reviews.total }} {{ reviews.rating }}-star {{ reviews.total === 1 ? 'review' : 'reviews' }}
                            <button
                                type="button"
                                class="link-btn"
                                @click="filterReviews(reviews.rating)"
                            >
                                Show all
                            </button>
                        </p>

                        <div
                            v-if="reviews.error && !reviews.items.length"
                            class="state-block"
                            role="alert"
                        >
                            <h3>We couldn&rsquo;t load the reviews</h3>
                            <p>{{ reviews.error }}</p>
                            <button
                                type="button"
                                class="btn btn-primary"
                                @click="loadReviews()"
                            >
                                Try again
                            </button>
                        </div>

                        <ul
                            v-else-if="!reviews.loaded"
                            class="store-review-items"
                            aria-hidden="true"
                        >
                            <li
                                v-for="n in 3"
                                :key="n"
                                class="store-review is-skeleton"
                            >
                                <span class="skeleton is-line is-short"></span>
                                <span class="skeleton is-line"></span>
                                <span class="skeleton is-line"></span>
                            </li>
                        </ul>

                        <ul
                            v-else
                            class="store-review-items"
                            :aria-busy="reviews.loading"
                        >
                            <li
                                v-for="review in reviews.items"
                                :key="review.id"
                                class="store-review"
                            >
                                <button
                                    v-if="review.product"
                                    type="button"
                                    class="store-review-product"
                                    :disabled="openingProductId === review.product.id"
                                    @click="openReviewedProduct(review.product)"
                                >
                                    <span class="store-review-thumb">
                                        <img
                                            v-if="review.product.image"
                                            :src="review.product.image"
                                            alt=""
                                            width="44"
                                            height="44"
                                            loading="lazy"
                                            decoding="async"
                                        >
                                    </span>
                                    <span class="store-review-product-name">{{ review.product.name }}</span>
                                    <span
                                        v-if="openingProductId === review.product.id"
                                        class="store-review-opening"
                                    >Opening…</span>
                                </button>

                                <div class="store-review-meta">
                                    <StarRating
                                        :rating="review.rating"
                                        :size="14"
                                    />
                                    <span class="store-review-author">{{ review.author }}</span>
                                    <span
                                        v-if="review.verifiedPurchase"
                                        class="store-review-verified"
                                    >Verified purchase</span>
                                    <time :datetime="review.createdAt">{{ reviewDate(review.createdAt) }}</time>
                                </div>

                                <p
                                    v-if="review.variant"
                                    class="store-review-variant"
                                >
                                    {{ review.variant }}
                                </p>
                                <p
                                    v-if="review.comment"
                                    class="store-review-comment"
                                >
                                    {{ review.comment }}
                                </p>

                                <div
                                    v-if="review.sellerResponse"
                                    class="store-review-response"
                                >
                                    <p class="store-review-response-label">Response from {{ identity?.name || 'the seller' }}</p>
                                    <p>{{ review.sellerResponse }}</p>
                                </div>
                            </li>
                        </ul>

                        <p
                            v-if="reviews.loaded && !reviews.items.length && !reviews.error"
                            class="store-reviews-empty"
                        >
                            No {{ reviews.rating ? `${reviews.rating}-star ` : '' }}reviews to show.
                        </p>

                        <button
                            v-if="reviews.loaded && reviews.page < reviews.lastPage"
                            type="button"
                            class="btn btn-secondary store-reviews-more"
                            :disabled="reviews.loading"
                            @click="loadReviews({ append: true })"
                        >
                            {{ reviews.loading ? 'Loading…' : 'Show more reviews' }}
                        </button>
                    </div>
                </section>

                <!-- ============================================== -->
                <!-- ABOUT -->
                <!-- ============================================== -->

                <section
                    v-else
                    id="store-tabpanel-about"
                    key="about"
                    class="store-about"
                    role="tabpanel"
                    aria-labelledby="store-tab-about"
                >
                    <div class="store-about-main">
                        <h2 class="store-about-title">
                            About {{ identity?.name || 'this store' }}
                        </h2>
                        <p
                            v-if="identity?.description"
                            class="store-about-desc"
                        >
                            {{ identity.description }}
                        </p>
                        <p
                            v-else
                            class="store-about-desc is-muted"
                        >
                            {{ identity?.name || 'This store' }} hasn&rsquo;t added a store description yet.
                        </p>

                        <dl
                            v-if="store"
                            class="store-facts"
                        >
                            <div v-if="store.category">
                                <dt>Sells</dt>
                                <dd>{{ store.category }}</dd>
                            </div>
                            <div v-if="store.location">
                                <dt>Based in</dt>
                                <dd>{{ store.location }}</dd>
                            </div>
                            <div v-if="joined">
                                <dt>On BuyTheWay since</dt>
                                <dd>{{ joined }}</dd>
                            </div>
                            <div>
                                <dt>Listings</dt>
                                <dd>{{ store.productCount > 0 ? productCountLabel(store.productCount) : 'None yet' }}</dd>
                            </div>
                            <div>
                                <dt>Product reviews</dt>
                                <dd v-if="hasRating">{{ store.rating.toFixed(1) }} average from {{ store.reviewCount }} {{ store.reviewCount === 1 ? 'review' : 'reviews' }}</dd>
                                <dd v-else>None yet</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="store-policies">
                        <section
                            class="store-policy"
                            aria-labelledby="store-policy-shipping"
                        >
                            <h3 id="store-policy-shipping">Shipping</h3>
                            <ul
                                v-if="deliveryTerms.length"
                                class="store-policy-list"
                            >
                                <li
                                    v-for="term in deliveryTerms"
                                    :key="term.id"
                                >
                                    {{ term.label }}
                                </li>
                            </ul>
                            <p v-if="shippingPerStoreOrder">Charged once per order from this store, however many items it holds.</p>
                            <p v-if="!deliveryTerms.length">Shown at checkout.</p>
                        </section>

                        <section
                            class="store-policy"
                            aria-labelledby="store-policy-payment"
                        >
                            <h3 id="store-policy-payment">Payment</h3>
                            <p>{{ paymentTerms || 'Shown at checkout.' }}</p>
                        </section>

                        <section
                            class="store-policy"
                            aria-labelledby="store-policy-returns"
                        >
                            <h3 id="store-policy-returns">Returns</h3>
                            <ul
                                v-if="returnRules.length"
                                class="store-policy-list is-rules"
                                aria-label="BuyTheWay return rules"
                            >
                                <li
                                    v-for="rule in returnRules"
                                    :key="rule"
                                >
                                    {{ rule }}
                                </li>
                            </ul>
                            <template v-if="store?.returnPolicy">
                                <h4 class="store-policy-sub">{{ identity?.name || 'This store' }}&rsquo;s policy</h4>
                                <p class="store-policy-text">{{ store.returnPolicy }}</p>
                                <p class="store-policy-aside">The BuyTheWay rules above always apply, whatever a store&rsquo;s policy says.</p>
                            </template>
                            <p
                                v-else
                                class="store-policy-aside"
                            >
                                This store hasn&rsquo;t published its own return policy.
                            </p>
                        </section>
                    </div>
                </section>
                </Transition>

            </template>

        </main>

        <!-- Mobile filter drawer: edits a pending copy, committed on "Show results" -->
        <div
            class="drawer-backdrop cat-drawer-backdrop"
            :class="{ 'is-open': drawerOpen }"
            aria-hidden="true"
            @click="closeDrawer"
        ></div>

        <div
            id="store-filter-drawer"
            class="cat-drawer"
            :class="{ 'is-open': drawerOpen }"
            role="dialog"
            aria-modal="true"
            aria-labelledby="store-filter-drawer-title"
            :inert="!drawerOpen || undefined"
        >
            <div class="cat-drawer-head">
                <h2 id="store-filter-drawer-title">Filter products</h2>
                <button
                    ref="drawerClose"
                    type="button"
                    class="icon-btn"
                    aria-label="Close filters without applying"
                    @click="closeDrawer"
                >
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                </button>
            </div>

            <div class="cat-drawer-body">
                <template v-if="drawerOpen">
                    <FilterGroup
                        v-for="group in filterGroups"
                        :key="group.key"
                        :group="group"
                        :model-value="groupValue(pending, group)"
                        collapsible
                        id-prefix="store-drawer"
                        @update:model-value="setGroupValue(pending, group, $event)"
                    />
                </template>
            </div>

            <div class="cat-drawer-foot">
                <button
                    type="button"
                    class="btn btn-ghost"
                    @click="resetPending"
                >
                    Reset
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
                    @click="applyPending"
                >
                    Show results
                </button>
            </div>
        </div>

        <Footer
            @browse-all="emit('browse-all')"
            @browse-categories="emit('browse-categories')"
            @cart-click="emit('open-cart')"
        />

        <Teleport to="body">
            <div v-if="storeReportOpen" class="store-report-backdrop" @click.self="!storeReportBusy && (storeReportOpen = false)">
                <form class="store-report-dialog" role="dialog" aria-modal="true" aria-labelledby="store-report-title" @submit.prevent="submitStoreReport">
                    <header>
                        <h2 id="store-report-title">Report {{ identity?.name || 'store' }}</h2>
                        <button type="button" aria-label="Close report form" :disabled="storeReportBusy" @click="storeReportOpen = false">×</button>
                    </header>
                    <label class="store-report-field">
                        <span>Reason</span>
                        <select v-model="storeReportReason" required>
                            <option value="" disabled>Select a reason</option>
                            <option v-for="reason in storeReportReasons" :key="reason.value" :value="reason.value">{{ reason.label }}</option>
                        </select>
                    </label>
                    <label class="store-report-field">
                        <span>Details</span>
                        <textarea v-model="storeReportDetails" required maxlength="2000" rows="4" placeholder="Explain what happened and why this should be reviewed."></textarea>
                    </label>
                    <label class="store-report-anonymous"><input v-model="storeReportAnonymous" type="checkbox"><span>Submit anonymously</span></label>
                    <div class="store-report-field">
                        <span>Evidence <small>(optional, up to 8 images or videos)</small></span>
                        <input ref="storeReportEvidenceInput" class="store-report-file-input" type="file" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime" multiple @change="selectStoreReportFiles">
                        <button type="button" class="store-report-dropzone" :class="{ 'is-active': storeReportDropActive }" @click="storeReportEvidenceInput?.click()" @dragover.prevent="storeReportDropActive = true" @dragleave.prevent="storeReportDropActive = false" @drop.prevent="handleStoreReportDrop">
                            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4m0 0L7 9m5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>
                            <strong>Drop files here or <span>browse</span></strong>
                            <small>Images and videos, up to 50 MB each</small>
                        </button>
                        <ul v-if="storeReportFiles.length" class="store-report-attachments">
                            <li v-for="(file, index) in storeReportFiles" :key="`${file.name}-${file.size}-${file.lastModified}`">
                                <img v-if="file.type.startsWith('image/')" :src="storeReportPreviewUrl(file)" alt="">
                                <span v-else class="store-report-video-thumb"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg></span>
                                <span class="store-report-file-name">{{ file.name }}</span>
                                <button type="button" :aria-label="`Remove ${file.name}`" @click="removeStoreReportFile(index)">×</button>
                            </li>
                        </ul>
                    </div>
                    <p v-if="storeReportSubmitError" class="store-report-error" role="alert">{{ storeReportSubmitError }}</p>
                    <footer>
                        <button type="button" class="store-report-cancel" :disabled="storeReportBusy" @click="storeReportOpen = false">Cancel</button>
                        <button type="submit" class="store-report-submit" :disabled="storeReportBusy || !storeReportReason || !storeReportDetails.trim()">{{ storeReportBusy ? 'Submitting…' : 'Submit report' }}</button>
                    </footer>
                </form>
            </div>
        </Teleport>

    </div>

</template>
