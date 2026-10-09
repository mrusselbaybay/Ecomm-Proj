<script setup>
/*
|--------------------------------------------------------------------------
| SearchResults
|--------------------------------------------------------------------------
|
| Results for a header search. One page, two clearly labelled parts in the
| same column: "Stores matching …" (SearchStoreResults, a few stores and a
| link to all of them) above "Product results for …".
|
| Products are searched on the server across the whole visible catalog
| (GET /api/products, ProductController@index), one page at a time — not
| over the products the storefront happened to load. Matching and "Best
| match" ranking are App\Support\ProductSearch's: every word in the name,
| brand, category, subcategory, description or store name, with partial
| and typo-tolerant name matches on PostgreSQL; exact names first, fuzzy
| matches last, then newest and id, so pages never shuffle. Related
| products (not matches) follow the last page of results.
|
| The left sidebar filters products only (category, subcategory, price,
| rating, availability); stores are never filtered by it. Option counts,
| the price range and whether ratings / sales exist come from the API's
| facets, so nothing is invented. There is deliberately no Brand, Life
| Stage, Pack Size or Flavor filter (product decision).
|
| Query, filters, sort and page live in the URL (useSearchState.js) —
| refresh, shared links and Back / Forward reproduce the same results —
| and a cached response renders immediately when the buyer comes back
| from a product or store, so their scroll position can be restored.
|
| Desktop: sidebar + results. Under 1024px the sidebar becomes a drawer
| (dialog) that edits a pending copy, committed by Apply.
|
*/
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import ProductCard from './ProductCard.vue';
import FilterGroup from './FilterGroup.vue';
import PageNav from './PageNav.vue';
import SearchStoreResults from './SearchStoreResults.vue';
import { categories, formatPrice } from '../composables/useCategoryMeta';
import { loadSubcategories, subcategoriesFor } from '../composables/useCategoryConfig';
import { cachedResponse, createLatestRequest, productsEndpoint, relatedProductsEndpoint } from '../composables/useStores';
import {
    defaultSearchState,
    queryForSearchState,
    rememberSearchFacets,
    rememberSearchState,
    rememberedSearchFacets,
    rememberedSearchState,
    sanitizeSearchState,
    searchFilterParams,
    searchProductParams,
    searchStateFromQuery,
    searchTermFromQuery
} from '../composables/useSearchState';

const props = defineProps({
    query: {
        type: String,
        required: true
    }
});

const emit = defineEmits([
    'view-product',
    'open-store',
    'clear-search'
]);

const PER_PAGE = 24;
const PRICE_DEBOUNCE = 450;
const ENTRANCE_MS = 700;
const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/*
|--------------------------------------------------------------------------
| Browse State <-> URL
|--------------------------------------------------------------------------
*/

const state = reactive(rememberedSearchState(props.query));

rememberSearchState(state);

watch(() => props.query, (query) => {
    Object.assign(state, rememberedSearchState(query));
});

function syncUrl() {
    rememberSearchState(state);

    const url = `${window.location.pathname}${queryForSearchState(state)}`;

    if (url !== `${window.location.pathname}${window.location.search}`) {
        window.history.replaceState(window.history.state, '', url);
    }
}

watch(state, syncUrl, { deep: true });

// Back / Forward between two entries of the same search (the view doesn't
// change, so nothing else would re-read the URL).
function handlePopState() {
    if (searchTermFromQuery(window.location.search) === state.q) {
        Object.assign(state, searchStateFromQuery(window.location.search));
    }
}

/*
|--------------------------------------------------------------------------
| Results
|--------------------------------------------------------------------------
*/

// Sidebar counts only depend on the search and filters, not the page or
// sort, so they're requested once per search + filter combination and
// reused, also after leaving and coming back (useSearchState.js).
function facetKey() {
    return JSON.stringify(searchFilterParams(state));
}

function endpointFor(withFacets) {
    // fields=card: cards don't need options / variants (opening a product
    // fetches the full one).
    return productsEndpoint({ ...searchProductParams(state, PER_PAGE), facets: withFacets, fields: 'card' });
}

// The page's identity, regardless of whether counts are requested with it.
function resultsKey() {
    return endpointFor(false);
}

