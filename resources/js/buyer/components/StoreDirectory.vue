<script setup>
/*
|--------------------------------------------------------------------------
| StoreDirectory
|--------------------------------------------------------------------------
|
| Find a store: browse every store, narrow by line of business, sort, page
| through results. A store-name filter (q) comes from the search results
| page's "Browse in the store directory" link or a shared URL; the header
| search itself opens the combined results page. Backed by GET /api/stores
| (StoreController).
|
| - Browse state lives in the URL (useStoreBrowseState.js), so refresh,
|   shared links and Back / Forward reproduce the same results, and the
|   directory reopens where the buyer left it.
| - Requests go through a latest-request-wins channel, so a slow, older
|   response can never replace newer results.
| - While a new result set loads, the current one stays on screen, dimmed,
|   instead of collapsing into skeletons. Skeletons only show when there is
|   nothing to show yet.
| - The category filter lists every line of business sellers register
|   under (useCategoryMeta's categories), with live counts for the current
|   search: chips on wide screens, a select on phones.
| - A store search with no matches offers to edit it in the header or run
|   the same words as a product search.
|
*/
import { ref, reactive, computed, watch, nextTick, onUnmounted } from 'vue';
import Header from './Header.vue';
import Footer from './Footer.vue';
import StoreCard from './StoreCard.vue';
import PageNav from './PageNav.vue';
import { categories } from '../composables/useCategoryMeta';
import { cachedResponse, createLatestRequest, storesEndpoint } from '../composables/useStores';
import {
    defaultDirectoryState,
    directoryStateFromQuery,
    isDirectoryQuery,
    queryForDirectoryState,
    rememberDirectoryState,
    rememberedDirectoryState
} from '../composables/useStoreBrowseState';

const emit = defineEmits([
    'back',
    'search',
    'select-category',
    'open-cart',
    'account-click',
    'open-store',
    'browse-all',
    'browse-categories'
]);

const PER_PAGE = 12;
const ENTRANCE_MS = 900;

const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/*
|--------------------------------------------------------------------------
| Browse State <-> URL
|--------------------------------------------------------------------------
*/

const state = reactive(
    isDirectoryQuery(window.location.search)
        ? directoryStateFromQuery(window.location.search)
        : rememberedDirectoryState()
);

rememberDirectoryState(state);

function syncUrl() {
    rememberDirectoryState(state);

    const url = `${window.location.pathname}${queryForDirectoryState(state)}`;

    if (url !== `${window.location.pathname}${window.location.search}`) {
        window.history.replaceState(window.history.state, '', url);
    }
}

watch(state, syncUrl, { deep: true });

/*
|--------------------------------------------------------------------------
| Editing the search
|--------------------------------------------------------------------------
|
| There's no search box on this page: "Edit search" puts the cursor in the
| header search (which opens the combined products-and-stores results).
|
*/

function editSearch() {
    const input = document.getElementById('header-search-input');

    input?.focus();
    input?.select();
}

/*
|--------------------------------------------------------------------------
| Results
|--------------------------------------------------------------------------
*/

function endpoint() {
    return storesEndpoint({
        search: state.q,
        category: state.category,
        sort: state.sort,
        page: state.page,
        per_page: PER_PAGE
    });
}

const initial = cachedResponse(endpoint());

const stores = ref(initial?.data || []);
const meta = ref(initial?.meta || null);
const facets = ref(initial?.facets || null);
const hasResults = ref(Boolean(initial));
const isLoading = ref(!initial);
const loadError = ref('');
const animateEntrance = ref(false);

const request = createLatestRequest();
let entranceTimer = null;

function applyResponse(body) {
    stores.value = body.data || [];
    meta.value = body.meta || null;
    facets.value = body.facets || null;
}

async function load() {
    const cached = cachedResponse(endpoint());

    if (cached) {
        applyResponse(cached);
        hasResults.value = true;
    }

    isLoading.value = true;
    loadError.value = '';

    try {
        const result = await request.run(endpoint());

        if (result.stale) {
            return;
        }

        // Only the first set of results rises in; later searches swap in
        // place so the list doesn't re-animate on every keystroke.
        if (!hasResults.value && !prefersReducedMotion) {
            animateEntrance.value = true;
            entranceTimer = setTimeout(() => {
                animateEntrance.value = false;
            }, ENTRANCE_MS);
        }

        applyResponse(result.body);
        hasResults.value = true;
        isLoading.value = false;

        // A page from an old link can outlive the stores that filled it.
        if (meta.value && meta.value.total > 0 && state.page > meta.value.last_page) {
            state.page = meta.value.last_page;
        }
    } catch (err) {
        loadError.value = err?.message || 'Something went wrong while loading stores.';
        isLoading.value = false;
    }
}

watch(() => [state.q, state.category, state.sort, state.page], load, { immediate: true });

