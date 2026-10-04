<script setup>
/*
|--------------------------------------------------------------------------
| SearchResults
|--------------------------------------------------------------------------
|
| Results for a header search, over the catalog already in memory (see
| useBuyerProducts.js). Every facet is computed from the matching products
| themselves — category counts, the price range, whether any product has a
| rating at all — so nothing here is invented. Filter state lives in
| useSearchFilters.js so it survives a trip into a product and back.
|
| Desktop: sticky filter sidebar. Mobile: the same panel becomes a drawer
| (dialog) with a "Show N results" footer.
|
*/
import { computed, ref, watch, nextTick, onUnmounted } from 'vue';
import ProductCard from './ProductCard.vue';
import { useSearchFilters, SEARCH_PAGE_SIZE } from '../composables/useSearchFilters';
import { formatPrice } from '../composables/useCategoryMeta';

const props = defineProps({
    query: {
        type: String,
        required: true
    },
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
    'view-product',
    'clear-search',
    'select-category',
    'retry'
]);

const { filters, resetFilters, syncQuery } = useSearchFilters();

syncQuery(props.query);

watch(() => props.query, (query) => syncQuery(query));

/*
|--------------------------------------------------------------------------
| Matching
|--------------------------------------------------------------------------
*/

function relevance(product, term) {
    const name = (product.name || '').toLowerCase();

    if (name === term) {
        return 4;
    }

    if (name.startsWith(term)) {
        return 3;
    }

    if (name.includes(term)) {
        return 2;
    }

    return 1;
}

const matches = computed(() => {
    const term = props.query.trim().toLowerCase();

    if (!term) {
        return [];
    }

    return props.products.filter(product =>
        [product.name, product.category, product.brand, product.seller]
            .some(field => (field || '').toLowerCase().includes(term))
    );
});

const categoryFacet = computed(() => {
    const counts = new Map();

    for (const product of matches.value) {
        if (product.category) {
            counts.set(product.category, (counts.get(product.category) || 0) + 1);
        }
    }

    return [...counts.entries()]
        .map(([value, count]) => ({ value, count }))
        .sort((a, b) => b.count - a.count);
});

const hasAnyRating = computed(() => matches.value.some(p => typeof p.rating === 'number'));
const hasAnySale = computed(() => matches.value.some(isOnSale));

function isOnSale(product) {
    const oldPrice = Number(product.oldPrice);

    return Number.isFinite(oldPrice) && oldPrice > Number(product.price);
}

const filtered = computed(() => {
    const min = filters.priceMin === '' ? null : Number(filters.priceMin);
    const max = filters.priceMax === '' ? null : Number(filters.priceMax);

    return matches.value.filter(product => {
        const price = Number(product.price) || 0;

        if (filters.categories.length && !filters.categories.includes(product.category)) {
            return false;
        }

        if (min !== null && price < min) {
            return false;
        }

        if (max !== null && price > max) {
            return false;
        }

        if (filters.inStockOnly && !(product.stock > 0)) {
            return false;
        }

        if (filters.onSaleOnly && !isOnSale(product)) {
            return false;
        }

        if (filters.minRating && !(product.rating >= filters.minRating)) {
            return false;
        }

        return true;
    });
});

const sortOptions = [
    { id: 'relevance', label: 'Best match' },
    { id: 'newest', label: 'Newest' },
    { id: 'priceAsc', label: 'Price: low to high' },
    { id: 'priceDesc', label: 'Price: high to low' },
    { id: 'rating', label: 'Top rated' }
];

const sorted = computed(() => {
    const term = props.query.trim().toLowerCase();
    const list = [...filtered.value];

    switch (filters.sort) {
        case 'newest':
            return list.sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));
        case 'priceAsc':
            return list.sort((a, b) => a.price - b.price);
        case 'priceDesc':
            return list.sort((a, b) => b.price - a.price);
        case 'rating':
            return list.sort((a, b) =>
                (b.rating ?? -1) - (a.rating ?? -1) || (b.reviewCount || 0) - (a.reviewCount || 0)
            );
        default:
            return list.sort((a, b) => relevance(b, term) - relevance(a, term));
    }
});

const visible = computed(() => sorted.value.slice(0, filters.visibleCount));

function loadMore() {
    filters.visibleCount += SEARCH_PAGE_SIZE;
}

watch(
    () => [filters.categories.length, filters.priceMin, filters.priceMax, filters.inStockOnly, filters.onSaleOnly, filters.minRating, filters.sort],
    () => {
        filters.visibleCount = SEARCH_PAGE_SIZE;
    }
);

