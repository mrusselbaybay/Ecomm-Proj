<script setup>
/*
|--------------------------------------------------------------------------
| CategoryListing
|--------------------------------------------------------------------------
|
| The dedicated "browse a single category" page — what clicking a category
| card/tab anywhere in the buyer app now navigates to, instead of just
| filtering the homepage's inline grid. Adapted from a pasted reference
| design ("ShopVerse"); ported onto NEXMART's own data, components
| (Header/Footer/ProductCard), and #0d9488 brand teal, which the reference
| already happened to share.
|
| `products` arrives pre-filtered to this category from Dashboard.vue
| (Dashboard already holds the full catalog in memory — see
| useBuyerProducts.js — so this component does no fetching of its own and
| can't accidentally clobber that shared list). Dashboard also gives this
| component a `:key="category"` where it's mounted, so every field below
| naturally resets when the category changes rather than needing its own
| prop watcher.
|
| Filters/sort are all real, computed from whatever's actually on these
| products (brand, condition, stock, price) — nothing here is fabricated.
| Two things the original reference had are deliberately left out:
|   - Customer Rating filter: there's no reviews aggregation wired into
|     the product catalog endpoint yet (see ProductController::transform),
|     so a rating filter would have nothing real to filter by.
|   - "Best Selling" sort: same reason — no sales-aggregation endpoint.
|     (Dashboard's homepage "Best Sellers" shelf is an explicitly-labeled
|     stock-based proxy; a *sort* option claiming to be "best selling"
|     buyers might actually rely on is a different bar, so it's left out
|     rather than reusing that same proxy here.)
| Both are real gaps, not hidden ones — worth wiring up if/when reviews
| and order aggregation exist.
|
*/
import { ref, computed } from 'vue';
import Header from './Header.vue';
import Footer from './Footer.vue';
import ProductCard from './ProductCard.vue';
import { useBuyer } from '../composables/useBuyer';
import { metaFor, formatPrice } from '../composables/useCategoryMeta';

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
    'browse-categories'
]);

const { addToCart } = useBuyer();

const PER_PAGE = 12;

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

const priceMin = ref('');
const priceMax = ref('');
const selectedBrands = ref([]);
const selectedConditions = ref([]);
const inStockOnly = ref(false);

const sortBy = ref('newest');
const viewMode = ref('grid');
const page = ref(1);

const sectionsOpen = ref({
    price: true,
    brand: true,
    availability: true,
    condition: true
});

function toggleSection(key) {
    sectionsOpen.value[key] = !sectionsOpen.value[key];
}

const priceBounds = computed(() => {
    if (props.products.length === 0) {
        return { min: 0, max: 0 };
    }

    const prices = props.products.map(p => Number(p.price) || 0);

    return {
        min: Math.min(...prices),
        max: Math.max(...prices)
    };
});

// {label, count}[] built from whatever brand/condition values these
// products actually carry — never a hardcoded list.
function facetOf(field) {
    const counts = new Map();

    for (const product of props.products) {
        const value = product[field];

        if (!value) {
            continue;
        }

        counts.set(value, (counts.get(value) || 0) + 1);
    }

    return [...counts.entries()]
        .map(([value, count]) => ({ value, count }))
        .sort((a, b) => a.value.localeCompare(b.value));
}

const availableBrands = computed(() => facetOf('brand'));
const availableConditions = computed(() => facetOf('condition'));

const hasActiveFilters = computed(() =>
    priceMin.value !== '' ||
    priceMax.value !== '' ||
    selectedBrands.value.length > 0 ||
    selectedConditions.value.length > 0 ||
    inStockOnly.value
);

function clearAllFilters() {
    priceMin.value = '';
    priceMax.value = '';
    selectedBrands.value = [];
    selectedConditions.value = [];
    inStockOnly.value = false;
    page.value = 1;
}

/*
|--------------------------------------------------------------------------
| Filter + Sort + Paginate
|--------------------------------------------------------------------------
*/

