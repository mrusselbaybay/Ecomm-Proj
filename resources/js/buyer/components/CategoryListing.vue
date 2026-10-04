<script setup>
/*
|--------------------------------------------------------------------------
| CategoryListing
|--------------------------------------------------------------------------
|
| The "browse one category" page. One layout for every category; what
| changes per category comes from useCategoryConfig.js (description, image
| crop, filterable attributes, card detail), which mirrors the seller-side
| app/Support/CategoryFieldConfig.php.
|
| `products` arrives pre-filtered to this category from Dashboard.vue, which
| holds the catalog in memory; filtering is client-side, so results update
| instantly and there are no competing requests to race.
|
| Filtering model
|   - Applied state lives in the URL (useCategoryFilterState.js): refresh,
|     share and browser back/forward all reproduce the same results.
|   - Desktop applies changes immediately (typed prices are debounced),
|     in a sidebar when the category has 2+ useful attribute groups,
|     otherwise in a compact toolbar of filter buttons with dropdown panels.
|   - Below 1024px a drawer edits a *pending* copy; "Apply filters" commits
|     it, closing without applying leaves the applied filters untouched.
|   - Option counts are contextual (products matching every *other* active
|     filter), and options that would lead to zero results are disabled.
|
| Not filterable by product decision: Brand, Life Stage, Pack Size, Flavor.
|
*/
import { ref, reactive, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import Header from './Header.vue';
import Footer from './Footer.vue';
import ProductCard from './ProductCard.vue';
import FilterGroup from './FilterGroup.vue';
import { formatPrice } from '../composables/useCategoryMeta';
import {
    categoryConfig,
    facetValuesOf,
    sortFacetValues,
    cardDetailFor
} from '../composables/useCategoryConfig';
import {
    defaultFilterState,
    sanitizeFilterState,
    filterStateFromQuery,
    queryForFilterState,
    categoryFromQuery,
    rememberFilterState,
    rememberedFilterState
} from '../composables/useCategoryFilterState';

const props = defineProps({
    category: {
        type: String,
        required: true
    },
    // Already scoped to `category` by Dashboard.vue.
    products: {
        type: Array,
        default: () => []
    },
    isLoading: {
        type: Boolean,
        default: false
    },
    loadError: {
        type: String,
        default: ''
    }
});

const emit = defineEmits([
    'back',
    'search',
    'select-category',
    'open-cart',
    'account-click',
    'select-product',
    'browse-all',
    'browse-categories',
    'view-orders',
    'retry'
]);

const PER_PAGE = 24;
const PRICE_DEBOUNCE = 450;

const config = computed(() => categoryConfig(props.category));

/*
|--------------------------------------------------------------------------
| Applied State <-> URL
|--------------------------------------------------------------------------
|
| On entry the URL wins when it describes this category (refresh, shared
| link, browser back); otherwise the buyer's last state for the category.
| Every change rewrites the current history entry in place, so filtering
| doesn't flood the back button but the entry always holds the latest
| filters to come back to.
|
*/

const applied = reactive(
    categoryFromQuery(window.location.search) === props.category
        ? filterStateFromQuery(window.location.search, props.category)
        : rememberedFilterState(props.category)
);

function syncUrl() {
    rememberFilterState(props.category, applied);

    const url = `${window.location.pathname}${queryForFilterState(props.category, applied)}`;

    if (url !== `${window.location.pathname}${window.location.search}`) {
        window.history.replaceState(window.history.state, '', url);
    }
}

watch(applied, syncUrl, { deep: true });

function cloneState(state) {
    return sanitizeFilterState(JSON.parse(JSON.stringify(state)), props.category);
}

/*
|--------------------------------------------------------------------------
| Matching
|--------------------------------------------------------------------------
*/

function isOnSale(product) {
    const oldPrice = Number(product.oldPrice);

    return Number.isFinite(oldPrice) && oldPrice > Number(product.price);
}

/**
 * Does the product pass every active filter in `state`, optionally
 * ignoring one group (used for contextual option counts)?
 */
function matches(product, state, exceptGroup = null) {
    const price = Number(product.price) || 0;

    if (exceptGroup !== 'price') {
        if (state.priceMin !== '' && price < Number(state.priceMin)) {
            return false;
        }

        if (state.priceMax !== '' && price > Number(state.priceMax)) {
            return false;
        }
    }

    if (exceptGroup !== 'availability') {
        if (state.inStockOnly && !(product.stock > 0)) {
            return false;
        }

        if (state.onSaleOnly && !isOnSale(product)) {
            return false;
        }
    }

    if (exceptGroup !== 'condition' && state.conditions.length && !state.conditions.includes(product.condition)) {
        return false;
    }

    if (exceptGroup !== 'rating' && state.minRating && !(product.rating >= state.minRating)) {
        return false;
    }

    for (const facet of config.value.facets) {
        if (facet.key === exceptGroup) {
            continue;
        }

        const selected = state.selections[facet.key] || [];

        if (selected.length && !facetValuesOf(product, facet).some(v => selected.includes(v))) {
            return false;
        }
    }

    return true;
}

function sortProducts(list, sortBy) {
    switch (sortBy) {
        case 'price-asc':
            return list.sort((a, b) => (Number(a.price) || 0) - (Number(b.price) || 0));
        case 'price-desc':
            return list.sort((a, b) => (Number(b.price) || 0) - (Number(a.price) || 0));
        case 'rating':
            return list.sort((a, b) => (b.rating ?? -1) - (a.rating ?? -1) || (b.reviewCount || 0) - (a.reviewCount || 0));
        case 'name-asc':
            return list.sort((a, b) => a.name.localeCompare(b.name));
        default:
            return list.sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));
    }
}