/*
|--------------------------------------------------------------------------
| Active Filter Chips
|--------------------------------------------------------------------------
*/

const activeChips = computed(() => {
    const chips = filters.categories.map(category => ({
        key: `cat-${category}`,
        label: category,
        remove: () => {
            filters.categories = filters.categories.filter(c => c !== category);
        }
    }));

    if (filters.priceMin !== '' || filters.priceMax !== '') {
        const label = filters.priceMin !== '' && filters.priceMax !== ''
            ? `${formatPrice(filters.priceMin)} – ${formatPrice(filters.priceMax)}`
            : filters.priceMin !== ''
                ? `From ${formatPrice(filters.priceMin)}`
                : `Up to ${formatPrice(filters.priceMax)}`;

        chips.push({
            key: 'price',
            label,
            remove: () => {
                filters.priceMin = '';
                filters.priceMax = '';
            }
        });
    }

    if (filters.inStockOnly) {
        chips.push({
            key: 'stock',
            label: 'In stock',
            remove: () => {
                filters.inStockOnly = false;
            }
        });
    }

    if (filters.onSaleOnly) {
        chips.push({
            key: 'sale',
            label: 'On sale',
            remove: () => {
                filters.onSaleOnly = false;
            }
        });
    }

    if (filters.minRating) {
        chips.push({
            key: 'rating',
            label: `${filters.minRating}+ stars`,
            remove: () => {
                filters.minRating = 0;
            }
        });
    }

    return chips;
});

/*
|--------------------------------------------------------------------------
| Mobile Filter Drawer
|--------------------------------------------------------------------------
*/

const drawerOpen = ref(false);
const drawerClose = ref(null);
const filterButton = ref(null);

function openDrawer() {
    drawerOpen.value = true;
    document.body.style.overflow = 'hidden';
    nextTick(() => drawerClose.value?.focus());
}

function closeDrawer() {
    drawerOpen.value = false;
    document.body.style.overflow = '';
    nextTick(() => filterButton.value?.focus());
}

function handleDrawerKeydown(event) {
    if (event.key === 'Escape' && drawerOpen.value) {
        closeDrawer();
    }
}

onUnmounted(() => {
    document.body.style.overflow = '';
});

const skeletons = Array.from({ length: 8 });
</script>

