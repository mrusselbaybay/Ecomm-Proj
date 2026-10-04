<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import BrandMark from './BrandMark.vue';
import { useBuyer } from '../composables/useBuyer';
import { useBuyerChat } from '../composables/useBuyerChat';
import { useBuyerProducts } from '../composables/useBuyerProducts';
import { useBuyerSession } from '../composables/useBuyerSession';
import { requestBuyerView } from '../composables/useBuyerNav';
import { categories, metaFor, formatPrice } from '../composables/useCategoryMeta';

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
const { products } = useBuyerProducts();
const { buyerProfile } = useBuyerSession();

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

/*
|--------------------------------------------------------------------------
| Search + Suggestions
|--------------------------------------------------------------------------
|
| Always visible (search is the primary way into a marketplace). Suggestions
| are drawn from the catalog already in memory — matching categories,
| brands and product names — so typing never fires extra requests. It is an
| ARIA combobox: arrow keys move through options, Enter picks the active
| option or submits the raw query.
|
*/

const localSearch = ref(props.searchQuery);
const searchFocused = ref(false);
const activeOption = ref(-1);
const searchInput = ref(null);

watch(() => props.searchQuery, (value) => {
    localSearch.value = value;
});

const suggestions = computed(() => {
    const term = localSearch.value.trim().toLowerCase();

    if (term.length < 2) {
        return [];
    }

    const categoryHits = shopCategories.value
        .filter(c => c.toLowerCase().includes(term))
        .slice(0, 2)
        .map(category => ({ type: 'category', key: `c-${category}`, label: category, category }));

    const brandHits = brandOptions.value
        .filter(b => b.toLowerCase().includes(term))
        .slice(0, 2)
        .map(brand => ({ type: 'brand', key: `b-${brand}`, label: brand }));

    const productHits = products.value
        .filter(p => p.name.toLowerCase().includes(term))
        .slice(0, 5)
        .map(product => ({ type: 'product', key: `p-${product.id}`, label: product.name, product }));

    return [...categoryHits, ...brandHits, ...productHits];
});

const showSuggestions = computed(() => searchFocused.value && suggestions.value.length > 0);

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
    emit('search', query.trim());
    searchFocused.value = false;
    searchInput.value?.blur();
    closeAll();
}

function pickSuggestion(option) {
    if (option.type === 'category') {
        localSearch.value = '';
        searchFocused.value = false;
        selectCategory(option.category);

        return;
    }

    if (option.type === 'product') {
        searchFocused.value = false;
        closeAll();
        requestBuyerView('product', option.product);

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

const accountLinks = [
    { view: 'orders', label: 'My orders' },
    { view: 'wishlist', label: 'Wishlist' },
    { view: 'addresses', label: 'Saved addresses' },
    { view: 'payments', label: 'Payment methods' },
    { view: 'reviews', label: 'My reviews' }
];

function toggleAccount() {
    accountOpen.value = !accountOpen.value;
}

function openProfile() {
    closeAll();
    emit('account-click');
}

function goToView(view) {
    closeAll();
    requestBuyerView(view);
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
                >Search BuyTheWay</label>

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
                    placeholder="Search products, brands, categories"
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

                <button
                    type="submit"
                    class="header-search-submit"
                >
                    Search
                </button>

                <ul
                    v-show="showSuggestions"
                    id="header-search-suggestions"
                    class="header-suggestions"
                    role="listbox"
                    aria-label="Search suggestions"
                >
                    <li
                        v-for="(option, index) in suggestions"
                        :id="`search-opt-${index}`"
                        :key="option.key"
                        role="option"
                        class="header-suggestion"
                        :class="{ 'is-active': index === activeOption }"
                        :aria-selected="index === activeOption"
                        @mousedown.prevent="pickSuggestion(option)"
                        @mouseenter="activeOption = index"
                    >
                        <span
                            class="header-suggestion-kind"
                            aria-hidden="true"
                        >
                            {{ option.type === 'category' ? 'Category' : option.type === 'brand' ? 'Brand' : '' }}
                        </span>
                        <span class="header-suggestion-label">{{ option.label }}</span>
                        <span
                            v-if="option.type === 'product'"
                            class="header-suggestion-price"
                        >{{ formatPrice(option.product.price) }}</span>
                    </li>
                </ul>
            </form>

            <nav
                class="header-actions"
                aria-label="Your shortcuts"
            >

                <button
                    type="button"
                    class="icon-btn header-action"
                    data-chat-trigger
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
                            :key="link.view"
                            type="button"
                            @click="goToView(link.view)"
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
                    :key="link.view"
                    type="button"
                    class="header-drawer-link"
                    @click="goToView(link.view)"
                >
                    {{ link.label }}
                </button>
            </div>
        </aside>

    </header>

</template>