function cachedPage() {
    return cachedResponse(endpointFor(false)) || cachedResponse(endpointFor(true));
}

const initial = cachedPage();
const products = ref(initial?.data || []);
const meta = ref(initial?.meta || null);
const facets = ref(initial?.facets || rememberedSearchFacets(facetKey()) || null);
const hasResults = ref(Boolean(initial));
const isLoading = ref(!initial);
const loadError = ref('');
const animateEntrance = ref(false);

const request = createLatestRequest();
let entranceTimer = null;

function applyResponse(body, key) {
    if (body.facets) {
        rememberSearchFacets(key, body.facets);
    }

    products.value = body.data || [];
    meta.value = body.meta || null;
    facets.value = body.facets || rememberedSearchFacets(key) || null;
}

async function load() {
    const key = facetKey();
    const cached = cachedPage();

    if (cached) {
        applyResponse(cached, key);
        hasResults.value = true;
    }

    isLoading.value = true;
    loadError.value = '';

    try {
        const result = await request.run(endpointFor(!rememberedSearchFacets(key)));

        if (result.stale) {
            return;
        }

        // Only the first set of results rises in; later pages and filter
        // changes swap in place instead of replaying it across the grid.
        if (!hasResults.value && !prefersReducedMotion) {
            animateEntrance.value = true;
            entranceTimer = setTimeout(() => {
                animateEntrance.value = false;
            }, ENTRANCE_MS);
        }

        applyResponse(result.body, key);
        hasResults.value = true;
        isLoading.value = false;

        // A page from an old link can outlive the results that filled it.
        if (meta.value && meta.value.total > 0 && state.page > meta.value.last_page) {
            state.page = meta.value.last_page;
        }
    } catch (err) {
        loadError.value = err?.message || 'Something went wrong while searching.';
        isLoading.value = false;
    }
}

watch(resultsKey, load, { immediate: true });

const total = computed(() => meta.value?.total ?? 0);
const totalPages = computed(() => meta.value?.last_page ?? 1);
const rangeStart = computed(() => (state.page - 1) * PER_PAGE + 1);
const rangeEnd = computed(() => Math.min(state.page * PER_PAGE, total.value));

// What the Stores section has found ({ status: loading | ready | error, total }).
const storeResults = ref({ status: 'loading', total: 0 });

function hasFilters(s) {
    return Boolean(s.category || s.subcategories.length || s.priceMin !== '' || s.priceMax !== ''
        || s.minRating || s.inStockOnly || s.onSaleOnly);
}

const isFiltered = computed(() => hasFilters(state));

// Nothing at all for these words: no products (before any filter) and no stores.
const noResultsAtAll = computed(() => hasResults.value && !isLoading.value && !loadError.value
    && total.value === 0 && !isFiltered.value
    && storeResults.value.status === 'ready' && storeResults.value.total === 0);

const showSidebar = computed(() => hasResults.value && !noResultsAtAll.value && (total.value > 0 || isFiltered.value));

/*
|--------------------------------------------------------------------------
| Related Products
|--------------------------------------------------------------------------
|
| Recommendations that are NOT matches for the search (GET
| /api/products/related): products from the categories the matches come
| from, or — when nothing matched the words — products with similar
| names. They come after every direct match (only on the last page of
| results, or under a "no results" message), are labelled as such, never
| repeat a direct match, and the section hides when there are none.
|
*/

const RELATED_LIMIT = 8;
const relatedProducts = ref([]);
const relatedBasis = ref(null);
const relatedRequest = createLatestRequest();

function relatedUrl() {
    return relatedProductsEndpoint({ ...searchFilterParams(state), limit: RELATED_LIMIT });
}

// Judged on the results on screen; a sort or page change in flight
// doesn't hide the section or ask for the same recommendations again.
const wantsRelated = computed(() => hasResults.value && !loadError.value
    && (total.value === 0 ? !isFiltered.value : state.page >= totalPages.value));

let relatedLoadedFor = '';

function applyRelated(body) {
    relatedProducts.value = body?.data || [];
    relatedBasis.value = body?.basis || null;
}