const filteredProducts = computed(() =>
    sortProducts(props.products.filter(p => matches(p, applied)), applied.sortBy)
);

/*
|--------------------------------------------------------------------------
| Filter Groups
|--------------------------------------------------------------------------
|
| Which groups exist is decided from the whole category (so groups never
| vanish mid-filter); option counts come from the state being edited.
|
*/

// A group earns its place only if it can actually narrow the list: two or
// more values, or one value that not every product has.
function isUseful(counts) {
    return counts.size > 1
        || (counts.size === 1 && [...counts.values()][0] < props.products.length);
}

function countValues(products, getValues) {
    const counts = new Map();

    for (const product of products) {
        for (const value of getValues(product)) {
            counts.set(value, (counts.get(value) || 0) + 1);
        }
    }

    return counts;
}

const SWATCHES = {
    black: '#1c1a17', white: '#ffffff', gray: '#9a958c', red: '#c0392b', blue: '#2f5d9e',
    green: '#3f7a4f', yellow: '#e2b93b', orange: '#d9772b', purple: '#7a4f9a', pink: '#d9849b',
    brown: '#7a5233', beige: '#e3d5bf', navy: '#22335e'
};

function conditionsOf(product) {
    return product.condition ? [product.condition] : [];
}

const priceBounds = computed(() => {
    const prices = props.products.map(p => Number(p.price) || 0);

    return prices.length
        ? { min: Math.floor(Math.min(...prices)), max: Math.ceil(Math.max(...prices)) }
        : { min: 0, max: 0 };
});

const availableGroups = computed(() => {
    const groups = [];

    for (const facet of config.value.facets) {
        if (isUseful(countValues(props.products, p => facetValuesOf(p, facet)))) {
            groups.push({
                key: facet.key,
                label: facet.label,
                kind: facet.display === 'swatch' ? 'swatch' : facet.display === 'grid' ? 'toggle' : 'checkbox',
                facet,
                isAttribute: true
            });
        }
    }

    if (priceBounds.value.max > priceBounds.value.min) {
        groups.push({ key: 'price', label: 'Price', kind: 'price' });
    }

    const hasOutOfStock = props.products.some(p => !(p.stock > 0));
    const hasSale = props.products.some(isOnSale);

    if (hasOutOfStock || hasSale) {
        groups.push({ key: 'availability', label: 'Availability', kind: 'checkbox', hasOutOfStock, hasSale });
    }

    if (isUseful(countValues(props.products, conditionsOf))) {
        groups.push({ key: 'condition', label: 'Condition', kind: 'checkbox', isAttribute: true });
    }

    if (props.products.some(p => typeof p.rating === 'number')) {
        groups.push({ key: 'rating', label: 'Customer rating', kind: 'radio' });
    }

    return groups;
});

