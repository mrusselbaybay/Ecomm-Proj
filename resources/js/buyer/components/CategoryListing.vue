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
| products (brand, condition, stock, price, and now color/size) — nothing
| here is fabricated. Two things the original reference had are
| deliberately left out:
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
| Color/Size are likewise real: every product carries `options` (the same
| {name, values} shape ProductDetails.vue reads to build its variant
| picker — see ProductController::transform()), so these facets are built
| from whichever option names a product actually has, exactly like the
| brand/condition facets below. A product with no "Color"/"Size" option
| simply doesn't contribute to those facets — never invented.
|
| The sidebar's order-tracking widget is the honest version of the
| reference's live map: this app has no courier GPS data (see
| OrderTracking.vue's own note on this), so instead of faking a map it
| surfaces the buyer's most recent in-progress order using the same
| trackingSteps/stepLabels data OrderTracking.vue's real timeline uses,
| with a link into the real order list.
|
*/
import { ref, computed, onMounted } from 'vue';
import Header from './Header.vue';
import Footer from './Footer.vue';
import ProductCard from './ProductCard.vue';
import { useBuyer } from '../composables/useBuyer';
import { metaFor, formatPrice } from '../composables/useCategoryMeta';
import { trackingSteps, stepLabels, isTrackingStepCompleted } from '../composables/useOrderTimeline';

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
    'view-orders'
]);

const { addToCart, orders, isLoadingOrders, loadOrders, ORDER_STATUSES } = useBuyer();

onMounted(() => {
    loadOrders();
});

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
const selectedColors = ref([]);
const selectedSizes = ref([]);
const inStockOnly = ref(false);

const sortBy = ref('newest');
const viewMode = ref('grid');
const page = ref(1);