async function loadRelated() {
    if (!wantsRelated.value) {
        relatedRequest.cancel();
        relatedLoadedFor = '';
        applyRelated(null);

        return;
    }

    const url = relatedUrl();

    if (url === relatedLoadedFor) {
        return;
    }

    relatedLoadedFor = url;

    const cached = cachedResponse(url);

    applyRelated(cached);

    try {
        const result = await relatedRequest.run(url);

        if (!result.stale) {
            applyRelated(result.body);
        }
    } catch {
        // Recommendations are optional: on failure the section just stays
        // hidden, and the next change tries again.
        relatedLoadedFor = '';

        if (!cached) {
            applyRelated(null);
        }
    }
}

watch(() => [wantsRelated.value, relatedUrl()], loadRelated, { immediate: true });

const relatedNote = computed(() => {
    if (relatedBasis.value?.type === 'similar_name') {
        return `Nothing matched “${state.q}” exactly. These products have similar names.`;
    }

    // Name a subcategory only when the products shown are actually in it;
    // otherwise just the category they share with the results.
    const places = [...new Set((relatedBasis.value?.categories || []).map((entry) => {
        const inSubcategory = entry.subcategory
            && relatedProducts.value.some(product => product.category === entry.category && product.subcategory === entry.subcategory);

        return inSubcategory ? `${entry.category} › ${entry.subcategory}` : entry.category;
    }))];

    return places.length
        ? `Not matches for “${state.q}” — more from ${places.join(', ')}, where your results are.`
        : `Not matches for “${state.q}”.`;
});

const summary = computed(() => {
    if (loadError.value && !hasResults.value) {
        return 'Results couldn’t be loaded';
    }

    if (!hasResults.value || storeResults.value.status === 'loading') {
        return 'Searching…';
    }

    const parts = [];

    if (storeResults.value.total > 0) {
        parts.push(`${storeResults.value.total} ${storeResults.value.total === 1 ? 'store' : 'stores'}`);
    }

    parts.push(`${total.value} ${total.value === 1 ? 'product' : 'products'}`);

    return parts.join(' · ');
});

/*
|--------------------------------------------------------------------------
| Filter Groups (rendered by FilterGroup.vue, as on category pages)
|--------------------------------------------------------------------------
|
| Counts are only shown where the API's facets describe that exact state:
| the sidebar (the applied filters). The drawer edits a pending copy, so
| its options carry no counts rather than misleading ones.
|
*/

const AVAILABILITY = [
    { value: 'in_stock', label: 'In stock' },
    { value: 'on_sale', label: 'On sale' }
];

const RATING_OPTIONS = [
    { value: 0, label: 'Any rating' },
    { value: 4, label: '4 stars & up' },
    { value: 3, label: '3 stars & up' }
];

function groupsFor(s, withCounts) {
    const f = facets.value;
    const categoryCounts = new Map((f?.categories || []).map(entry => [entry.name, entry.count]));
    const known = categories.filter(name => name !== 'All');
    const groups = [];

    groups.push({
        key: 'category',
        label: 'Category',
        kind: 'radio',
        options: [
            {
                value: '',
                label: 'All categories',
                count: withCounts && f ? known.reduce((sum, name) => sum + (categoryCounts.get(name) || 0), 0) : undefined
            },
            ...known
                .filter(name => !f || categoryCounts.get(name) || name === s.category)
                .map(name => ({ value: name, label: name, count: withCounts && f ? categoryCounts.get(name) || 0 : undefined }))
        ]
    });

    const subcategories = s.category ? subcategoriesFor(s.category) : null;

    if (s.category && (subcategories?.length || s.subcategories.length)) {
        const counts = new Map((f?.subcategories || []).map(entry => [entry.name, entry.count]));
        const countable = withCounts && f && s.category === state.category;
        const names = [...(subcategories || []), ...s.subcategories.filter(name => !(subcategories || []).includes(name))];

        groups.push({
            key: 'subcategory',
            label: 'Subcategory',
            kind: 'checkbox',
            options: names.map(name => ({
                value: name,
                label: name,
                count: countable ? counts.get(name) || 0 : undefined,
                disabled: countable && !counts.get(name) && !s.subcategories.includes(name)
            }))
        });
    }

    if (f?.price) {
        groups.push({
            key: 'price',
            label: 'Price',
            kind: 'price',
            bounds: { min: Math.floor(f.price.min), max: Math.ceil(f.price.max) }
        });
    }

    if (f?.has_ratings || s.minRating) {
        groups.push({ key: 'rating', label: 'Rating', kind: 'radio', options: RATING_OPTIONS });
    }

    groups.push({ key: 'availability', label: 'Availability', kind: 'checkbox', options: AVAILABILITY });

    return groups;
}