function capitalize(value) {
    return String(value).charAt(0).toUpperCase() + String(value).slice(1);
}

/** Groups with options and contextual counts for a given state. */
function groupsFor(state) {
    return availableGroups.value.map(group => {
        const pool = props.products.filter(p => matches(p, state, group.key));

        if (group.facet) {
            const counts = countValues(pool, p => facetValuesOf(p, group.facet));
            const all = sortFacetValues([...countValues(props.products, p => facetValuesOf(p, group.facet)).keys()], group.facet);
            const selected = state.selections[group.key] || [];

            return {
                ...group,
                options: all.map(value => ({
                    value,
                    label: value,
                    count: counts.get(value) || 0,
                    swatch: SWATCHES[value.toLowerCase()] || null,
                    disabled: !counts.get(value) && !selected.includes(value)
                }))
            };
        }

        if (group.key === 'condition') {
            const counts = countValues(pool, conditionsOf);
            const all = [...countValues(props.products, conditionsOf).keys()];

            return {
                ...group,
                options: all.map(value => ({
                    value,
                    label: capitalize(value),
                    count: counts.get(value) || 0,
                    disabled: !counts.get(value) && !state.conditions.includes(value)
                }))
            };
        }

        if (group.key === 'availability') {
            const options = [];

            if (group.hasOutOfStock) {
                options.push({ value: 'in_stock', label: 'In stock only', count: pool.filter(p => p.stock > 0).length });
            }

            if (group.hasSale) {
                options.push({ value: 'on_sale', label: 'On sale', count: pool.filter(isOnSale).length });
            }

            return { ...group, options };
        }

        if (group.key === 'rating') {
            return {
                ...group,
                options: [
                    { value: 0, label: 'Any rating' },
                    { value: 4, label: '4 stars & up', count: pool.filter(p => p.rating >= 4).length },
                    { value: 3, label: '3 stars & up', count: pool.filter(p => p.rating >= 3).length }
                ]
            };
        }

        return { ...group, bounds: priceBounds.value };
    });
}

function groupValue(state, group) {
    switch (group.key) {
        case 'price':
            return { min: state.priceMin, max: state.priceMax };
        case 'availability':
            return [state.inStockOnly && 'in_stock', state.onSaleOnly && 'on_sale'].filter(Boolean);
        case 'condition':
            return state.conditions;
        case 'rating':
            return state.minRating;
        default:
            return state.selections[group.key] || [];
    }
}

function setGroupValue(state, group, value) {
    switch (group.key) {
        case 'price':
            state.priceMin = value.min;
            state.priceMax = value.max;
            break;
        case 'availability':
            state.inStockOnly = value.includes('in_stock');
            state.onSaleOnly = value.includes('on_sale');
            break;
        case 'condition':
            state.conditions = value;
            break;
        case 'rating':
            state.minRating = Number(value) || 0;
            break;
        default:
            state.selections = { ...state.selections, [group.key]: value };
    }

    // Any filter change starts the results over; sorting is kept.
    state.page = 1;
}

function groupHasValue(state, group) {
    const value = groupValue(state, group);

    if (group.key === 'price') {
        return value.min !== '' || value.max !== '';
    }

    return Array.isArray(value) ? value.length > 0 : Boolean(value);
}

const appliedGroups = computed(() => groupsFor(applied));

// Sidebar only when there's enough to justify the column.
const useSidebar = computed(() => availableGroups.value.filter(g => g.isAttribute).length >= 2);

// Frequently used groups open by default; the rest collapse but keep their
// selection visible in a summary line.
function isDefaultOpen(group, index, state) {
    return index < 3 || groupHasValue(state, group);
}

/*
|--------------------------------------------------------------------------
| Applied Chips
|--------------------------------------------------------------------------
*/