const filteredProducts = computed(() => {
    const min = priceMin.value !== '' ? Number(priceMin.value) : null;
    const max = priceMax.value !== '' ? Number(priceMax.value) : null;

    const list = props.products.filter(product => {
        const price = Number(product.price) || 0;

        if (min !== null && price < min) return false;
        if (max !== null && price > max) return false;
        if (selectedBrands.value.length > 0 && !selectedBrands.value.includes(product.brand)) return false;
        if (selectedConditions.value.length > 0 && !selectedConditions.value.includes(product.condition)) return false;
        if (inStockOnly.value && !(product.stock > 0)) return false;

        return true;
    });

    const sorted = [...list];

    if (sortBy.value === 'price-asc') {
        sorted.sort((a, b) => (Number(a.price) || 0) - (Number(b.price) || 0));
    } else if (sortBy.value === 'price-desc') {
        sorted.sort((a, b) => (Number(b.price) || 0) - (Number(a.price) || 0));
    } else if (sortBy.value === 'name-asc') {
        sorted.sort((a, b) => a.name.localeCompare(b.name));
    } else {
        sorted.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
    }

    return sorted;
});

const totalPages = computed(() =>
    Math.max(1, Math.ceil(filteredProducts.value.length / PER_PAGE))
);

const pagedProducts = computed(() => {
    const start = (page.value - 1) * PER_PAGE;

    return filteredProducts.value.slice(start, start + PER_PAGE);
});

const rangeStart = computed(() =>
    filteredProducts.value.length === 0 ? 0 : (page.value - 1) * PER_PAGE + 1
);

const rangeEnd = computed(() =>
    Math.min(page.value * PER_PAGE, filteredProducts.value.length)
);

// A small windowed page list (1 … current-1 current current+1 … last)
// rather than a button per page, so this stays usable past a handful of pages.
const pageNumbers = computed(() => {
    const total = totalPages.value;
    const current = page.value;

    const nums = new Set([1, total, current - 1, current, current + 1]);

    return [...nums]
        .filter(n => n >= 1 && n <= total)
        .sort((a, b) => a - b);
});

