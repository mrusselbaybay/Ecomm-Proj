<script setup>
/*
|--------------------------------------------------------------------------
| Wishlist.vue
|--------------------------------------------------------------------------
|
| Adapted from a pasted reference design ("ShopVerse Modern Wishlist") the
| same way as the rest of the account area — Tailwind utilities, the
| shared Header/Footer, #0d9488 brand teal. Two differences from the
| reference worth calling out:
|
|   - The favoriting itself wasn't new to build — `useBuyer.js`'s
|     favorites/toggleFavorite/isFavorite already existed and the heart
|     button on every ProductCard.vue and ProductDetails.vue already
|     wrote to it. What was missing was a page to see the results, and
|     (see useBuyer.js) persistence — favorites lived in memory only and
|     reset on every refresh until this pass added localStorage.
|
|   - The reference shows a star rating + review count per product
|     ("4.8 (1.2k reviews)"). There's no reviews aggregation wired into
|     the product catalog endpoint (see ProductController — no `rating`/
|     `reviewCount` in its transform), so those numbers would be
|     fabricated. Reusing <ProductCard> for the grid view sidesteps this
|     entirely: it already renders the honest fallback ("No reviews yet"
|     with empty stars) everywhere else in the app, so this page just
|     inherits that instead of needing its own decision about it.
|
| Grouped by category (real product.category, in the same canonical order
| as the category nav) rather than the reference's implied manual
| grouping — an actual buyer's wishlist spans whatever categories they've
| saved from, so this computes the grouping instead of hardcoding it.
|
*/
import { computed, ref } from 'vue';
import Header from './Header.vue';
import Footer from './Footer.vue';
import ProductCard from './ProductCard.vue';
import { useBuyer } from '../composables/useBuyer';
import { useBuyerProducts } from '../composables/useBuyerProducts';
import { categories, metaFor, discountPercent, formatPrice } from '../composables/useCategoryMeta';

const emit = defineEmits([
    'back',
    'go-home',
    'view-profile',
    'view-orders',
    'view-reviews',
    'search',
    'select-category',
    'open-cart',
    'select-product'
]);

const { favorites, favoriteCount, toggleFavorite, addToCart } = useBuyer();
const { products, isLoadingProducts, loadError } = useBuyerProducts();

const favoritedProducts = computed(() =>
    products.value.filter(product => favorites.value.includes(product.id))
);

/*
|--------------------------------------------------------------------------
| Sort + Group
|--------------------------------------------------------------------------
*/

const sortBy = ref('recent');
const viewMode = ref('grid');

function sortList(list) {
    const sorted = [...list];

    if (sortBy.value === 'price-asc') {
        sorted.sort((a, b) => (Number(a.price) || 0) - (Number(b.price) || 0));
    } else if (sortBy.value === 'price-desc') {
        sorted.sort((a, b) => (Number(b.price) || 0) - (Number(a.price) || 0));
    } else {
        // "Recently Added" — favorites.value is a plain array that
        // toggleFavorite() pushes onto, so later index = added more
        // recently. No separate timestamp exists to sort by, but this is
        // the real order the buyer favorited things in, not a guess.
        sorted.sort((a, b) => favorites.value.indexOf(b.id) - favorites.value.indexOf(a.id));
    }

    return sorted;
}

// Grouped by category, in the same canonical order as the category nav
// (see useCategoryMeta.js), with any category not in that list (shouldn't
// happen, but data can always surprise you) tacked on alphabetically
// rather than silently dropped.
const groupedFavorites = computed(() => {
    const groups = {};

    for (const product of favoritedProducts.value) {
        const category = product.category || 'Other';

        if (!groups[category]) {
            groups[category] = [];
        }

        groups[category].push(product);
    }

    const canonicalNames = categories.filter(name => name !== 'All' && groups[name]);
    const extraNames = Object.keys(groups)
        .filter(name => !canonicalNames.includes(name))
        .sort();

    return [...canonicalNames, ...extraNames].map(category => ({
        category,
        products: sortList(groups[category])
    }));
});

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

function handleRemove(product) {
    toggleFavorite(product.id);
}

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
</script>

