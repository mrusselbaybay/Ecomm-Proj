<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue';
import { useBuyer } from '../composables/useBuyer';
import { useBuyerChat } from '../composables/useBuyerChat';
import { useBuyerProducts } from '../composables/useBuyerProducts';
import { categories } from '../composables/useCategoryMeta';

const props = defineProps({
    /*
    |----------------------------------------------------------------------
    | activeCategory
    |----------------------------------------------------------------------
    |
    | On the dashboard this is the live filter ('All', 'Electronics and
    | Gadgets', ...).
    | On other pages (e.g. Product Details) pass the current product's
    | category so the matching "Shop" dropdown entry highlights; pass ''
    | to highlight nothing.
    |
    */
    activeCategory: {
        type: String,
        default: 'All'
    },
    searchQuery: {
        type: String,
        default: ''
    }
});

const emit = defineEmits([
    'update:searchQuery',
    'search',
    'select-category',
    'account-click',
    'cart-click',
    'logo-click'
]);

const { cartItemCount } = useBuyer();
const { totalUnread, toggleChat } = useBuyerChat();

const localSearch = ref(props.searchQuery);

function handleSearchInput(event) {
    localSearch.value = event.target.value;
    emit('update:searchQuery', localSearch.value);
}

function handleSearchSubmit() {
    emit('search', localSearch.value);
}

/*
|--------------------------------------------------------------------------
| Search Flyout
|--------------------------------------------------------------------------
|
| The search bar is icon-triggered rather than always-visible — click the
| icon, a slide-down field appears and gets focus.
|
*/

const searchOpen = ref(false);
const searchInput = ref(null);

function toggleSearch() {
    searchOpen.value = !searchOpen.value;
    closeDrop();

    if (searchOpen.value) {
        nextTick(() => searchInput.value?.focus());
    }
}

function closeSearch() {
    searchOpen.value = false;
}

/*
|--------------------------------------------------------------------------
| Announcement Ticker
|--------------------------------------------------------------------------
|
| Decorative scrolling marquee. The message reuses the app's real tagline
| (no invented sitewide discount) repeated with "+" separators; a static
| copy is kept for screen readers since the scrolling track is aria-hidden.
|
*/

const tickerMessage = 'Shop Trusted Local Sellers. Quality Goods Delivered To Your Door.';
const tickerRepeats = Array.from({ length: 8 });

/*
|--------------------------------------------------------------------------
| Shop / Collections / Brands Dropdowns
|--------------------------------------------------------------------------
|
| "Shop" lists the app's real categories (same list the old subnav used),
| ordered by real in-stock product count so the category a buyer can
| actually shop right now surfaces first — same rule Dashboard.vue's own
| category grid uses, kept consistent across both nav surfaces.
| "Collections" links to real, already-existing homepage sections (Flash
| Deals / All Products) — not invented collections. New Arrivals and Best
| Sellers are sort options inside All Products now, not their own
| sections, so they're not separate links here either.
| "Brands" is built from whatever real `brand` values are on the products
| already loaded in memory (see useBuyerProducts.js — shared/module-scope,
| so this reads whatever Dashboard.vue's onMounted already fetched, no
| second request). If no product has a brand yet, the dropdown is hidden
| entirely rather than showing empty.
|
*/

const { products } = useBuyerProducts();

const shopCategories = computed(() => {
    const counts = {};

    products.value.forEach(product => {
        if (product.stock > 0) {
            counts[product.category] = (counts[product.category] || 0) + 1;
        }
    });

    return categories
        .filter(c => c !== 'All')
        .map((category, index) => ({ category, index }))
        .sort((a, b) =>
            (counts[b.category] || 0) - (counts[a.category] || 0)
            || a.index - b.index,
        )
        .map(entry => entry.category);
});

const shopCategoriesLeft = computed(() => shopCategories.value.slice(0, 4));
const shopCategoriesRight = computed(() => shopCategories.value.slice(4));

const collectionLinks = [
    { id: 'flash-deals', label: 'Flash Deals' },
    { id: 'buyer-products', label: 'All Products' }
];

const brandOptions = computed(() => {
    const set = new Set();

    for (const product of products.value) {
        if (product.brand) {
            set.add(product.brand);
        }
    }

    return [...set].sort((a, b) => a.localeCompare(b));
});

const brandOptionsLeft = computed(() =>
    brandOptions.value.slice(0, Math.ceil(brandOptions.value.length / 2))
);
const brandOptionsRight = computed(() =>
    brandOptions.value.slice(Math.ceil(brandOptions.value.length / 2))
);

const openDrop = ref(null);
const navRoot = ref(null);

function toggleDrop(name) {
    openDrop.value = openDrop.value === name ? null : name;
    closeSearch();
}

function closeDrop() {
    openDrop.value = null;
}

function selectShopCategory(category) {
    emit('select-category', category);
    closeDrop();
}