const sectionsOpen = ref({
    price: true,
    brand: true,
    availability: true,
    condition: true,
    color: true,
    size: true
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

// Color/Size facets, built the same honest way but sourced from each
// product's real variant `options` (see ProductController::transform —
// options is [{name, values: [{value}]}]) rather than a flat field, since
// that's where this data actually lives.
function variantOptionFacet(optionName) {
    const counts = new Map();

    for (const product of props.products) {
        const option = (product.options || []).find(
            o => (o.name || '').toLowerCase() === optionName
        );

        if (!option) {
            continue;
        }

        const seenOnThisProduct = new Set();

        for (const { value } of option.values || []) {
            if (!value || seenOnThisProduct.has(value)) {
                continue;
            }

            seenOnThisProduct.add(value);
            counts.set(value, (counts.get(value) || 0) + 1);
        }
    }

    return [...counts.entries()].map(([value, count]) => ({ value, count }));
}

function productHasOptionValue(product, optionName, selectedValues) {
    if (selectedValues.length === 0) {
        return true;
    }

    const option = (product.options || []).find(
        o => (o.name || '').toLowerCase() === optionName
    );

    if (!option) {
        return false;
    }

    return option.values.some(v => selectedValues.includes(v.value));
}

const availableBrands = computed(() => facetOf('brand'));
const availableConditions = computed(() => facetOf('condition'));

const availableColors = computed(() =>
    variantOptionFacet('color').sort((a, b) => a.value.localeCompare(b.value))
);

// Common apparel scale first, then anything unrecognized falls back to a
// natural alphanumeric sort rather than being dropped.
const SIZE_ORDER = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '2XL', 'XXXL', '3XL'];

const availableSizes = computed(() =>
    variantOptionFacet('size').sort((a, b) => {
        const ai = SIZE_ORDER.indexOf(a.value.toUpperCase());
        const bi = SIZE_ORDER.indexOf(b.value.toUpperCase());

        if (ai !== -1 && bi !== -1) {
            return ai - bi;
        }

        if (ai !== -1) {
            return -1;
        }

        if (bi !== -1) {
            return 1;
        }

        return a.value.localeCompare(b.value, undefined, { numeric: true });
    })
);

// A small curated set of real CSS colors for common apparel color names, so
// the swatch actually reflects the named color instead of guessing. Names
// outside this set still show as a plain labeled chip — never a wrong hue.
const COLOR_SWATCH_MAP = {
    black: '#111111', white: '#ffffff', red: '#ef4444', blue: '#3b82f6',
    navy: '#1e3a8a', green: '#22c55e', brown: '#92400e', gray: '#9ca3af',
    grey: '#9ca3af', beige: '#e7d8c9', yellow: '#eab308', orange: '#f97316',
    pink: '#ec4899', purple: '#a855f7', gold: '#ca8a04', silver: '#c0c0c0',
    tan: '#d2b48c', cream: '#fffdf0', maroon: '#7f1d1d', teal: '#0d9488'
};

function swatchColor(name) {
    return COLOR_SWATCH_MAP[(name || '').toLowerCase().trim()] || null;
}

const hasActiveFilters = computed(() =>
    priceMin.value !== '' ||
    priceMax.value !== '' ||
    selectedBrands.value.length > 0 ||
    selectedConditions.value.length > 0 ||
    selectedColors.value.length > 0 ||
    selectedSizes.value.length > 0 ||
    inStockOnly.value
);

function clearAllFilters() {
    priceMin.value = '';
    priceMax.value = '';
    selectedBrands.value = [];
    selectedConditions.value = [];
    selectedColors.value = [];
    selectedSizes.value = [];
    inStockOnly.value = false;
    page.value = 1;
}

/*
|--------------------------------------------------------------------------
| Active Filter Chips
|--------------------------------------------------------------------------
*/

const activeFilterChips = computed(() => {
    const chips = [];

    if (priceMin.value !== '' || priceMax.value !== '') {
        chips.push({
            type: 'price',
            value: null,
            label: `${formatPrice(sliderMin.value)} – ${formatPrice(sliderMax.value)}`
        });
    }

    for (const value of selectedBrands.value) {
        chips.push({ type: 'brand', value, label: value });
    }

    for (const value of selectedConditions.value) {
        chips.push({ type: 'condition', value, label: value });
    }

    for (const value of selectedColors.value) {
        chips.push({ type: 'color', value, label: value });
    }

    for (const value of selectedSizes.value) {
        chips.push({ type: 'size', value, label: value });
    }

    if (inStockOnly.value) {
        chips.push({ type: 'stock', value: null, label: 'In Stock Only' });
    }

    return chips;
});

function removeChip(chip) {
    if (chip.type === 'price') {
        priceMin.value = '';
        priceMax.value = '';
    } else if (chip.type === 'stock') {
        inStockOnly.value = false;
    } else if (chip.type === 'brand') {
        selectedBrands.value = selectedBrands.value.filter(v => v !== chip.value);
    } else if (chip.type === 'condition') {
        selectedConditions.value = selectedConditions.value.filter(v => v !== chip.value);
    } else if (chip.type === 'color') {
        selectedColors.value = selectedColors.value.filter(v => v !== chip.value);
    } else if (chip.type === 'size') {
        selectedSizes.value = selectedSizes.value.filter(v => v !== chip.value);
    }

    resetPage();
}

/*
|--------------------------------------------------------------------------
| Price Range Slider
|--------------------------------------------------------------------------
|
| Two overlapping <input type="range"> elements sharing one track — the
| standard dual-thumb approach, since there's no native two-handle range
| input. Only the thumbs are interactive (the transparent track underneath
| has pointer-events disabled) so they don't fight each other for clicks.
|
*/

const sliderMin = computed(() =>
    priceMin.value !== '' ? Number(priceMin.value) : priceBounds.value.min
);

const sliderMax = computed(() =>
    priceMax.value !== '' ? Number(priceMax.value) : priceBounds.value.max
);

const minPricePercent = computed(() => {
    const { min, max } = priceBounds.value;

    if (max === min) {
        return 0;
    }

    return ((sliderMin.value - min) / (max - min)) * 100;
});

const maxPricePercent = computed(() => {
    const { min, max } = priceBounds.value;

    if (max === min) {
        return 100;
    }

    return ((sliderMax.value - min) / (max - min)) * 100;
});

function onMinSlide(rawValue) {
    priceMin.value = String(Math.min(Number(rawValue), sliderMax.value));
    resetPage();
}

function onMaxSlide(rawValue) {
    priceMax.value = String(Math.max(Number(rawValue), sliderMin.value));
    resetPage();
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
        if (!productHasOptionValue(product, 'color', selectedColors.value)) return false;
        if (!productHasOptionValue(product, 'size', selectedSizes.value)) return false;
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
| Order Tracking Widget
|--------------------------------------------------------------------------
|
| The honest stand-in for the reference's live map — real order data,
| no invented courier position. Shows whichever of the buyer's orders is
| still in progress (not yet delivered or cancelled) and was placed most
| recently; "view-orders" routes to the real My Orders list, same as every
| other "Track Order" entry point in this app (Header account menu,
| OrderDetails, etc.).
|
*/

const latestActiveOrder = computed(() => {
    const active = orders.value.filter(
        o => o.status !== ORDER_STATUSES.DELIVERED && o.status !== ORDER_STATUSES.CANCELLED
    );

    if (active.length === 0) {
        return null;
    }

    return [...active].sort(
        (a, b) => new Date(b.createdAt) - new Date(a.createdAt)
    )[0];
});

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
                <!-- SIDEBAR: FILTERS + ORDER TRACKING -->
                <!-- ==================================================== -->

                <aside class="w-full lg:w-72 shrink-0 space-y-6">

                    <div
                        v-if="products.length > 0"
                        class="bg-white rounded-3xl border border-slate-100 p-6"
                        style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                    >

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

                                <!-- Dual-thumb slider -->
                                <div class="relative h-1.5 mt-2 mb-4">
                                    <div class="absolute inset-0 top-1/2 -translate-y-1/2 h-1.5 bg-slate-100 rounded-full"></div>
                                    <div
                                        class="absolute top-1/2 -translate-y-1/2 h-1.5 bg-[#0d9488] rounded-full"
                                        :style="{ left: minPricePercent + '%', right: (100 - maxPricePercent) + '%' }"
                                    ></div>
                                    <input
                                        type="range"
                                        :min="priceBounds.min"
                                        :max="priceBounds.max"
                                        :value="sliderMin"
                                        class="absolute inset-0 w-full h-1.5 m-0 appearance-none bg-transparent pointer-events-none
                                               [&::-webkit-slider-thumb]:pointer-events-auto [&::-webkit-slider-thumb]:appearance-none
                                               [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:rounded-full
                                               [&::-webkit-slider-thumb]:bg-white [&::-webkit-slider-thumb]:border-2 [&::-webkit-slider-thumb]:border-[#0d9488]
                                               [&::-webkit-slider-thumb]:shadow-md [&::-webkit-slider-thumb]:cursor-pointer
                                               [&::-moz-range-thumb]:pointer-events-auto [&::-moz-range-thumb]:appearance-none [&::-moz-range-thumb]:border-0
                                               [&::-moz-range-thumb]:w-4 [&::-moz-range-thumb]:h-4 [&::-moz-range-thumb]:rounded-full
                                               [&::-moz-range-thumb]:bg-white [&::-moz-range-thumb]:ring-2 [&::-moz-range-thumb]:ring-[#0d9488]
                                               [&::-moz-range-thumb]:cursor-pointer"
                                        @input="onMinSlide($event.target.value)"
                                    >
                                    <input
                                        type="range"
                                        :min="priceBounds.min"
                                        :max="priceBounds.max"
                                        :value="sliderMax"
                                        class="absolute inset-0 w-full h-1.5 m-0 appearance-none bg-transparent pointer-events-none
                                               [&::-webkit-slider-thumb]:pointer-events-auto [&::-webkit-slider-thumb]:appearance-none
                                               [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:rounded-full
                                               [&::-webkit-slider-thumb]:bg-white [&::-webkit-slider-thumb]:border-2 [&::-webkit-slider-thumb]:border-[#0d9488]
                                               [&::-webkit-slider-thumb]:shadow-md [&::-webkit-slider-thumb]:cursor-pointer
                                               [&::-moz-range-thumb]:pointer-events-auto [&::-moz-range-thumb]:appearance-none [&::-moz-range-thumb]:border-0
                                               [&::-moz-range-thumb]:w-4 [&::-moz-range-thumb]:h-4 [&::-moz-range-thumb]:rounded-full
                                               [&::-moz-range-thumb]:bg-white [&::-moz-range-thumb]:ring-2 [&::-moz-range-thumb]:ring-[#0d9488]
                                               [&::-moz-range-thumb]:cursor-pointer"
                                        @input="onMaxSlide($event.target.value)"
                                    >
                                </div>

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

                        <!-- Color -->
                        <div
                            v-if="availableColors.length > 0"
                            class="mb-8"
                        >
                            <button
                                type="button"
                                class="flex justify-between items-center w-full mb-4"
                                @click="toggleSection('color')"
                            >
                                <h3 class="text-sm font-bold text-slate-900">Color</h3>
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-slate-400 transition-transform" :class="{ 'rotate-180': !sectionsOpen.color }">
                                    <path d="m18 15-6-6-6 6" />
                                </svg>
                            </button>
                            <div
                                v-show="sectionsOpen.color"
                                class="flex flex-wrap gap-3"
                            >
                                <label
                                    v-for="color in availableColors"
                                    :key="color.value"
                                    class="group cursor-pointer"
                                    :title="`${color.value} (${color.count})`"
                                >
                                    <input
                                        v-model="selectedColors"
                                        type="checkbox"
                                        :value="color.value"
                                        class="sr-only peer"
                                        @change="resetPage"
                                    >
                                    <span
                                        v-if="swatchColor(color.value)"
                                        class="flex w-8 h-8 rounded-full ring-1 ring-slate-200 ring-offset-2 items-center justify-center transition-all peer-checked:ring-2 peer-checked:ring-[#0d9488]"
                                        :style="{ background: swatchColor(color.value) }"
                                    ></span>
                                    <span
                                        v-else
                                        class="flex items-center px-3 h-8 rounded-full border text-xs font-semibold transition-colors"
                                        :class="selectedColors.includes(color.value)
                                            ? 'border-[#0d9488] bg-teal-50 text-[#0d9488]'
                                            : 'border-slate-200 text-slate-500 group-hover:border-slate-300'"
                                    >
                                        {{ color.value }}
                                    </span>
                                </label>
                            </div>
                        </div>

                        <!-- Size -->
                        <div
                            v-if="availableSizes.length > 0"
                            class="mb-8"
                        >
                            <button
                                type="button"
                                class="flex justify-between items-center w-full mb-4"
                                @click="toggleSection('size')"
                            >
                                <h3 class="text-sm font-bold text-slate-900">Size</h3>
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-slate-400 transition-transform" :class="{ 'rotate-180': !sectionsOpen.size }">
                                    <path d="m18 15-6-6-6 6" />
                                </svg>
                            </button>
                            <div
                                v-show="sectionsOpen.size"
                                class="grid grid-cols-4 gap-2"
                            >
                                <label
                                    v-for="size in availableSizes"
                                    :key="size.value"
                                    class="cursor-pointer"
                                    :title="`${size.count} available`"
                                >
                                    <input
                                        v-model="selectedSizes"
                                        type="checkbox"
                                        :value="size.value"
                                        class="sr-only peer"
                                        @change="resetPage"
                                    >
                                    <span class="flex items-center justify-center h-9 rounded-xl border text-xs font-bold transition-colors peer-checked:border-[#0d9488] peer-checked:bg-[#0d9488] peer-checked:text-white border-slate-200 text-slate-600 hover:border-slate-300">
                                        {{ size.value }}
                                    </span>
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

                    <!-- Order Tracking Widget -->
                    <div
                        class="rounded-3xl border border-slate-100 p-6"
                        style="background: #0f2f2c; box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                    >
                        <div class="flex items-center gap-3 mb-5">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-teal-300 shrink-0" style="background: #16423e;">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" /><circle cx="12" cy="10" r="3" />
                                </svg>
                            </div>
                            <h3 class="font-bold text-white">Track Your Order</h3>
                        </div>

                        <p
                            v-if="isLoadingOrders"
                            class="text-sm text-teal-100/60"
                        >
                            Checking your orders&hellip;
                        </p>

                        <template v-else-if="latestActiveOrder">
                            <p class="text-xs text-teal-100/60 mb-3">Order {{ latestActiveOrder.orderId }}</p>

                            <div class="flex items-center gap-1.5 mb-4">
                                <div
                                    v-for="(step, index) in trackingSteps"
                                    :key="step"
                                    class="flex-1 h-1.5 rounded-full"
                                    :class="isTrackingStepCompleted(latestActiveOrder, index) ? 'bg-[#2dd4bf]' : 'bg-white/10'"
                                ></div>
                            </div>

                            <p class="text-sm font-bold text-white mb-5">
                                {{ stepLabels[latestActiveOrder.status] || latestActiveOrder.status }}
                            </p>

                            <button
                                type="button"
                                class="w-full py-2.5 bg-[#0d9488] text-white rounded-xl text-xs font-bold hover:bg-[#0f766e] transition-all"
                                @click="emit('view-orders')"
                            >
                                Track Package
                            </button>
                        </template>

                        <template v-else>
                            <p class="text-sm text-teal-100/70 leading-relaxed mb-5">
                                You don't have any orders in progress right now.
                            </p>
                            <button
                                type="button"
                                class="w-full py-2.5 bg-white/10 text-white rounded-xl text-xs font-bold hover:bg-white/15 transition-all"
                                @click="emit('view-orders')"
                            >
                                View My Orders
                            </button>
                        </template>
                    </div>

                </aside>

                <!-- ==================================================== -->
                <!-- PRODUCT GRID AREA -->
                <!-- ==================================================== -->

                <div class="flex-1 min-w-0">

                    <!-- Controls -->
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-6">
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

                    <!-- Active Filter Chips -->
                    <div
                        v-if="activeFilterChips.length > 0"
                        class="flex flex-wrap items-center gap-2 mb-8"
                    >
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider mr-1">Active Filter</span>

                        <span
                            v-for="chip in activeFilterChips"
                            :key="`${chip.type}:${chip.value}`"
                            class="inline-flex items-center gap-1.5 pl-3.5 pr-2 py-1.5 rounded-full bg-slate-900 text-white text-xs font-semibold"
                        >
                            {{ chip.label }}
                            <button
                                type="button"
                                class="w-4 h-4 flex items-center justify-center rounded-full hover:bg-white/20 transition-colors"
                                :title="`Remove ${chip.label} filter`"
                                @click="removeChip(chip)"
                            >
                                <svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M18 6 6 18" /><path d="m6 6 12 12" />
                                </svg>
                            </button>
                        </span>

                        <button
                            type="button"
                            class="text-xs font-bold text-slate-500 underline underline-offset-2 hover:text-slate-900 transition-colors ml-1"
                            @click="clearAllFilters"
                        >
                            Clear All
                        </button>
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