function goToPage(n) {
    if (n < 1 || n > totalPages.value || n === page.value) {
        return;
    }

    page.value = n;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Any filter/sort change invalidates the current page number.
function resetPage() {
    page.value = 1;
}

/*
|--------------------------------------------------------------------------
| Product Actions
|--------------------------------------------------------------------------
*/

function handleView(product) {
    emit('select-product', product);
}

function handleAddToCart(product) {
    // Same guard as ProductCard's quick-add: a variant product can't be
    // added blind, so send the buyer to pick options first.
    if (product.hasVariants) {
        emit('select-product', product);
        return;
    }

    addToCart(product, null, 1);
}

/*
|--------------------------------------------------------------------------
| Header Relay
|--------------------------------------------------------------------------
|
| Same pattern as Cart.vue / ProductDetails.vue's embedded Header — this
| page has no dashboard state of its own, so these bubble up.
|
*/

function handleHeaderSearch(query) {
    emit('search', query);
}

function handleHeaderSelectCategory(category) {
    emit('select-category', category);
}
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

        <main class="max-w-7xl mx-auto w-full px-4 lg:px-8 py-8">

            <!-- Breadcrumb -->
            <nav class="flex items-center gap-2 text-sm text-slate-500 mb-6">
                <button
                    type="button"
                    class="hover:text-[#0d9488] transition-colors"
                    @click="emit('back')"
                >
                    Home
                </button>
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-slate-300">
                    <path d="m9 18 6-6-6-6" />
                </svg>
                <span class="text-slate-900 font-medium">{{ category }}</span>
            </nav>

            <!-- Loading -->
            <div
                v-if="isLoading"
                class="empty-products"
            >
                <p>Loading products&hellip;</p>
            </div>

            <!-- Load Error -->
            <div
                v-else-if="loadError"
                class="empty-products"
            >
                <p>{{ loadError }}</p>
            </div>

            <div
                v-else
                class="flex flex-col lg:flex-row gap-8"
            >

                <!-- ==================================================== -->
                <!-- SIDEBAR FILTERS -->
                <!-- ==================================================== -->

                <aside
                    v-if="products.length > 0"
                    class="w-full lg:w-72 shrink-0 space-y-6"
                >
                    <div class="bg-white rounded-3xl border border-slate-100 p-6" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">

                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-lg font-bold text-slate-900">Filter Products</h2>
                            <button
                                v-if="hasActiveFilters"
                                type="button"
                                class="text-xs font-bold text-[#0d9488] uppercase tracking-wider"
                                @click="clearAllFilters"
                            >
                                Clear All
                            </button>
                        </div>

                        <!-- Price Range -->
                        <div class="mb-8">
                            <button
                                type="button"
                                class="flex justify-between items-center w-full mb-4"
                                @click="toggleSection('price')"
                            >
                                <h3 class="text-sm font-bold text-slate-900">Price Range</h3>
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-slate-400 transition-transform" :class="{ 'rotate-180': !sectionsOpen.price }">
                                    <path d="m18 15-6-6-6 6" />
                                </svg>
                            </button>
                            <div v-show="sectionsOpen.price">
                                <div class="flex gap-4 mb-2">
                                    <div class="flex-1">
                                        <span class="text-[10px] text-slate-400 font-bold uppercase block mb-1">Min</span>
                                        <input
                                            v-model="priceMin"
                                            type="number"
                                            min="0"
                                            :placeholder="String(priceBounds.min)"
                                            class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:outline-none focus:border-[#0d9488]"
                                            @change="resetPage"
                                        >
                                    </div>
                                    <div class="flex-1">
                                        <span class="text-[10px] text-slate-400 font-bold uppercase block mb-1">Max</span>
                                        <input
                                            v-model="priceMax"
                                            type="number"
                                            min="0"
                                            :placeholder="String(priceBounds.max)"
                                            class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:outline-none focus:border-[#0d9488]"
                                            @change="resetPage"
                                        >
                                    </div>
                                </div>
                                <p class="text-[11px] text-slate-400">
                                    {{ formatPrice(priceBounds.min) }} – {{ formatPrice(priceBounds.max) }} available
                                </p>
                            </div>
                        </div>

                        <!-- Brand -->
                        <div
                            v-if="availableBrands.length > 0"
                            class="mb-8"
                        >
                            <button
                                type="button"
                                class="flex justify-between items-center w-full mb-4"
                                @click="toggleSection('brand')"
                            >
                                <h3 class="text-sm font-bold text-slate-900">Brand</h3>
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-slate-400 transition-transform" :class="{ 'rotate-180': !sectionsOpen.brand }">
                                    <path d="m18 15-6-6-6 6" />
                                </svg>
                            </button>
                            <div
                                v-show="sectionsOpen.brand"
                                class="space-y-3"
                            >
                                <label
                                    v-for="brand in availableBrands"
                                    :key="brand.value"
                                    class="flex items-center gap-3 cursor-pointer group"
                                >
                                    <input
                                        v-model="selectedBrands"
                                        type="checkbox"
                                        :value="brand.value"
                                        class="w-4 h-4 rounded border-slate-300 text-[#0d9488] focus:ring-[#0d9488]"
                                        @change="resetPage"
                                    >
                                    <span class="text-sm text-slate-600 group-hover:text-slate-900 transition-colors">{{ brand.value }}</span>
                                    <span class="text-xs text-slate-400 ml-auto">{{ brand.count }}</span>
                                </label>
                            </div>
                        </div>

                        <!-- Availability -->
                        <div class="mb-8">
                            <button
                                type="button"
                                class="flex justify-between items-center w-full mb-4"
                                @click="toggleSection('availability')"
                            >
                                <h3 class="text-sm font-bold text-slate-900">Availability</h3>
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-slate-400 transition-transform" :class="{ 'rotate-180': !sectionsOpen.availability }">
                                    <path d="m18 15-6-6-6 6" />
                                </svg>
                            </button>
                            <label
                                v-show="sectionsOpen.availability"
                                class="flex items-center gap-3 cursor-pointer group"
                            >
                                <input
                                    v-model="inStockOnly"
                                    type="checkbox"
                                    class="w-4 h-4 rounded border-slate-300 text-[#0d9488] focus:ring-[#0d9488]"
                                    @change="resetPage"
                                >
                                <span class="text-sm text-slate-600 group-hover:text-slate-900 transition-colors">In Stock Only</span>
                            </label>
                        </div>

                        <!-- Condition -->
                        <div v-if="availableConditions.length > 0">
                            <button
                                type="button"
                                class="flex justify-between items-center w-full mb-4"
                                @click="toggleSection('condition')"
                            >
                                <h3 class="text-sm font-bold text-slate-900">Condition</h3>
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-slate-400 transition-transform" :class="{ 'rotate-180': !sectionsOpen.condition }">
                                    <path d="m18 15-6-6-6 6" />
                                </svg>
                            </button>
                            <div
                                v-show="sectionsOpen.condition"
                                class="space-y-3"
                            >
                                <label
                                    v-for="condition in availableConditions"
                                    :key="condition.value"
                                    class="flex items-center gap-3 cursor-pointer group"
                                >
                                    <input
                                        v-model="selectedConditions"
                                        type="checkbox"
                                        :value="condition.value"
                                        class="w-4 h-4 rounded border-slate-300 text-[#0d9488] focus:ring-[#0d9488]"
                                        @change="resetPage"
                                    >
                                    <span class="text-sm text-slate-600 group-hover:text-slate-900 transition-colors">{{ condition.value }}</span>
                                    <span class="text-xs text-slate-400 ml-auto">{{ condition.count }}</span>
                                </label>
                            </div>
                        </div>

                    </div>
                </aside>

                <!-- ==================================================== -->
                <!-- PRODUCT GRID AREA -->
                <!-- ==================================================== -->

                <div class="flex-1 min-w-0">

                    <!-- Controls -->
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-8">
                        <div>
                            <h1 class="text-4xl font-bold text-slate-900 tracking-tight mb-1">{{ category }}</h1>
                            <p class="text-sm text-slate-500">
                                <template v-if="filteredProducts.length > 0">
                                    Showing {{ rangeStart }}-{{ rangeEnd }} of {{ filteredProducts.length }} products
                                </template>
                                <template v-else>
                                    0 products
                                </template>
                            </p>
                        </div>

                        <div
                            v-if="products.length > 0"
                            class="flex items-center gap-4"
                        >
                            <div>
                                <label for="category-sort" class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Sort By</label>
                                <div class="relative">
                                    <select
                                        id="category-sort"
                                        v-model="sortBy"
                                        class="pl-4 pr-10 py-2.5 bg-white border border-slate-100 rounded-2xl text-sm font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-[#0d9488]/20 appearance-none min-w-[190px]"
                                        style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                                        @change="resetPage"
                                    >
                                        <option value="newest">Newest Arrivals</option>
                                        <option value="price-asc">Price: Low to High</option>
                                        <option value="price-desc">Price: High to Low</option>
                                        <option value="name-asc">Name: A to Z</option>
                                    </select>
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </div>
                            </div>

                            <div class="flex items-center bg-white rounded-2xl border border-slate-100 p-1 mt-[18px]" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">
                                <button
                                    type="button"
                                    class="w-10 h-10 flex items-center justify-center rounded-xl transition-colors"
                                    :class="viewMode === 'grid' ? 'bg-teal-50 text-[#0d9488]' : 'text-slate-400 hover:text-slate-600'"
                                    title="Grid view"
                                    @click="viewMode = 'grid'"
                                >
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect width="7" height="7" x="3" y="3" rx="1" />
                                        <rect width="7" height="7" x="14" y="3" rx="1" />
                                        <rect width="7" height="7" x="14" y="14" rx="1" />
                                        <rect width="7" height="7" x="3" y="14" rx="1" />
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    class="w-10 h-10 flex items-center justify-center rounded-xl transition-colors"
                                    :class="viewMode === 'list' ? 'bg-teal-50 text-[#0d9488]' : 'text-slate-400 hover:text-slate-600'"
                                    title="List view"
                                    @click="viewMode = 'list'"
                                >
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 5h.01" /><path d="M3 12h.01" /><path d="M3 19h.01" />
                                        <path d="M8 5h13" /><path d="M8 12h13" /><path d="M8 19h13" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- No products in this category at all -->
                    <div
                        v-if="products.length === 0"
                        class="empty-products"
                    >
                        <span class="empty-products-icon" aria-hidden="true">🔍</span>
                        <p>No products in this category yet.</p>
                        <button
                            type="button"
                            class="clear-filters-button"
                            @click="emit('back')"
                        >
                            Back to Home
                        </button>
                    </div>

                    <!-- Filters matched nothing -->
                    <div
                        v-else-if="filteredProducts.length === 0"
                        class="empty-products"
                    >
                        <span class="empty-products-icon" aria-hidden="true">🔍</span>
                        <p>No products match your filters.</p>
                        <button
                            type="button"
                            class="clear-filters-button"
                            @click="clearAllFilters"
                        >
                            Clear Filters
                        </button>
                    </div>

                    <!-- Grid View -->
                    <div
                        v-else-if="viewMode === 'grid'"
                        class="product-grid"
                    >
                        <ProductCard
                            v-for="product in pagedProducts"
                            :key="product.id"
                            :product="product"
                            @view="handleView"
                        />
                    </div>

                    <!-- List View -->
                    <div
                        v-else
                        class="flex flex-col divide-y divide-slate-100 bg-white rounded-3xl border border-slate-100 overflow-hidden"
                        style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                    >
                        <div
                            v-for="product in pagedProducts"
                            :key="product.id"
                            class="flex flex-col sm:flex-row sm:items-center gap-4 p-5"
                        >
                            <button
                                type="button"
                                class="w-full sm:w-24 h-24 rounded-2xl overflow-hidden shrink-0 bg-slate-100 flex items-center justify-center"
                                @click="handleView(product)"
                            >
                                <img
                                    v-if="product.images && product.images[0]"
                                    :src="product.images[0]"
                                    :alt="product.name"
                                    class="w-full h-full object-cover"
                                >
                                <span
                                    v-else
                                    class="w-10 h-10 text-slate-400"
                                    v-html="metaFor(product.category).icon"
                                ></span>
                            </button>

                            <div class="flex-1 min-w-0">
                                <button
                                    type="button"
                                    class="text-left"
                                    @click="handleView(product)"
                                >
                                    <h3 class="text-sm font-semibold text-slate-800 hover:text-[#0d9488] transition-colors">{{ product.name }}</h3>
                                </button>
                                <p class="text-xs text-slate-400 mt-1">
                                    <template v-if="product.brand">{{ product.brand }} · </template>
                                    <span :class="product.stock > 0 ? 'text-emerald-600' : 'text-red-500'">
                                        {{ product.stock > 0 ? 'In Stock' : 'Out of Stock' }}
                                    </span>
                                </p>
                            </div>

                            <div class="flex items-center justify-between sm:justify-end gap-6 shrink-0">
                                <div class="flex flex-col sm:items-end">
                                    <span class="text-lg font-bold text-slate-900">{{ formatPrice(product.price) }}</span>
                                    <span
                                        v-if="product.oldPrice"
                                        class="text-[11px] text-slate-400 line-through"
                                    >
                                        {{ formatPrice(product.oldPrice) }}
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    class="w-10 h-10 rounded-xl bg-slate-900 text-white flex items-center justify-center hover:bg-[#0d9488] transition-colors shrink-0"
                                    title="Add to cart"
                                    @click="handleAddToCart(product)"
                                >
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="9" cy="21" r="1" />
                                        <circle cx="20" cy="21" r="1" />
                                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div
                        v-if="totalPages > 1"
                        class="flex items-center justify-center gap-2 mt-12"
                    >
                        <button
                            type="button"
                            class="w-10 h-10 flex items-center justify-center rounded-xl bg-white border border-slate-200 text-slate-400 hover:text-[#0d9488] hover:border-[#0d9488] transition-all disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:text-slate-400 disabled:hover:border-slate-200"
                            :disabled="page === 1"
                            @click="goToPage(page - 1)"
                        >
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m15 18-6-6 6-6" />
                            </svg>
                        </button>

                        <template
                            v-for="(n, idx) in pageNumbers"
                            :key="n"
                        >
                            <span
                                v-if="idx > 0 && n - pageNumbers[idx - 1] > 1"
                                class="text-slate-400 px-2"
                            >&hellip;</span>
                            <button
                                type="button"
                                class="w-10 h-10 flex items-center justify-center rounded-xl font-bold transition-all"
                                :class="n === page ? 'bg-[#0d9488] text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50'"
                                @click="goToPage(n)"
                            >
                                {{ n }}
                            </button>
                        </template>

                        <button
                            type="button"
                            class="w-10 h-10 flex items-center justify-center rounded-xl bg-white border border-slate-200 text-slate-400 hover:text-[#0d9488] hover:border-[#0d9488] transition-all disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:text-slate-400 disabled:hover:border-slate-200"
                            :disabled="page === totalPages"
                            @click="goToPage(page + 1)"
                        >
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m9 18 6-6-6-6" />
                            </svg>
                        </button>
                    </div>

                </div>

            </div>

        </main>

        <Footer
            @browse-all="emit('browse-all')"
            @browse-categories="emit('browse-categories')"
            @cart-click="emit('open-cart')"
        />

    </div>

</template>