onUnmounted(() => {
    request.cancel();
    clearTimeout(entranceTimer);
});

const total = computed(() => meta.value?.total ?? 0);
const totalPages = computed(() => meta.value?.last_page ?? 1);

const isFiltered = computed(() => Boolean(state.q || state.category));

const countLabel = computed(() => {
    if (!hasResults.value) {
        return 'Loading stores…';
    }

    return `${total.value} ${total.value === 1 ? 'store' : 'stores'}`;
});

/*
|--------------------------------------------------------------------------
| Category Filter
|--------------------------------------------------------------------------
|
| Every line of business a seller can register under, in the storefront's
| usual order, with how many stores match the current search. A category
| with none is shown but can't be picked (unless it's the selected one, so
| it can always be cleared).
|
*/

const categoryOptions = computed(() => {
    const counts = new Map((facets.value?.categories || []).map(entry => [entry.name, entry.count]));
    const known = categories.filter(category => category !== 'All');
    const extra = [...counts.keys()].filter(name => name && !known.includes(name));

    return [...known, ...extra].map(name => ({
        name,
        count: counts.get(name) || 0,
        disabled: Boolean(facets.value) && !counts.get(name) && name !== state.category
    }));
});

const allCategoriesCount = computed(() =>
    (facets.value?.categories || []).reduce((sum, entry) => sum + entry.count, 0)
);

function selectCategory(name) {
    Object.assign(state, { category: name, page: 1 });
}

/*
|--------------------------------------------------------------------------
| Sort + Pagination
|--------------------------------------------------------------------------
*/

const sortOptions = computed(() => [
    { id: 'products', label: 'Most products' },
    ...(facets.value?.has_ratings || state.sort === 'rating' ? [{ id: 'rating', label: 'Top rated' }] : []),
    { id: 'newest', label: 'Newest on BuyTheWay' },
    { id: 'name', label: 'Name: A to Z' }
]);

function setSort(sort) {
    Object.assign(state, { sort, page: 1 });
}

const resultsTop = ref(null);

function goToPage(n) {
    state.page = n;
    nextTick(() => resultsTop.value?.scrollIntoView({
        block: 'start',
        behavior: prefersReducedMotion ? 'auto' : 'smooth'
    }));
}

function resetAll() {
    Object.assign(state, { ...defaultDirectoryState(), sort: state.sort });
}

function storeHref(store) {
    return `${window.location.pathname}?store=${encodeURIComponent(store.id)}`;
}

const skeletons = Array.from({ length: 6 });
</script>