function priceLabel(min, max) {
    if (min !== '' && max !== '') {
        return `${formatPrice(min)} – ${formatPrice(max)}`;
    }

    return min !== '' ? `From ${formatPrice(min)}` : `Up to ${formatPrice(max)}`;
}

const activeChips = computed(() => {
    const chips = [];

    for (const group of appliedGroups.value) {
        if (!groupHasValue(applied, group)) {
            continue;
        }

        if (group.key === 'price') {
            chips.push({
                key: 'price',
                group: 'Price',
                label: priceLabel(applied.priceMin, applied.priceMax),
                remove: () => setGroupValue(applied, group, { min: '', max: '' })
            });
        } else if (group.key === 'rating') {
            chips.push({
                key: 'rating',
                group: 'Rating',
                label: `${applied.minRating} stars & up`,
                remove: () => setGroupValue(applied, group, 0)
            });
        } else {
            const values = groupValue(applied, group);

            for (const option of group.options.filter(o => values.includes(o.value))) {
                chips.push({
                    key: `${group.key}:${option.value}`,
                    group: group.label,
                    label: option.label,
                    remove: () => setGroupValue(applied, group, values.filter(v => v !== option.value))
                });
            }
        }
    }

    return chips;
});

function clearAllFilters() {
    Object.assign(applied, { ...defaultFilterState(), sortBy: applied.sortBy });
}

function removeLastFilter() {
    activeChips.value[activeChips.value.length - 1]?.remove();
}

/*
|--------------------------------------------------------------------------
| "Shop by" pills — the category's main attribute
|--------------------------------------------------------------------------
*/

const primaryGroup = computed(() =>
    appliedGroups.value.find(g => g.key === config.value.primaryFacet && g.options.length > 1) || null
);

function isPrimaryActive(value) {
    const selected = applied.selections[primaryGroup.value.key] || [];

    return value === null ? selected.length === 0 : selected.length === 1 && selected[0] === value;
}

function selectPrimary(value) {
    setGroupValue(applied, primaryGroup.value, value === null || isPrimaryActive(value) ? [] : [value]);
}

/*
|--------------------------------------------------------------------------
| Sort + Pagination
|--------------------------------------------------------------------------
*/

const sortOptions = computed(() => [
    { id: 'newest', label: 'Newest' },
    { id: 'price-asc', label: 'Price: low to high' },
    { id: 'price-desc', label: 'Price: high to low' },
    ...(props.products.some(p => typeof p.rating === 'number') ? [{ id: 'rating', label: 'Top rated' }] : []),
    { id: 'name-asc', label: 'Name: A to Z' }
]);

const totalPages = computed(() => Math.max(1, Math.ceil(filteredProducts.value.length / PER_PAGE)));

// A page from a URL or memory can outlive the products that filled it.
watch([totalPages, () => props.isLoading], ([total, loading]) => {
    if (!loading && applied.page > total) {
        applied.page = total;
    }
}, { immediate: true });

const pagedProducts = computed(() => {
    const start = (applied.page - 1) * PER_PAGE;

    return filteredProducts.value.slice(start, start + PER_PAGE);
});

const rangeStart = computed(() => (filteredProducts.value.length ? (applied.page - 1) * PER_PAGE + 1 : 0));
const rangeEnd = computed(() => Math.min(applied.page * PER_PAGE, filteredProducts.value.length));

const pageNumbers = computed(() => {
    const total = totalPages.value;
    const current = applied.page;

    return [...new Set([1, total, current - 1, current, current + 1])]
        .filter(n => n >= 1 && n <= total)
        .sort((a, b) => a - b);
});

const toolbar = ref(null);

function goToPage(n) {
    if (n < 1 || n > totalPages.value || n === applied.page) {
        return;
    }

    applied.page = n;
    nextTick(() => toolbar.value?.scrollIntoView({ block: 'start' }));
}

/*
|--------------------------------------------------------------------------
| Compact Toolbar Panels (desktop, categories with few filters)
|--------------------------------------------------------------------------
*/