function groupValue(s, group) {
    switch (group.key) {
        case 'category':
            return s.category;
        case 'subcategory':
            return s.subcategories;
        case 'price':
            return { min: s.priceMin, max: s.priceMax };
        case 'rating':
            return s.minRating;
        default:
            return AVAILABILITY.map(o => o.value).filter(v => (v === 'in_stock' ? s.inStockOnly : s.onSaleOnly));
    }
}

function setGroupValue(s, group, value) {
    switch (group.key) {
        case 'category':
            Object.assign(s, { category: value, subcategories: value === s.category ? s.subcategories : [] });
            break;
        case 'subcategory':
            s.subcategories = value;
            break;
        case 'price':
            Object.assign(s, { priceMin: value.min, priceMax: value.max });
            break;
        case 'rating':
            s.minRating = value;
            break;
        default:
            Object.assign(s, { inStockOnly: value.includes('in_stock'), onSaleOnly: value.includes('on_sale') });
    }

    s.page = 1;
}

const appliedGroups = computed(() => groupsFor(state, true));

function isDefaultOpen(group, s) {
    return ['category', 'subcategory', 'price'].includes(group.key)
        || (group.key === 'rating' && Boolean(s.minRating))
        || (group.key === 'availability' && (s.inStockOnly || s.onSaleOnly));
}

/*
|--------------------------------------------------------------------------
| Applied Filter Chips
|--------------------------------------------------------------------------
*/

function priceLabel(s) {
    if (s.priceMin !== '' && s.priceMax !== '') {
        return `${formatPrice(s.priceMin)} – ${formatPrice(s.priceMax)}`;
    }

    return s.priceMin !== '' ? `From ${formatPrice(s.priceMin)}` : `Up to ${formatPrice(s.priceMax)}`;
}

const activeChips = computed(() => {
    const chips = [];

    if (state.category) {
        chips.push({ key: 'category', group: 'Category', label: state.category, remove: () => setGroupValue(state, { key: 'category' }, '') });
    }

    for (const name of state.subcategories) {
        chips.push({
            key: `sub-${name}`,
            group: 'Subcategory',
            label: name,
            remove: () => setGroupValue(state, { key: 'subcategory' }, state.subcategories.filter(v => v !== name))
        });
    }

    if (state.priceMin !== '' || state.priceMax !== '') {
        chips.push({ key: 'price', group: 'Price', label: `Price: ${priceLabel(state)}`, remove: () => setGroupValue(state, { key: 'price' }, { min: '', max: '' }) });
    }

    if (state.minRating) {
        chips.push({ key: 'rating', group: 'Rating', label: `${state.minRating} stars & up`, remove: () => setGroupValue(state, { key: 'rating' }, 0) });
    }

    if (state.inStockOnly) {
        chips.push({ key: 'stock', group: 'Availability', label: 'In stock', remove: () => Object.assign(state, { inStockOnly: false, page: 1 }) });
    }

    if (state.onSaleOnly) {
        chips.push({ key: 'sale', group: 'Availability', label: 'On sale', remove: () => Object.assign(state, { onSaleOnly: false, page: 1 }) });
    }

    return chips;
});

function clearAllFilters() {
    Object.assign(state, { ...defaultSearchState(state.q), sort: state.sort });
}

/*
|--------------------------------------------------------------------------
| Sort + Pagination
|--------------------------------------------------------------------------
|
| Top rated / Top sales are offered only when some matching product has a
| review / a completed sale (facets), so they never sort a list of zeros.
|
*/

