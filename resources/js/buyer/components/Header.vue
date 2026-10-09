<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { useHomeSession } from '../../home/composables/useHomeSession';
import { useBuyer } from '../composables/useBuyer';
import { primeUnread, useBuyerChat } from '../composables/useBuyerChat';
import { requestBuyerView } from '../composables/useBuyerNav';
import { useBuyerProducts } from '../composables/useBuyerProducts';
import { useBuyerSession } from '../composables/useBuyerSession';
import { categories, metaFor, formatPrice } from '../composables/useCategoryMeta';
import { createLatestRequest, fetchJson } from '../composables/useStores';
import BrandMark from './BrandMark.vue';
import StoreLogo from './StoreLogo.vue';

const props = defineProps({
    /*
    |----------------------------------------------------------------------
    | activeCategory
    |----------------------------------------------------------------------
    |
    | On the dashboard this is the live filter ('All', 'Electronics and
    | Gadgets', ...). On other pages (e.g. Product Details) pass the current
    | product's category so the matching category-bar entry highlights;
    | omit it (or pass '') to highlight nothing.
    |
    */
    activeCategory: {
        type: String,
        default: ''
    },
    searchQuery: {
        type: String,
        default: ''
    },

    // Highlights a non-category destination in the category bar / drawer
    // ('stores' on the store directory and store pages).
    activeView: {
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

const { cartItemCount, favoriteCount } = useBuyer();
const { totalUnread, toggleChat } = useBuyerChat();

// The Messages badge: one unread-count request per page load.
primeUnread();
const { products } = useBuyerProducts();
const { buyerProfile } = useBuyerSession();
const { logout } = useHomeSession();

const headerRoot = ref(null);

/*
|--------------------------------------------------------------------------
| Categories (ordered by real in-stock count)
|--------------------------------------------------------------------------
|
| Same rule Dashboard.vue's category rail uses, so both surfaces list the
| category a buyer can actually shop right now first.
|
*/

const inStockCounts = computed(() => {
    const counts = {};

    for (const product of products.value) {
        if (product.stock > 0) {
            counts[product.category] = (counts[product.category] || 0) + 1;
        }
    }

    return counts;
});

const shopCategories = computed(() =>
    categories
        .filter(c => c !== 'All')
        .map((category, index) => ({ category, index }))
        .sort((a, b) =>
            (inStockCounts.value[b.category] || 0) - (inStockCounts.value[a.category] || 0)
            || a.index - b.index,
        )
        .map(entry => entry.category)
);

const brandOptions = computed(() => {
    const set = new Set();

    for (const product of products.value) {
        if (product.brand) {
            set.add(product.brand);
        }
    }

    return [...set].sort((a, b) => a.localeCompare(b));
});

function selectCategory(category) {
    closeAll();
    emit('select-category', category);
}

function goToDeals() {
    closeAll();
    requestBuyerView('deals');
}

function goToStores() {
    closeAll();
    requestBuyerView('stores');
}

/*
|--------------------------------------------------------------------------
| Search + Suggestions
|--------------------------------------------------------------------------
|
| Always visible (search is the primary way into a marketplace). One box
| finds both products and stores — nothing to choose, nothing guessed from
| the words typed. Enter opens the results page, which has a Stores and a
| Products section (SearchResults.vue).
|
| Suggestions come in two labelled groups, Products (with a thumbnail) and
| Stores (with the logo), both from one debounced server request over the
| whole catalogue (GET /api/search/suggestions); the latest request wins.
| Picking one opens that product or store. It is an ARIA combobox: arrow
| keys move through options, Enter picks the active option or submits the
| raw query.
|
*/

const localSearch = ref(props.searchQuery);
const searchFocused = ref(false);
const activeOption = ref(-1);
const searchInput = ref(null);

watch(() => props.searchQuery, (value) => {
    localSearch.value = value;
});

// Suggestions come from the server (GET /api/search/suggestions): products
// and stores from the whole catalogue, matched and ranked like the results
// page. One debounced request per pause in typing; an answer for an older
// term is dropped (latest request wins), and only suggestions for exactly
// what's typed now are shown.
const SUGGEST_MIN_LENGTH = 2;
const SUGGEST_DELAY_MS = 200;

const suggestTerm = ref('');
const suggestProducts = ref([]);
const suggestStores = ref([]);
const suggestStatus = ref('idle');
const suggestRequest = createLatestRequest();
let suggestTimer = null;

watch(localSearch, (value) => {
    clearTimeout(suggestTimer);

    const term = value.trim();

    if (term.length < SUGGEST_MIN_LENGTH) {
        suggestRequest.cancel();
        suggestTerm.value = '';
        suggestProducts.value = [];
        suggestStores.value = [];
        suggestStatus.value = 'idle';

        return;
    }

    suggestStatus.value = 'loading';

    suggestTimer = setTimeout(async () => {
        try {
            const result = await suggestRequest.run(`/api/search/suggestions?q=${encodeURIComponent(term)}`);

            if (result.stale) {
                return;
            }

            suggestProducts.value = result.body.products || [];
            suggestStores.value = result.body.stores || [];
            suggestTerm.value = term;
            suggestStatus.value = 'ready';
        } catch {
            suggestProducts.value = [];
            suggestStores.value = [];
            suggestTerm.value = term;
            suggestStatus.value = 'error';
        }
    }, SUGGEST_DELAY_MS);
});

const suggestionsAreCurrent = computed(() => suggestTerm.value !== '' && suggestTerm.value === localSearch.value.trim());

const productSuggestions = computed(() => (suggestionsAreCurrent.value ? suggestProducts.value : []).map(product => ({
    type: 'product',
    key: `p-${product.id}`,
    label: product.name,
    product
})));

const storeSuggestions = computed(() => (suggestionsAreCurrent.value ? suggestStores.value : []).map(store => ({
    type: 'store',
    key: `s-${store.id}`,
    label: store.name,
    store
})));

// Products then Stores, each option with its position in the flat keyboard
// order.
const suggestionGroups = computed(() => {
    const groups = [
        { id: 'products', label: 'Products', options: productSuggestions.value },
        { id: 'stores', label: 'Stores', options: storeSuggestions.value }
    ].filter(group => group.options.length);

    let index = 0;

    return groups.map(group => ({
        ...group,
        options: group.options.map(option => ({ ...option, index: index++ }))
    }));
});

const suggestions = computed(() => suggestionGroups.value.flatMap(group => group.options));

const showSuggestions = computed(() => searchFocused.value && suggestions.value.length > 0);

// A line under (or instead of) the suggestions: still searching, nothing
// found, or suggestions unavailable — Enter always runs the full search.
const suggestStatusText = computed(() => {
    if (!searchFocused.value || localSearch.value.trim().length < SUGGEST_MIN_LENGTH) {
        return '';
    }

    if (suggestStatus.value === 'loading' && !suggestionsAreCurrent.value) {
        return 'Searching…';
    }

    if (!suggestionsAreCurrent.value) {
        return '';
    }

    if (suggestStatus.value === 'error') {
        return 'Suggestions aren’t available right now. Press Enter to search.';
    }

    return suggestions.value.length ? '' : 'No quick matches. Press Enter to search everything.';
});

watch(suggestions, () => {
    activeOption.value = -1;
});

function handleSearchInput(event) {
    localSearch.value = event.target.value;
    emit('update:searchQuery', localSearch.value);
}

function submitSearch(query = localSearch.value) {
    localSearch.value = query;
    emit('update:searchQuery', query);
    searchFocused.value = false;
    searchInput.value?.blur();
    closeAll();
    emit('search', query.trim());
}

// A product suggestion only carries what its row shows, so the full
// product is fetched before its page opens; if that fails, the buyer gets
// the search results for its name instead.
async function openProductSuggestion(product) {
    try {
        const body = await fetchJson(`/api/products/${encodeURIComponent(product.id)}`);

        requestBuyerView('product', body.data);
    } catch {
        submitSearch(product.name);
    }
}

function pickSuggestion(option) {
    if (option.type === 'product') {
        searchFocused.value = false;
        closeAll();
        openProductSuggestion(option.product);

        return;
    }

    if (option.type === 'store') {
        searchFocused.value = false;
        closeAll();
        requestBuyerView('store', option.store);

        return;
    }

    submitSearch(option.label);
}

function handleSearchKeydown(event) {
    const count = suggestions.value.length;

    if (event.key === 'ArrowDown' && count) {
        event.preventDefault();
        searchFocused.value = true;
        activeOption.value = (activeOption.value + 1) % count;
    } else if (event.key === 'ArrowUp' && count) {
        event.preventDefault();
        activeOption.value = activeOption.value <= 0 ? count - 1 : activeOption.value - 1;
    } else if (event.key === 'Enter' && showSuggestions.value && activeOption.value >= 0) {
        event.preventDefault();
        pickSuggestion(suggestions.value[activeOption.value]);
    } else if (event.key === 'Escape') {
        searchFocused.value = false;
    }
}

function handleSearchBlur() {
    // Let a click on a suggestion land before the list closes.
    setTimeout(() => {
        searchFocused.value = false;
    }, 120);
}

function clearSearch() {
    localSearch.value = '';
    emit('update:searchQuery', '');
    searchInput.value?.focus();
}

/*
|--------------------------------------------------------------------------
| Account Menu
|--------------------------------------------------------------------------
*/

const accountOpen = ref(false);

const accountName = computed(() => buyerProfile.value?.first_name || '');

// Shopping destinations first, then account settings (see useAccountNav).
const accountLinks = [
    { view: 'orders', label: 'My orders' },
    { view: 'wishlist', label: 'Wishlist' },
    { view: 'reviews', label: 'My reviews' },
    { view: 'account', section: 'following', label: 'Followed stores' },
    { view: 'account', section: 'addresses', label: 'Addresses' },
    { action: 'logout', label: 'Log out' },
    { view: 'account', section: 'help', label: 'Help & support' }
];

function toggleAccount() {
    accountOpen.value = !accountOpen.value;
}

function openProfile() {
    closeAll();
    requestBuyerView('account', { section: 'profile' });
}

function goToView(view, section = null) {
    closeAll();
    requestBuyerView(view, section ? { section } : null);
}

/*
|--------------------------------------------------------------------------
| Mobile Drawer
|--------------------------------------------------------------------------
*/

const drawerOpen = ref(false);
const drawerClose = ref(null);
const menuButton = ref(null);

function openDrawer() {
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
    nextTick(() => menuButton.value?.focus());
}

function closeAll() {
    accountOpen.value = false;
    closeDrawer();
}

/*
|--------------------------------------------------------------------------
| Cart Badge Feedback
|--------------------------------------------------------------------------
|
| A short bump on the cart icon whenever the count goes up — the visual
| half of "added to cart" (the toast is the announced half).
|
*/

const cartBumped = ref(false);
let bumpTimer = null;

watch(cartItemCount, (next, previous) => {
    if (next > previous) {
        cartBumped.value = false;
        clearTimeout(bumpTimer);

        requestAnimationFrame(() => {
            cartBumped.value = true;
            bumpTimer = setTimeout(() => {
                cartBumped.value = false;
            }, 450);
        });
    }
});

// Every page renders its own <main>; focus whichever one is mounted.
function skipToContent() {
    const target = document.getElementById('main-content') || document.querySelector('main');

    if (!target) {
        return;
    }

    if (!target.hasAttribute('tabindex')) {
        target.setAttribute('tabindex', '-1');
    }

    target.focus();
}

function handleDocumentClick(event) {
    if (headerRoot.value && !headerRoot.value.contains(event.target)) {
        accountOpen.value = false;
    }
}

function handleKeydown(event) {
    if (event.key === 'Escape') {
        accountOpen.value = false;
        closeDrawer();
    }
}

onMounted(() => {
    document.addEventListener('click', handleDocumentClick);
    document.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    document.removeEventListener('click', handleDocumentClick);
    document.removeEventListener('keydown', handleKeydown);
    document.body.style.overflow = '';
    clearTimeout(bumpTimer);
    clearTimeout(suggestTimer);
    suggestRequest.cancel();
});
</script>

<template>

    <header
        ref="headerRoot"
        class="site-header"
    >

        <a
            class="skip-link"
            href="#main-content"
            @click.prevent="skipToContent"
        >Skip to main content</a>

        <div class="site-header-main">

            <button
                ref="menuButton"
                type="button"
                class="icon-btn header-menu-btn"
                aria-label="Open menu"
                aria-controls="header-drawer"
                :aria-expanded="drawerOpen"
                @click="openDrawer"
            >
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10" /></svg>
            </button>

            <a
                href="/buyer"
                class="site-header-logo"
                aria-label="BuyTheWay home"
                @click.prevent="emit('logo-click'); requestBuyerView('home')"
            >
                <BrandMark />
            </a>

            <form
                class="header-search"
                role="search"
                @submit.prevent="submitSearch()"
            >
                <label
                    for="header-search-input"
                    class="sr-only"
                >Search products or stores</label>

                <svg class="header-search-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7" />
                    <path d="m20 20-3.5-3.5" />
                </svg>

                <input
                    id="header-search-input"
                    ref="searchInput"
                    :value="localSearch"
                    type="search"
                    role="combobox"
                    autocomplete="off"
                    enterkeyhint="search"
                    aria-autocomplete="list"
                    aria-controls="header-search-suggestions"
                    :aria-expanded="showSuggestions"
                    :aria-activedescendant="activeOption >= 0 ? `search-opt-${activeOption}` : undefined"
                    placeholder="Search products or stores"
                    @input="handleSearchInput"
                    @focus="searchFocused = true"
                    @blur="handleSearchBlur"
                    @keydown="handleSearchKeydown"
                >

                <button
                    v-if="localSearch"
                    type="button"
                    class="header-search-clear"
                    aria-label="Clear search text"
                    @click="clearSearch"
                >
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                </button>

                <!-- Icon-only submit: one magnifier at every width; the
                     accessible name and tooltip say "Search". Enter in the
                     field submits the same form. -->
                <button
                    type="submit"
                    class="header-search-submit"
                    aria-label="Search"
                    title="Search"
                >
                    <svg class="header-search-submit-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="7" />
                        <path d="m20 20-3.5-3.5" />
                    </svg>
                </button>

                <div
                    v-show="showSuggestions || suggestStatusText"
                    id="header-search-suggestions"
                    class="header-suggestions"
                    role="listbox"
                    aria-label="Search suggestions"
                >
                    <div
                        v-for="group in suggestionGroups"
                        :key="group.id"
                        role="group"
                        :aria-labelledby="`search-group-${group.id}`"
                        class="header-suggestion-group"
                    >
                        <p
                            :id="`search-group-${group.id}`"
                            class="header-suggestion-heading"
                        >
                            {{ group.label }}
                        </p>
                        <div
                            v-for="option in group.options"
                            :id="`search-opt-${option.index}`"
                            :key="option.key"
                            role="option"
                            class="header-suggestion"
                            :class="{ 'is-active': option.index === activeOption }"
                            :aria-selected="option.index === activeOption"
                            @mousedown.prevent="pickSuggestion(option)"
                            @mouseenter="activeOption = option.index"
                        >
                            <StoreLogo
                                v-if="option.type === 'store'"
                                size="xs"
                                :name="option.store.name"
                                :src="option.store.logo || ''"
                                :category="option.store.category || ''"
                            />
                            <span
                                v-else
                                class="header-suggestion-thumb"
                                aria-hidden="true"
                            >
                                <img
                                    v-if="option.product.image"
                                    :src="option.product.image"
                                    alt=""
                                    width="28"
                                    height="28"
                                    loading="lazy"
                                    @error="$event.target.remove()"
                                >
                            </span>
                            <span class="header-suggestion-label">{{ option.label }}</span>
                            <span
                                v-if="option.type === 'product'"
                                class="header-suggestion-price"
                            >{{ formatPrice(option.product.price) }}</span>
                            <span
                                v-else-if="option.type === 'store' && option.store.category"
                                class="header-suggestion-meta"
                            >{{ option.store.category }}</span>
                        </div>
                    </div>
                    <p
                        v-if="suggestStatusText"
                        class="header-suggestion-status"
                        role="status"
                    >
                        {{ suggestStatusText }}
                    </p>
                </div>
            </form>

            <nav
                class="header-actions"
                aria-label="Your shortcuts"
            >

                <button
                    type="button"
                    class="icon-btn header-action"
                    data-chat-trigger
                    aria-haspopup="dialog"
                    :aria-label="totalUnread > 0 ? `Messages, ${totalUnread} unread` : 'Messages'"
                    @click="toggleChat"
                >
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.9A8 8 0 1 1 21 12Z" />
                    </svg>
                    <span
                        v-if="totalUnread > 0"
                        class="header-badge"
                        aria-hidden="true"
                    >{{ totalUnread > 99 ? '99+' : totalUnread }}</span>
                </button>

                <button
                    type="button"
                    class="icon-btn header-action header-action-wishlist"
                    :aria-label="favoriteCount > 0 ? `Wishlist, ${favoriteCount} saved` : 'Wishlist'"
                    @click="goToView('wishlist')"
                >
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 20.5s-7.5-4.6-9.2-9.4C1.7 7.9 3.9 4.5 7.4 4.5c2 0 3.5 1.1 4.6 2.7 1.1-1.6 2.6-2.7 4.6-2.7 3.5 0 5.7 3.4 4.6 6.6-1.7 4.8-9.2 9.4-9.2 9.4Z" />
                    </svg>
                    <span
                        v-if="favoriteCount > 0"
                        class="header-badge is-muted"
                        aria-hidden="true"
                    >{{ favoriteCount }}</span>
                </button>

                <div
                    class="header-account"
                    :class="{ 'is-open': accountOpen }"
                >
                    <button
                        type="button"
                        class="header-action header-account-btn"
                        aria-haspopup="true"
                        aria-controls="header-account-menu"
                        :aria-expanded="accountOpen"
                        @click.stop="toggleAccount"
                    >
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                            <circle cx="12" cy="8" r="4" />
                            <path d="M4 21a8 8 0 0 1 16 0" />
                        </svg>
                        <span class="header-action-text">
                            <small>{{ accountName ? `Hi, ${accountName}` : 'Account' }}</small>
                            <strong>Orders &amp; more</strong>
                        </span>
                    </button>

                    <div
                        id="header-account-menu"
                        class="header-menu"
                    >
                        <a
                            v-if="!buyerProfile"
                            href="/login"
                            class="header-menu-signin"
                        >Sign in</a>
                        <button
                            type="button"
                            @click="openProfile"
                        >
                            My profile
                        </button>
                        <button
                            v-for="link in accountLinks"
                            :key="link.label"
                            v-show="link.action !== 'logout' || buyerProfile"
                            type="button"
                            @click="link.action === 'logout' ? logout() : goToView(link.view, link.section)"
                        >
                            {{ link.label }}
                        </button>
                    </div>
                </div>

                <button
                    type="button"
                    class="header-action header-cart"
                    :class="{ 'is-bumped': cartBumped }"
                    :aria-label="cartItemCount > 0 ? `Cart, ${cartItemCount} items` : 'Cart, empty'"
                    @click="closeAll(); emit('cart-click')"
                >
                    <span class="header-cart-icon">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 4h2l2.2 11.2a1.5 1.5 0 0 0 1.5 1.2h8.9a1.5 1.5 0 0 0 1.5-1.1L21 8H6" />
                            <circle cx="9.5" cy="20" r="1.2" />
                            <circle cx="17.5" cy="20" r="1.2" />
                        </svg>
                        <span
                            v-if="cartItemCount > 0"
                            class="header-badge"
                            aria-hidden="true"
                        >{{ cartItemCount > 99 ? '99+' : cartItemCount }}</span>
                    </span>
                    <span
                        class="header-cart-label"
                        aria-hidden="true"
                    >Cart</span>
                </button>

            </nav>

        </div>

        <!-- Category bar (tablet / desktop) -->
        <nav
            class="category-bar"
            aria-label="Shop by category"
        >
            <ul class="category-bar-list">
                <li>
                    <button
                        type="button"
                        class="category-bar-link"
                        :class="{ 'is-active': activeCategory === 'All' }"
                        :aria-current="activeCategory === 'All' ? 'page' : undefined"
                        @click="selectCategory('All')"
                    >
                        All products
                    </button>
                </li>
                <li>
                    <button
                        type="button"
                        class="category-bar-link is-deal"
                        @click="goToDeals"
                    >
                        Deals
                    </button>
                </li>
                <li>
                    <button
                        type="button"
                        class="category-bar-link"
                        :class="{ 'is-active': activeView === 'stores' }"
                        :aria-current="activeView === 'stores' ? 'page' : undefined"
                        @click="goToStores"
                    >
                        Stores
                    </button>
                </li>
                <li
                    class="category-bar-sep"
                    aria-hidden="true"
                ></li>
                <li
                    v-for="category in shopCategories"
                    :key="category"
                >
                    <button
                        type="button"
                        class="category-bar-link"
                        :class="{ 'is-active': activeCategory === category }"
                        :aria-current="activeCategory === category ? 'page' : undefined"
                        @click="selectCategory(category)"
                    >
                        {{ category }}
                    </button>
                </li>
            </ul>
        </nav>

        <!-- Mobile drawer -->
        <div
            class="drawer-backdrop"
            :class="{ 'is-open': drawerOpen }"
            aria-hidden="true"
            @click="closeDrawer"
        ></div>

        <aside
            id="header-drawer"
            class="header-drawer"
            :class="{ 'is-open': drawerOpen }"
            role="dialog"
            aria-modal="true"
            aria-label="Menu"
            :inert="!drawerOpen || undefined"
        >
            <div class="header-drawer-head">
                <BrandMark compact />
                <button
                    ref="drawerClose"
                    type="button"
                    class="icon-btn"
                    aria-label="Close menu"
                    @click="closeDrawer"
                >
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                </button>
            </div>

            <div class="header-drawer-body">
                <p class="header-drawer-label">Shop</p>
                <button
                    type="button"
                    class="header-drawer-link"
                    :class="{ 'is-active': activeCategory === 'All' }"
                    @click="selectCategory('All')"
                >
                    All products
                </button>
                <button
                    type="button"
                    class="header-drawer-link is-deal"
                    @click="goToDeals"
                >
                    Deals
                </button>
                <button
                    type="button"
                    class="header-drawer-link"
                    :class="{ 'is-active': activeView === 'stores' }"
                    @click="goToStores"
                >
                    Stores
                </button>
                <button
                    v-for="category in shopCategories"
                    :key="category"
                    type="button"
                    class="header-drawer-link"
                    :class="{ 'is-active': activeCategory === category }"
                    @click="selectCategory(category)"
                >
                    <span
                        class="header-drawer-icon"
                        aria-hidden="true"
                        v-html="metaFor(category).icon"
                    ></span>
                    <span>{{ category }}</span>
                    <span
                        v-if="inStockCounts[category]"
                        class="header-drawer-count"
                    >{{ inStockCounts[category] }}</span>
                </button>

                <template v-if="brandOptions.length > 0">
                    <p class="header-drawer-label">Brands</p>
                    <div class="header-drawer-chips">
                        <button
                            v-for="brand in brandOptions"
                            :key="brand"
                            type="button"
                            class="chip"
                            @click="submitSearch(brand)"
                        >
                            {{ brand }}
                        </button>
                    </div>
                </template>

                <p class="header-drawer-label">Your account</p>
                <a
                    v-if="!buyerProfile"
                    href="/login"
                    class="header-drawer-link"
                >Sign in</a>
                <button
                    type="button"
                    class="header-drawer-link"
                    @click="openProfile"
                >
                    My profile
                </button>
                <button
                    v-for="link in accountLinks"
                    :key="link.label"
                    v-show="link.action !== 'logout' || buyerProfile"
                    type="button"
                    class="header-drawer-link"
                    @click="link.action === 'logout' ? logout() : goToView(link.view, link.section)"
                >
                    {{ link.label }}
                </button>
            </div>
        </aside>

    </header>

</template>