const openPanel = ref(null);
const toolbarRoot = ref(null);

function togglePanel(key) {
    openPanel.value = openPanel.value === key ? null : key;

    if (openPanel.value) {
        nextTick(() => {
            toolbarRoot.value?.querySelector(`#panel-${key} input:not([disabled])`)?.focus();
        });
    }
}

function panelSummary(group) {
    if (!groupHasValue(applied, group)) {
        return '';
    }

    if (group.key === 'price') {
        return priceLabel(applied.priceMin, applied.priceMax);
    }

    if (group.key === 'rating') {
        return `${applied.minRating}+`;
    }

    return String(groupValue(applied, group).length);
}

function handleDocumentClick(event) {
    if (openPanel.value && toolbarRoot.value && !toolbarRoot.value.contains(event.target)) {
        openPanel.value = null;
    }
}

/*
|--------------------------------------------------------------------------
| Mobile Drawer (pending state, committed on Apply)
|--------------------------------------------------------------------------
*/

const drawerOpen = ref(false);
const pending = reactive(defaultFilterState());
const drawerClose = ref(null);
const filterButton = ref(null);

const pendingGroups = computed(() => (drawerOpen.value ? groupsFor(pending) : []));

const pendingCount = computed(() =>
    (drawerOpen.value ? props.products.filter(p => matches(p, pending)).length : 0)
);

function openDrawer() {
    Object.assign(pending, cloneState(applied));
    drawerOpen.value = true;
    document.body.style.overflow = 'hidden';
    nextTick(() => drawerClose.value?.focus());
}

// Closing without Apply discards the pending selections.
function closeDrawer() {
    if (!drawerOpen.value) {
        return;
    }

    drawerOpen.value = false;
    document.body.style.overflow = '';
    nextTick(() => filterButton.value?.focus());
}

function resetPending() {
    Object.assign(pending, { ...defaultFilterState(), sortBy: applied.sortBy });
}

function applyPending() {
    Object.assign(applied, { ...cloneState(pending), sortBy: applied.sortBy, page: 1 });
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
        nextTick(() => toolbarRoot.value?.querySelector(`[aria-controls="panel-${key}"]`)?.focus());
    }
}

onMounted(() => {
    document.addEventListener('keydown', handleKeydown);
    document.addEventListener('click', handleDocumentClick);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleKeydown);
    document.removeEventListener('click', handleDocumentClick);
    document.body.style.overflow = '';
});

/*
|--------------------------------------------------------------------------
| Header Relay
|--------------------------------------------------------------------------
*/

function handleHeaderSearch(query) {
    emit('search', query);
}

function handleHeaderSelectCategory(category) {
    emit('select-category', category);
}

const skeletons = Array.from({ length: 8 });
</script>