const sortOptions = computed(() => [
    { id: 'relevance', label: 'Best match', title: 'Name matches first, then store name, category and description; newest first within each' },
    { id: 'newest', label: 'Newest' },
    ...(facets.value?.has_sales || state.sort === 'popular' ? [{ id: 'popular', label: 'Top sales', title: 'Units on delivered, non-refunded orders' }] : []),
    ...(facets.value?.has_ratings || state.sort === 'rating' ? [{ id: 'rating', label: 'Top rated', title: 'Average of product reviews' }] : [])
]);

const PRICE_SORTS = [
    { id: 'price-asc', label: 'Price: low to high' },
    { id: 'price-desc', label: 'Price: high to low' }
];

const priceSort = computed(() => (state.sort.startsWith('price-') ? state.sort : ''));

function setSort(sort) {
    if (sort && sort !== state.sort) {
        Object.assign(state, { sort, page: 1 });
    }
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
| Editing the search
|--------------------------------------------------------------------------
*/

function editSearch() {
    const input = document.getElementById('header-search-input');

    input?.focus();
    input?.select();
}

/*
|--------------------------------------------------------------------------
| Mobile / Tablet Filter Drawer
|--------------------------------------------------------------------------
|
| Edits a pending copy of the filters; Apply commits it (and goes back to
| page 1), closing without Apply discards it. Focus moves into the dialog,
| stays there while it is open, and returns to the Filters button.
|
*/

const drawerOpen = ref(false);
const drawer = ref(null);
const drawerClose = ref(null);
const filterButton = ref(null);
const pending = reactive(defaultSearchState(props.query));

const pendingGroups = computed(() => (drawerOpen.value ? groupsFor(pending, false) : []));

function openDrawer() {
    Object.assign(pending, sanitizeSearchState(JSON.parse(JSON.stringify(state))));
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
    Object.assign(pending, { ...defaultSearchState(state.q), sort: state.sort });
}

function applyPending() {
    Object.assign(state, { ...sanitizeSearchState(JSON.parse(JSON.stringify(pending))), q: state.q, sort: state.sort, page: 1 });
    closeDrawer();
}

function handleKeydown(event) {
    if (!drawerOpen.value) {
        return;
    }

    if (event.key === 'Escape') {
        closeDrawer();

        return;
    }

    if (event.key !== 'Tab' || !drawer.value) {
        return;
    }

    const focusable = [...drawer.value.querySelectorAll('button:not([disabled]), input:not([disabled]), select, a[href]')]
        .filter(el => el.offsetParent !== null);
    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first?.focus();
    }
}

onMounted(() => {
    loadSubcategories();
    document.addEventListener('keydown', handleKeydown);
    window.addEventListener('popstate', handlePopState);
});

onUnmounted(() => {
    request.cancel();
    relatedRequest.cancel();
    clearTimeout(entranceTimer);
    document.removeEventListener('keydown', handleKeydown);
    window.removeEventListener('popstate', handlePopState);
    document.body.style.overflow = '';
});

const skeletons = Array.from({ length: 8 });
</script>