<template>

    <section
        class="results"
        aria-labelledby="results-title"
        @keydown="handleDrawerKeydown"
    >

        <div class="results-head">
            <div>
                <p class="eyebrow">Search results</p>
                <h1
                    id="results-title"
                    class="results-title"
                >
                    &ldquo;{{ query }}&rdquo;
                </h1>
                <p
                    class="results-count"
                    role="status"
                    aria-live="polite"
                >
                    <template v-if="isLoading">Searching&hellip;</template>
                    <template v-else>{{ filtered.length }} {{ filtered.length === 1 ? 'product' : 'products' }}</template>
                </p>
            </div>

            <div class="results-toolbar">
                <button
                    ref="filterButton"
                    type="button"
                    class="btn btn-secondary results-filter-btn"
                    aria-controls="results-filters"
                    :aria-expanded="drawerOpen"
                    @click="openDrawer"
                >
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4" /></svg>
                    Filters
                    <span
                        v-if="activeChips.length"
                        class="count-pill"
                    >{{ activeChips.length }}</span>
                </button>

                <label class="select-field">
                    <span class="select-field-label">Sort by</span>
                    <select v-model="filters.sort">
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

        <div
            v-if="activeChips.length"
            class="chip-row"
        >
            <button
                v-for="chip in activeChips"
                :key="chip.key"
                type="button"
                class="chip is-removable"
                :aria-label="`Remove filter: ${chip.label}`"
                @click="chip.remove"
            >
                {{ chip.label }}
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
            </button>
            <button
                type="button"
                class="link-btn"
                @click="resetFilters()"
            >
                Clear all
            </button>
        </div>

        <div class="results-layout">

            <div
                class="drawer-backdrop is-filters"
                :class="{ 'is-open': drawerOpen }"
                aria-hidden="true"
                @click="closeDrawer"
            ></div>

            <aside
                id="results-filters"
                class="filters"
                :class="{ 'is-open': drawerOpen }"
                aria-label="Filters"
            >
                <div class="filters-head">
                    <h2>Filters</h2>
                    <button
                        ref="drawerClose"
                        type="button"
                        class="icon-btn"
                        aria-label="Close filters"
                        @click="closeDrawer"
                    >
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                    </button>
                </div>

                <div class="filters-body">
                    <fieldset
                        v-if="categoryFacet.length > 0"
                        class="filter-group"
                    >
                        <legend>Category</legend>
                        <label
                            v-for="facet in categoryFacet"
                            :key="facet.value"
                            class="check-row"
                        >
                            <input
                                v-model="filters.categories"
                                type="checkbox"
                                :value="facet.value"
                            >
                            <span>{{ facet.value }}</span>
                            <span class="check-row-count">{{ facet.count }}</span>
                        </label>
                    </fieldset>

                    <fieldset class="filter-group">
                        <legend>Price (&#8369;)</legend>
                        <div class="price-inputs">
                            <label>
                                <span class="sr-only">Minimum price</span>
                                <input
                                    v-model="filters.priceMin"
                                    type="number"
                                    inputmode="decimal"
                                    min="0"
                                    placeholder="Min"
                                >
                            </label>
                            <span aria-hidden="true">&ndash;</span>
                            <label>
                                <span class="sr-only">Maximum price</span>
                                <input
                                    v-model="filters.priceMax"
                                    type="number"
                                    inputmode="decimal"
                                    min="0"
                                    placeholder="Max"
                                >
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="filter-group">
                        <legend>Availability</legend>
                        <label class="check-row">
                            <input
                                v-model="filters.inStockOnly"
                                type="checkbox"
                            >
                            <span>In stock only</span>
                        </label>
                        <label
                            v-if="hasAnySale"
                            class="check-row"
                        >
                            <input
                                v-model="filters.onSaleOnly"
                                type="checkbox"
                            >
                            <span>On sale</span>
                        </label>
                    </fieldset>

                    <fieldset
                        v-if="hasAnyRating"
                        class="filter-group"
                    >
                        <legend>Customer rating</legend>
                        <label
                            v-for="option in [0, 4, 3]"
                            :key="option"
                            class="check-row"
                        >
                            <input
                                v-model="filters.minRating"
                                type="radio"
                                name="min-rating"
                                :value="option"
                            >
                            <span>{{ option ? `${option} stars & up` : 'Any rating' }}</span>
                        </label>
                    </fieldset>
                </div>

                <div class="filters-foot">
                    <button
                        type="button"
                        class="btn btn-ghost"
                        @click="resetFilters()"
                    >
                        Reset
                    </button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        @click="closeDrawer"
                    >
                        Show {{ filtered.length }} {{ filtered.length === 1 ? 'result' : 'results' }}
                    </button>
                </div>
            </aside>

            <div class="results-main">

                <ul
                    v-if="isLoading"
                    class="product-grid"
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
                    <h2>We couldn't load products</h2>
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
                    v-else-if="filtered.length === 0"
                    class="state-block"
                >
                    <svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="7" />
                        <path d="m20 20-3.5-3.5M8.5 11h5" />
                    </svg>
                    <template v-if="matches.length > 0">
                        <h2>No products match these filters</h2>
                        <p>{{ matches.length }} {{ matches.length === 1 ? 'product matches' : 'products match' }} &ldquo;{{ query }}&rdquo; — loosen a filter to see them.</p>
                        <button
                            type="button"
                            class="btn btn-primary"
                            @click="resetFilters()"
                        >
                            Clear filters
                        </button>
                    </template>
                    <template v-else>
                        <h2>No results for &ldquo;{{ query }}&rdquo;</h2>
                        <p>Check the spelling, try a more general word, or browse a category instead.</p>
                        <button
                            type="button"
                            class="btn btn-secondary"
                            @click="emit('clear-search')"
                        >
                            Back to the storefront
                        </button>
                    </template>
                </div>

                <template v-else>
                    <ul class="product-grid">
                        <li
                            v-for="product in visible"
                            :key="product.id"
                        >
                            <ProductCard
                                :product="product"
                                @view="emit('view-product', $event)"
                            />
                        </li>
                    </ul>

                    <div
                        v-if="visible.length < sorted.length"
                        class="load-more"
                    >
                        <p>Showing {{ visible.length }} of {{ sorted.length }}</p>
                        <button
                            type="button"
                            class="btn btn-secondary"
                            @click="loadMore"
                        >
                            Show more
                        </button>
                    </div>
                </template>

            </div>

        </div>

    </section>

</template>