// Collections point at real sections that only exist on the dashboard, so
// this always routes home first (same as picking a category), then scrolls
// once that view has had a moment to render.
function goToSection(id) {
    emit('select-category', 'All');
    closeDrop();

    setTimeout(() => {
        document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 250);
}

function searchBrand(brand) {
    localSearch.value = brand;
    emit('update:searchQuery', brand);
    emit('search', brand);
    closeDrop();
}

function handleDocumentClick(event) {
    if (navRoot.value && !navRoot.value.contains(event.target)) {
        closeDrop();
        closeSearch();
    }
}

function handleKeydown(event) {
    if (event.key === 'Escape') {
        closeDrop();
        closeSearch();
    }
}

onMounted(() => {
    document.addEventListener('click', handleDocumentClick);
    document.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    document.removeEventListener('click', handleDocumentClick);
    document.removeEventListener('keydown', handleKeydown);
});
</script>

<template>

    <div>

        <!-- Announcement Ticker -->
        <div class="ticker-bar">
            <span class="sr-only">{{ tickerMessage }}</span>
            <div
                class="ticker-track"
                aria-hidden="true"
            >
                <div
                    v-for="seq in 2"
                    :key="seq"
                    class="ticker-seq"
                >
                    <template
                        v-for="n in tickerRepeats.length"
                        :key="n"
                    >
                        <span class="ticker-item">{{ tickerMessage }}</span>
                        <span class="ticker-item ticker-plus">+</span>
                    </template>
                </div>
            </div>
        </div>

        <div
            ref="navRoot"
            class="buyer-header-wrap"
        >

            <!-- Main Nav -->
            <nav class="main-nav">

                <a
                    href="#"
                    class="brand-word"
                    @click.prevent="emit('logo-click')"
                >
                    NEXMART
                </a>

                <div class="nav-links">

                    <div
                        class="nav-drop"
                        :class="{ open: openDrop === 'shop' }"
                    >
                        <button
                            type="button"
                            class="nav-link"
                            :aria-expanded="openDrop === 'shop'"
                            @click="toggleDrop('shop')"
                        >
                            Shop<span class="plus">+</span>
                        </button>
                        <div class="nav-dropdown">
                            <div class="nav-dropdown-grid">
                                <div class="nav-dropdown-list">
                                    <button
                                        v-for="category in shopCategoriesLeft"
                                        :key="category"
                                        type="button"
                                        :class="{ active: activeCategory === category }"
                                        @click="selectShopCategory(category)"
                                    >
                                        {{ category }}
                                    </button>
                                </div>
                                <div class="nav-dropdown-list">
                                    <button
                                        v-for="category in shopCategoriesRight"
                                        :key="category"
                                        type="button"
                                        :class="{ active: activeCategory === category }"
                                        @click="selectShopCategory(category)"
                                    >
                                        {{ category }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        class="nav-drop"
                        :class="{ open: openDrop === 'collections' }"
                    >
                        <button
                            type="button"
                            class="nav-link"
                            :aria-expanded="openDrop === 'collections'"
                            @click="toggleDrop('collections')"
                        >
                            Collections<span class="plus">+</span>
                        </button>
                        <div class="nav-dropdown">
                            <div class="nav-dropdown-list">
                                <button
                                    v-for="link in collectionLinks"
                                    :key="link.id"
                                    type="button"
                                    @click="goToSection(link.id)"
                                >
                                    {{ link.label }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="brandOptions.length > 0"
                        class="nav-drop"
                        :class="{ open: openDrop === 'brands' }"
                    >
                        <button
                            type="button"
                            class="nav-link"
                            :aria-expanded="openDrop === 'brands'"
                            @click="toggleDrop('brands')"
                        >
                            Brands<span class="plus">+</span>
                        </button>
                        <div class="nav-dropdown">
                            <div class="nav-dropdown-grid">
                                <div class="nav-dropdown-list">
                                    <button
                                        v-for="brand in brandOptionsLeft"
                                        :key="brand"
                                        type="button"
                                        @click="searchBrand(brand)"
                                    >
                                        {{ brand }}
                                    </button>
                                </div>
                                <div
                                    v-if="brandOptionsRight.length > 0"
                                    class="nav-dropdown-list"
                                >
                                    <button
                                        v-for="brand in brandOptionsRight"
                                        :key="brand"
                                        type="button"
                                        @click="searchBrand(brand)"
                                    >
                                        {{ brand }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Buyer Actions -->
                <div class="buyer-actions">

                    <button
                        type="button"
                        title="Search"
                        aria-controls="header-search-panel"
                        :aria-expanded="searchOpen"
                        @click="toggleSearch"
                    >
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.3-4.3" />
                        </svg>
                    </button>

                    <button
                        type="button"
                        data-chat-trigger
                        :title="totalUnread > 0 ? `Messages (${totalUnread} unread)` : 'Messages'"
                        @click="toggleChat"
                    >
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
                        </svg>

                        <span
                            v-if="totalUnread > 0"
                            class="cart-count"
                        >
                            {{ totalUnread }}
                        </span>
                    </button>

                    <button
                        type="button"
                        title="Cart"
                        class="cart-button"
                        @click="emit('cart-click')"
                    >
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="9" cy="21" r="1" />
                            <circle cx="20" cy="21" r="1" />
                            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                        </svg>

                        <span
                            v-if="cartItemCount > 0"
                            class="cart-count"
                        >
                            {{ cartItemCount }}
                        </span>
                    </button>

                    <button
                        type="button"
                        title="Account"
                        @click="emit('account-click')"
                    >
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg>
                    </button>

                </div>

            </nav>

            <!-- Search -->
            <div
                id="header-search-panel"
                class="search-row"
                :class="{ open: searchOpen }"
            >
                <form
                    class="buyer-search"
                    @submit.prevent="handleSearchSubmit"
                >

                    <svg class="search-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <circle cx="11" cy="11" r="7" />
                        <line x1="21" y1="21" x2="16.65" y2="16.65" />
                    </svg>

                    <input
                        ref="searchInput"
                        :value="localSearch"
                        type="text"
                        placeholder="Search products, brands and categories..."
                        @input="handleSearchInput"
                    >

                    <button
                        type="submit"
                        title="Search"
                    >
                        Search
                    </button>

                </form>
            </div>

        </div>

    </div>

</template>