<template>

    <div class="buyer-page">

        <Header
            :active-category="category"
            @select-category="handleHeaderSelectCategory"
            @cart-click="emit('open-cart')"
            @account-click="emit('account-click')"
            @logo-click="emit('back')"
            @search="handleHeaderSearch"
        />

        <main
            id="main-content"
            class="buyer-main cat-page"
            tabindex="-1"
        >

            <!-- Page header -->
            <header class="cat-head">
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
                        <li aria-current="page">{{ category }}</li>
                    </ol>
                </nav>

                <h1 class="cat-title">{{ category }}</h1>

                <p
                    v-if="config.description"
                    class="cat-desc"
                >
                    {{ config.description }}
                </p>

                <div
                    v-if="primaryGroup && !isLoading"
                    class="cat-pills"
                    role="group"
                    :aria-label="`Shop by ${primaryGroup.label.toLowerCase()}`"
                >
                    <button
                        type="button"
                        class="cat-pill"
                        :class="{ 'is-active': isPrimaryActive(null) }"
                        :aria-pressed="isPrimaryActive(null)"
                        @click="selectPrimary(null)"
                    >
                        All
                    </button>
                    <button
                        v-for="option in primaryGroup.options"
                        :key="option.value"
                        type="button"
                        class="cat-pill"
                        :class="{ 'is-active': isPrimaryActive(option.value) }"
                        :aria-pressed="isPrimaryActive(option.value)"
                        @click="selectPrimary(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </header>

            <div class="cat-body">

                <!-- Toolbar -->
                <div
                    v-if="isLoading || loadError || products.length > 0"
                    ref="toolbar"
                    class="cat-toolbar"
                >
                    <div
                        ref="toolbarRoot"
                        class="cat-toolbar-main"
                    >
                        <p
                            class="cat-count"
                            role="status"
                            aria-live="polite"
                        >
                            <template v-if="isLoading">Loading products&hellip;</template>
                            <template v-else-if="filteredProducts.length > PER_PAGE">
                                {{ rangeStart }}&ndash;{{ rangeEnd }} of {{ filteredProducts.length }} products
                            </template>
                            <template v-else>
                                {{ filteredProducts.length }} {{ filteredProducts.length === 1 ? 'product' : 'products' }}
                            </template>
                        </p>

                        <!-- Compact desktop filters (categories with few filters) -->
                        <div
                            v-if="!useSidebar && !isLoading && products.length > 0 && appliedGroups.length"
                            class="cat-quick"
                        >
                            <div
                                v-for="group in appliedGroups"
                                :key="group.key"
                                class="cat-quick-item"
                            >
                                <button
                                    type="button"
                                    class="cat-quick-btn"
                                    :class="{ 'is-active': groupHasValue(applied, group), 'is-open': openPanel === group.key }"
                                    :aria-expanded="openPanel === group.key"
                                    :aria-controls="`panel-${group.key}`"
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
                                    :id="`panel-${group.key}`"
                                    class="cat-quick-panel"
                                    role="region"
                                    :aria-label="`${group.label} filter`"
                                >
                                    <FilterGroup
                                        :group="group"
                                        :model-value="groupValue(applied, group)"
                                        :debounce-ms="PRICE_DEBOUNCE"
                                        id-prefix="quick"
                                        @update:model-value="setGroupValue(applied, group, $event)"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="cat-toolbar-actions">
                        <button
                            v-if="appliedGroups.length && products.length > 0"
                            ref="filterButton"
                            type="button"
                            class="cat-filter-btn"
                            aria-controls="category-drawer"
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

                        <label
                            v-if="products.length > 1"
                            class="select-field"
                        >
                            <span class="select-field-label">Sort by</span>
                            <select v-model="applied.sortBy">
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

                <!-- Applied filters -->
                <div
                    v-if="activeChips.length"
                    class="chip-row cat-chips"
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
                        {{ chip.key === 'price' ? `Price: ${chip.label}` : chip.label }}
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

                <div
                    class="cat-layout"
                    :class="{ 'has-sidebar': useSidebar }"
                >

                    <!-- Desktop sidebar -->
                    <aside
                        v-if="useSidebar && products.length > 0 && !isLoading"
                        class="cat-sidebar"
                        aria-label="Filters"
                    >
                        <FilterGroup
                            v-for="(group, index) in appliedGroups"
                            :key="group.key"
                            :group="group"
                            :model-value="groupValue(applied, group)"
                            :default-open="isDefaultOpen(group, index, applied)"
                            :debounce-ms="PRICE_DEBOUNCE"
                            collapsible
                            id-prefix="side"
                            @update:model-value="setGroupValue(applied, group, $event)"
                        />
                    </aside>

                    <div class="cat-results">

                        <ul
                            v-if="isLoading"
                            class="product-grid listing-grid"
                            :class="`is-${config.imageRatio}`"
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
                            <h2>We couldn&rsquo;t load {{ category }}</h2>
                            <p>{{ loadError }}</p>
                            <button
                                type="button"
                                class="btn btn-primary"
                                @click="emit('retry')"
                            >
                                Try again
                            </button>
                        </div>

                        <div
                            v-else-if="products.length === 0"
                            class="state-block"
                        >
                            <h2>Nothing in {{ category }} yet</h2>
                            <p>Sellers in this category haven&rsquo;t listed anything yet. Try another category in the meantime.</p>
                            <button
                                type="button"
                                class="btn btn-secondary"
                                @click="emit('browse-all')"
                            >
                                Browse all products
                            </button>
                        </div>

                        <div
                            v-else-if="filteredProducts.length === 0"
                            class="state-block cat-no-results"
                        >
                            <h2>No products match these filters</h2>
                            <p>
                                {{ activeChips.length === 1 ? 'Remove the filter below' : 'Remove one of the filters below' }}
                                to see more of {{ category }}.
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
                                    {{ chip.key === 'price' ? `Price: ${chip.label}` : chip.label }}
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                                </button>
                            </div>
                            <div class="cat-no-results-actions">
                                <button
                                    v-if="activeChips.length > 1"
                                    type="button"
                                    class="btn btn-secondary"
                                    @click="removeLastFilter"
                                >
                                    Undo last filter
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    @click="clearAllFilters"
                                >
                                    Clear all filters
                                </button>
                            </div>
                        </div>

                        <template v-else>
                            <ul
                                class="product-grid listing-grid"
                                :class="`is-${config.imageRatio}`"
                            >
                                <li
                                    v-for="product in pagedProducts"
                                    :key="product.id"
                                >
                                    <ProductCard
                                        :product="product"
                                        :detail="cardDetailFor(product, config)"
                                        @view="emit('select-product', $event)"
                                    />
                                </li>
                            </ul>

                            <nav
                                v-if="totalPages > 1"
                                class="cat-pagination"
                                aria-label="Pages"
                            >
                                <button
                                    type="button"
                                    class="icon-btn is-outline"
                                    aria-label="Previous page"
                                    :disabled="applied.page === 1"
                                    @click="goToPage(applied.page - 1)"
                                >
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
                                </button>

                                <template
                                    v-for="(n, index) in pageNumbers"
                                    :key="n"
                                >
                                    <span
                                        v-if="index > 0 && n - pageNumbers[index - 1] > 1"
                                        class="page-gap"
                                        aria-hidden="true"
                                    >&hellip;</span>
                                    <button
                                        type="button"
                                        class="page-btn"
                                        :class="{ 'is-current': n === applied.page }"
                                        :aria-current="n === applied.page ? 'page' : undefined"
                                        :aria-label="`Page ${n}`"
                                        @click="goToPage(n)"
                                    >
                                        {{ n }}
                                    </button>
                                </template>

                                <button
                                    type="button"
                                    class="icon-btn is-outline"
                                    aria-label="Next page"
                                    :disabled="applied.page === totalPages"
                                    @click="goToPage(applied.page + 1)"
                                >
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
                                </button>
                            </nav>
                        </template>

                    </div>
                </div>
            </div>

        </main>

        <!-- Mobile filter drawer: edits a pending copy, committed by Apply -->
        <div
            class="drawer-backdrop cat-drawer-backdrop"
            :class="{ 'is-open': drawerOpen }"
            aria-hidden="true"
            @click="closeDrawer"
        ></div>

        <div
            id="category-drawer"
            class="cat-drawer"
            :class="{ 'is-open': drawerOpen }"
            role="dialog"
            aria-modal="true"
            aria-labelledby="category-drawer-title"
            :inert="!drawerOpen || undefined"
        >
            <div class="cat-drawer-head">
                <h2 id="category-drawer-title">Filter {{ category }}</h2>
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
                <FilterGroup
                    v-for="(group, index) in pendingGroups"
                    :key="group.key"
                    :group="group"
                    :model-value="groupValue(pending, group)"
                    :default-open="isDefaultOpen(group, index, pending)"
                    collapsible
                    id-prefix="drawer"
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
                    class="btn btn-primary cat-drawer-apply"
                    @click="applyPending"
                >
                    Apply filters
                    <span class="cat-drawer-count">{{ pendingCount }} {{ pendingCount === 1 ? 'product' : 'products' }}</span>
                </button>
            </div>
        </div>

        <Footer
            @browse-all="emit('browse-all')"
            @browse-categories="emit('browse-categories')"
            @cart-click="emit('open-cart')"
        />

    </div>

</template>