<template>

    <div class="buyer-page">

        <Header
            active-category=""
            @select-category="handleHeaderSelectCategory"
            @cart-click="emit('open-cart')"
            @account-click="emit('view-profile')"
            @logo-click="emit('go-home')"
            @search="handleHeaderSearch"
        />

        <main class="max-w-7xl mx-auto w-full px-4 lg:px-8 py-10">
            <div class="flex flex-col lg:flex-row gap-8">

                <!-- ==================================================== -->
                <!-- SIDEBAR NAV (matching Account / Orders) -->
                <!-- ==================================================== -->

                <aside class="w-full lg:w-64 shrink-0">
                    <nav class="bg-white rounded-3xl p-4 border border-slate-100 space-y-1 lg:sticky lg:top-28" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-profile')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" />
                            </svg>
                            My Profile
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-orders')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z" />
                                <path d="M12 22V12" /><polyline points="3.29 7 12 12 20.71 7" /><path d="m7.5 4.27 9 5.15" />
                            </svg>
                            My Orders
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl bg-slate-100 text-[#0d9488] font-semibold transition-colors"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5" />
                            </svg>
                            Wishlist
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-reviews')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" />
                            </svg>
                            My Reviews
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-profile')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10.268 21a2 2 0 0 0 3.464 0" />
                                <path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326" />
                            </svg>
                            Notifications
                        </button>

                        <div class="pt-1 mt-1 border-t border-slate-50 space-y-1">
                            <div
                                v-for="item in ['Saved Addresses', 'Payment Methods']"
                                :key="item"
                                class="flex items-center justify-between gap-2 px-4 py-3 rounded-2xl text-slate-300 cursor-not-allowed select-none"
                                title="Coming soon"
                            >
                                <span>{{ item }}</span>
                                <span class="text-[9px] font-bold uppercase tracking-wide bg-slate-100 text-slate-400 px-2 py-0.5 rounded-full shrink-0">Soon</span>
                            </div>
                        </div>

                    </nav>
                </aside>

                <!-- ==================================================== -->
                <!-- MAIN CONTENT -->
                <!-- ==================================================== -->

                <div class="flex-1 space-y-10 min-w-0">

                    <!-- Page Header + Controls -->
                    <div class="flex flex-col md:flex-row md:items-end justify-between gap-6">
                        <div>
                            <h1 class="text-3xl font-bold text-slate-900 tracking-tight">Wishlist</h1>
                            <p class="text-slate-500 mt-2">
                                <template v-if="favoriteCount > 0">
                                    You have <span class="font-bold text-slate-900">{{ favoriteCount }} {{ favoriteCount === 1 ? 'item' : 'items' }}</span> saved in your collection.
                                </template>
                                <template v-else>
                                    Save items you like and they'll show up here.
                                </template>
                            </p>
                        </div>

                        <div
                            v-if="favoritedProducts.length > 0"
                            class="flex items-center gap-3"
                        >
                            <div class="flex bg-white rounded-xl border border-slate-200 p-1" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">
                                <button
                                    type="button"
                                    class="p-2 rounded-lg transition-colors"
                                    :class="viewMode === 'grid' ? 'bg-slate-100 text-slate-700' : 'text-slate-400 hover:text-slate-600'"
                                    title="Grid view"
                                    @click="viewMode = 'grid'"
                                >
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect width="7" height="7" x="3" y="3" rx="1" /><rect width="7" height="7" x="14" y="3" rx="1" />
                                        <rect width="7" height="7" x="14" y="14" rx="1" /><rect width="7" height="7" x="3" y="14" rx="1" />
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    class="p-2 rounded-lg transition-colors"
                                    :class="viewMode === 'list' ? 'bg-slate-100 text-slate-700' : 'text-slate-400 hover:text-slate-600'"
                                    title="List view"
                                    @click="viewMode = 'list'"
                                >
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 5h.01" /><path d="M3 12h.01" /><path d="M3 19h.01" />
                                        <path d="M8 5h13" /><path d="M8 12h13" /><path d="M8 19h13" />
                                    </svg>
                                </button>
                            </div>

                            <select
                                v-model="sortBy"
                                class="bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-[#0d9488]/20"
                                style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                            >
                                <option value="recent">Recently Added</option>
                                <option value="price-asc">Price: Low to High</option>
                                <option value="price-desc">Price: High to Low</option>
                            </select>
                        </div>
                    </div>

                    <!-- Loading -->
                    <div
                        v-if="isLoadingProducts"
                        class="empty-products"
                    >
                        <p>Loading your wishlist&hellip;</p>
                    </div>

                    <div
                        v-else-if="loadError"
                        class="empty-products"
                    >
                        <p>{{ loadError }}</p>
                    </div>

                    <!-- Empty -->
                    <div
                        v-else-if="favoritedProducts.length === 0"
                        class="flex flex-col items-center justify-center py-20 text-center"
                    >
                        <div class="w-24 h-24 bg-slate-100 rounded-full flex items-center justify-center text-slate-300 mb-6">
                            <svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z" />
                            </svg>
                        </div>
                        <h2 class="text-2xl font-bold text-slate-900">Your wishlist is empty</h2>
                        <p class="text-slate-500 mt-2 max-w-xs">Tap the heart on any product to save it here for later.</p>
                        <button
                            type="button"
                            class="mt-8 px-8 py-4 bg-[#0d9488] text-white rounded-2xl font-bold hover:bg-[#0f766e] transition-all"
                            @click="emit('go-home')"
                        >
                            Start Shopping
                        </button>
                    </div>

                    <!-- Grouped Wishlist -->
                    <template v-else>
                        <section
                            v-for="group in groupedFavorites"
                            :key="group.category"
                            class="space-y-6"
                        >
                            <div class="flex items-center gap-4">
                                <h2 class="text-sm font-bold text-slate-400 uppercase tracking-[0.2em]">{{ group.category }}</h2>
                                <div class="h-px bg-slate-200 flex-1"></div>
                            </div>

                            <!-- Grid View -->
                            <div
                                v-if="viewMode === 'grid'"
                                class="product-grid"
                            >
                                <ProductCard
                                    v-for="product in group.products"
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
                                    v-for="product in group.products"
                                    :key="product.id"
                                    class="flex flex-col sm:flex-row sm:items-center gap-4 p-5"
                                >
                                    <button
                                        type="button"
                                        class="w-full sm:w-20 h-20 rounded-2xl overflow-hidden shrink-0 flex items-center justify-center"
                                        :class="'accent-' + metaFor(product.category).accent"
                                        style="background: var(--accent-bg, #f1f5f9); color: var(--accent-fg, #64748b);"
                                        @click="handleView(product)"
                                    >
                                        <span class="w-8 h-8" v-html="metaFor(product.category).icon"></span>
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
                                            <span :class="product.stock > 0 ? 'text-emerald-600' : 'text-red-500'">
                                                {{ product.stock > 0 ? 'In Stock' : 'Out of Stock' }}
                                            </span>
                                        </p>
                                    </div>

                                    <div class="flex items-center justify-between sm:justify-end gap-4 shrink-0">
                                        <div class="flex flex-col sm:items-end">
                                            <span class="text-base font-bold text-slate-900">{{ formatPrice(product.price) }}</span>
                                            <span
                                                v-if="product.oldPrice"
                                                class="text-[11px] text-slate-400 line-through"
                                            >
                                                {{ formatPrice(product.oldPrice) }}
                                            </span>
                                        </div>
                                        <button
                                            type="button"
                                            class="w-9 h-9 rounded-xl bg-slate-900 text-white flex items-center justify-center hover:bg-[#0d9488] transition-colors shrink-0"
                                            title="Add to cart"
                                            @click="handleAddToCart(product)"
                                        >
                                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="9" cy="21" r="1" /><circle cx="20" cy="21" r="1" />
                                                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                                            </svg>
                                        </button>
                                        <button
                                            type="button"
                                            class="w-9 h-9 rounded-xl border border-slate-200 text-slate-400 flex items-center justify-center hover:bg-red-50 hover:text-red-500 hover:border-red-100 transition-colors shrink-0"
                                            title="Remove from wishlist"
                                            @click="handleRemove(product)"
                                        >
                                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M3 6h18" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" /><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </template>

                </div>

            </div>
        </main>

        <Footer
            @browse-all="emit('go-home')"
            @browse-categories="emit('go-home')"
            @cart-click="emit('open-cart')"
        />

    </div>

</template>