<template>

    <div class="buyer-page">

        <Header
            active-view="stores"
            :search-query="state.q"
            @select-category="emit('select-category', $event)"
            @cart-click="emit('open-cart')"
            @account-click="emit('account-click')"
            @logo-click="emit('back')"
            @search="emit('search', $event)"
        />

        <main
            id="main-content"
            class="buyer-main stores-page"
            tabindex="-1"
        >

            <header class="stores-head">
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
                        <li aria-current="page">Stores</li>
                    </ol>
                </nav>

                <h1 class="cat-title">Stores</h1>
                <p class="cat-desc">
                    Shop straight from the independent sellers on BuyTheWay. Search store names from the bar above, or browse by what they sell.
                </p>
            </header>

            <section
                ref="resultsTop"
                class="stores-results"
                aria-labelledby="stores-results-title"
            >
                <h2
                    id="stores-results-title"
                    class="sr-only"
                >
                    Store results
                </h2>

                <!-- Category: chips on wide screens, a select on phones -->
                <div class="stores-filter">
                    <div
                        class="stores-cats"
                        role="group"
                        aria-label="Filter stores by category"
                    >
                        <button
                            type="button"
                            class="stores-cat"
                            :class="{ 'is-active': !state.category }"
                            :aria-pressed="!state.category"
                            @click="selectCategory('')"
                        >
                            All categories
                            <span
                                v-if="facets"
                                class="stores-cat-count"
                            >{{ allCategoriesCount }}</span>
                        </button>
                        <button
                            v-for="option in categoryOptions"
                            :key="option.name"
                            type="button"
                            class="stores-cat"
                            :class="{ 'is-active': state.category === option.name }"
                            :aria-pressed="state.category === option.name"
                            :disabled="option.disabled"
                            @click="selectCategory(state.category === option.name ? '' : option.name)"
                        >
                            {{ option.name }}
                            <span
                                v-if="facets"
                                class="stores-cat-count"
                            >{{ option.count }}</span>
                        </button>
                    </div>

                    <label class="stores-cat-select select-field">
                        <span class="select-field-label">Category</span>
                        <select
                            :value="state.category"
                            @change="selectCategory($event.target.value)"
                        >
                            <option value="">All categories{{ facets ? ` (${allCategoriesCount})` : '' }}</option>
                            <option
                                v-for="option in categoryOptions"
                                :key="option.name"
                                :value="option.name"
                                :disabled="option.disabled"
                            >
                                {{ option.name }}{{ facets ? ` (${option.count})` : '' }}
                            </option>
                        </select>
                    </label>
                </div>

                <div class="cat-toolbar">
                    <p
                        class="cat-count stores-count"
                        role="status"
                        aria-live="polite"
                    >
                        <span>{{ countLabel }}</span>
                        <span
                            v-if="hasResults && state.q"
                            class="stores-count-q"
                        >matching &ldquo;{{ state.q }}&rdquo;</span>
                        <span
                            v-if="hasResults && state.category"
                            class="stores-count-q"
                        >in {{ state.category }}</span>
                        <button
                            v-if="isFiltered"
                            type="button"
                            class="link-btn stores-clear"
                            @click="resetAll"
                        >
                            Clear filters
                        </button>
                    </p>

                    <label
                        v-if="total > 1"
                        class="select-field"
                    >
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

                <ul
                    v-if="!hasResults && !loadError"
                    class="store-grid"
                    aria-hidden="true"
                >
                    <li
                        v-for="(_, index) in skeletons"
                        :key="index"
                        class="store-card is-skeleton"
                    >
                        <span class="skeleton store-logo is-md"></span>
                        <span class="store-card-body">
                            <span class="skeleton is-line"></span>
                            <span class="skeleton is-line is-short"></span>
                        </span>
                    </li>
                </ul>

                <div
                    v-else-if="loadError && !stores.length"
                    class="state-block"
                    role="alert"
                >
                    <h3>We couldn&rsquo;t load stores</h3>
                    <p>{{ loadError }}</p>
                    <button
                        type="button"
                        class="btn btn-primary"
                        @click="load"
                    >
                        Try again
                    </button>
                </div>

                <div
                    v-else-if="total === 0 && !isFiltered"
                    class="state-block"
                >
                    <h3>No stores are open yet</h3>
                    <p>Sellers appear here once their accounts are approved. You can still browse every product listed so far.</p>
                    <button
                        type="button"
                        class="btn btn-secondary"
                        @click="emit('browse-all')"
                    >
                        Browse all products
                    </button>
                </div>

                <div
                    v-else-if="total === 0"
                    class="state-block stores-empty"
                >
                    <h3 v-if="state.q">No stores match &ldquo;{{ state.q }}&rdquo;</h3>
                    <h3 v-else>No stores in {{ state.category }} yet</h3>
                    <p v-if="state.q && state.category">Try another spelling, or search every category.</p>
                    <p v-else-if="state.q">Check the spelling, or try part of the store&rsquo;s name.</p>
                    <p v-else>Try another category, or see every store.</p>
                    <div class="cat-no-results-actions">
                        <button
                            v-if="state.category"
                            type="button"
                            class="btn btn-secondary"
                            @click="selectCategory('')"
                        >
                            {{ state.q ? 'Search all categories' : 'All categories' }}
                        </button>
                        <button
                            v-if="state.q"
                            type="button"
                            class="btn btn-secondary"
                            @click="editSearch"
                        >
                            Edit search
                        </button>
                        <button
                            type="button"
                            class="btn btn-primary"
                            @click="resetAll"
                        >
                            Show all stores
                        </button>
                    </div>
                    <button
                        v-if="state.q"
                        type="button"
                        class="link-btn"
                        @click="emit('search', state.q)"
                    >
                        Search products for &ldquo;{{ state.q }}&rdquo; instead
                    </button>
                </div>

                <template v-else>
                    <p
                        v-if="loadError"
                        class="stores-inline-error"
                        role="alert"
                    >
                        Couldn&rsquo;t refresh these results. {{ loadError }}
                        <button
                            type="button"
                            class="link-btn"
                            @click="load"
                        >
                            Try again
                        </button>
                    </p>

                    <ul
                        class="store-grid"
                        :class="{ 'is-entering': animateEntrance, 'is-refreshing': isLoading }"
                        :aria-busy="isLoading"
                    >
                        <li
                            v-for="(store, index) in stores"
                            :key="store.id"
                            :style="{ '--i': index }"
                        >
                            <StoreCard
                                :store="store"
                                :href="storeHref(store)"
                                @open="emit('open-store', $event)"
                            />
                        </li>
                    </ul>

                    <PageNav
                        :page="state.page"
                        :total-pages="totalPages"
                        label="Store result pages"
                        @change="goToPage"
                    />
                </template>
            </section>

        </main>

        <Footer
            @browse-all="emit('browse-all')"
            @browse-categories="emit('browse-categories')"
            @cart-click="emit('open-cart')"
        />

    </div>

</template>