<template>

    <section
        class="srch"
        aria-labelledby="srch-title"
    >

        <header class="srch-head">
            <p class="eyebrow">Search results</p>
            <h1
                id="srch-title"
                class="srch-title"
            >
                Results for &ldquo;{{ query }}&rdquo;
            </h1>
            <p
                class="srch-summary"
                role="status"
                aria-live="polite"
            >
                {{ summary }}
            </p>
        </header>

        <div
            class="cat-layout srch-layout"
            :class="{ 'has-sidebar': showSidebar }"
        >

            <!-- Desktop sidebar: product filters only -->
            <aside
                v-if="showSidebar"
                class="cat-sidebar srch-sidebar"
                aria-labelledby="srch-filters-title"
            >
                <div class="srch-sidebar-head">
                    <h2 id="srch-filters-title">Filter products</h2>
                    <p>Applies to product results only.</p>
                </div>

                <FilterGroup
                    v-for="group in appliedGroups"
                    :key="group.key"
                    :group="group"
                    :model-value="groupValue(state, group)"
                    :default-open="isDefaultOpen(group, state)"
                    :debounce-ms="PRICE_DEBOUNCE"
                    collapsible
                    id-prefix="srch-side"
                    @update:model-value="setGroupValue(state, group, $event)"
                />

                <button
                    v-if="isFiltered"
                    type="button"
                    class="link-btn srch-sidebar-clear"
                    @click="clearAllFilters"
                >
                    Clear all filters
                </button>
            </aside>

            <div class="srch-main">

                <SearchStoreResults
                    :query="query"
                    @open-store="emit('open-store', $event)"
                    @state="storeResults = $event"
                />

                <!-- Nothing matches the words at all -->
                <div
                    v-if="noResultsAtAll"
                    class="state-block srch-empty"
                >
                    <svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="7" />
                        <path d="m20 20-3.5-3.5M8.5 11h5" />
                    </svg>
                    <h2>No products or stores match &ldquo;{{ query }}&rdquo;</h2>
                    <p>Check the spelling, use fewer or more general words, or search for a store&rsquo;s name.</p>
                    <div class="cat-no-results-actions">
                        <button
                            type="button"
                            class="btn btn-primary"
                            @click="editSearch"
                        >
                            Edit search
                        </button>
                        <button
                            type="button"
                            class="btn btn-secondary"
                            @click="emit('clear-search')"
                        >
                            Back to the storefront
                        </button>
                    </div>
                </div>

                <section
                    v-else
                    ref="productsTop"
                    class="results-section srch-products"
                    aria-labelledby="srch-products-title"
                >
                    <div class="srch-section-head">
                        <h2
                            id="srch-products-title"
                            class="srch-section-title"
                        >
                            Product results for &ldquo;{{ query }}&rdquo;
                        </h2>
                        <p
                            v-if="hasResults && total > 0"
                            class="srch-count"
                        >
                            <template v-if="total > PER_PAGE">{{ rangeStart }}&ndash;{{ rangeEnd }} of {{ total }} products</template>
                            <template v-else>{{ total }} {{ total === 1 ? 'product' : 'products' }}</template>
                        </p>
                    </div>

                    <!-- Sort + (under 1024px) the Filters button -->
                    <div
                        v-if="hasResults && (total > 0 || isFiltered)"
                        class="srch-toolbar"
                    >
                        <button
                            ref="filterButton"
                            type="button"
                            class="cat-filter-btn"
                            aria-controls="srch-drawer"
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

                        <div
                            class="srch-sort"
                            role="group"
                            aria-label="Sort products"
                        >
                            <span
                                class="srch-sort-label"
                                aria-hidden="true"
                            >Sort by</span>
                            <button
                                v-for="option in sortOptions"
                                :key="option.id"
                                type="button"
                                class="srch-sort-btn"
                                :class="{ 'is-active': state.sort === option.id }"
                                :aria-pressed="state.sort === option.id"
                                :title="option.title"
                                @click="setSort(option.id)"
                            >
                                {{ option.label }}
                            </button>
                            <label class="srch-sort-price">
                                <span class="sr-only">Sort by price</span>
                                <select
                                    :value="priceSort"
                                    :class="{ 'is-active': priceSort }"
                                    @change="setSort($event.target.value)"
                                >
                                    <option
                                        value=""
                                        disabled
                                    >Price</option>
                                    <option
                                        v-for="option in PRICE_SORTS"
                                        :key="option.id"
                                        :value="option.id"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                            </label>
                        </div>

                        <!-- Phones: one compact select -->
                        <label class="select-field srch-sort-compact">
                            <span class="select-field-label">Sort</span>
                            <select
                                :value="state.sort"
                                aria-label="Sort products"
                                @change="setSort($event.target.value)"
                            >
                                <option
                                    v-for="option in [...sortOptions, ...PRICE_SORTS]"
                                    :key="option.id"
                                    :value="option.id"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </label>
                    </div>

                    <!-- Applied filters -->
                    <div
                        v-if="activeChips.length"
                        class="chip-row srch-chips"
                        role="group"
                        aria-label="Applied filters"
                    >
                        <button
                            v-for="chip in activeChips"
                            :key="chip.key"
                            type="button"
                            class="chip is-removable"
                            :aria-label="`Remove filter ${chip.group}: ${chip.label}`"
                            @click="chip.remove()"
                        >
                            {{ chip.label }}
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                        </button>
                        <button
                            type="button"
                            class="link-btn"
                            @click="clearAllFilters"
                        >
                            Clear all
                        </button>
                    </div>

                    <ul
                        v-if="!hasResults && isLoading"
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
                        v-else-if="loadError"
                        class="state-block"
                        role="alert"
                    >
                        <h2>We couldn&rsquo;t load product results</h2>
                        <p>{{ loadError }}</p>
                        <button
                            type="button"
                            class="btn btn-primary"
                            @click="load"
                        >
                            Try again
                        </button>
                    </div>

                    <!-- Filters leave nothing: keep the query, offer a way back -->
                    <div
                        v-else-if="total === 0 && isFiltered"
                        class="state-block cat-no-results"
                    >
                        <h2>No products match these filters</h2>
                        <p>
                            Products match &ldquo;{{ query }}&rdquo;, just not with
                            {{ activeChips.length === 1 ? 'this filter' : 'all of these filters' }}.
                        </p>
                        <div class="chip-row">
                            <button
                                v-for="chip in activeChips"
                                :key="`nr-${chip.key}`"
                                type="button"
                                class="chip is-removable"
                                :aria-label="`Remove filter ${chip.group}: ${chip.label}`"
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
                                @click="clearAllFilters"
                            >
                                Clear all filters
                            </button>
                        </div>
                    </div>

                    <!-- Stores matched, products didn't -->
                    <p
                        v-else-if="total === 0"
                        class="results-section-note srch-none-note"
                    >
                        No products match &ldquo;{{ query }}&rdquo;. Try the matching stores above, or
                        <button
                            type="button"
                            class="link-btn"
                            @click="editSearch"
                        >edit your search</button>.
                    </p>

                    <template v-else>
                        <ul
                            class="product-grid listing-grid"
                            :class="{ 'is-entering': animateEntrance, 'is-refreshing': isLoading }"
                            :aria-busy="isLoading"
                        >
                            <li
                                v-for="(product, index) in products"
                                :key="product.id"
                                :style="{ '--i': index }"
                            >
                                <ProductCard
                                    :product="product"
                                    @view="emit('view-product', $event)"
                                />
                            </li>
                        </ul>

                        <PageNav
                            :page="state.page"
                            :total-pages="totalPages"
                            label="Product result pages"
                            @change="goToPage"
                        />
                    </template>
                </section>

                <!-- Recommendations, clearly apart from the matches -->
                <section
                    v-if="relatedProducts.length"
                    class="results-section srch-related"
                    aria-labelledby="srch-related-title"
                >
                    <div class="srch-section-head">
                        <div>
                            <h2
                                id="srch-related-title"
                                class="srch-section-title"
                            >
                                Related products
                            </h2>
                            <p class="srch-related-note">{{ relatedNote }}</p>
                        </div>
                    </div>

                    <ul class="product-grid listing-grid">
                        <li
                            v-for="product in relatedProducts"
                            :key="product.id"
                        >
                            <ProductCard
                                :product="product"
                                @view="emit('view-product', $event)"
                            />
                        </li>
                    </ul>
                </section>

            </div>
        </div>

        <!-- Filter drawer (under 1024px): edits a pending copy, committed by Apply -->
        <div
            class="drawer-backdrop cat-drawer-backdrop"
            :class="{ 'is-open': drawerOpen }"
            aria-hidden="true"
            @click="closeDrawer"
        ></div>

        <div
            id="srch-drawer"
            ref="drawer"
            class="cat-drawer"
            :class="{ 'is-open': drawerOpen }"
            role="dialog"
            aria-modal="true"
            aria-labelledby="srch-drawer-title"
            :inert="!drawerOpen || undefined"
        >
            <div class="cat-drawer-head">
                <h2 id="srch-drawer-title">Filter products</h2>
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
                <p class="srch-drawer-note">Applies to product results for &ldquo;{{ query }}&rdquo; only.</p>
                <FilterGroup
                    v-for="group in pendingGroups"
                    :key="group.key"
                    :group="group"
                    :model-value="groupValue(pending, group)"
                    :default-open="isDefaultOpen(group, pending)"
                    collapsible
                    id-prefix="srch-drawer"
                    @update:model-value="setGroupValue(pending, group, $event)"
                />
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

    </section>

</template>
