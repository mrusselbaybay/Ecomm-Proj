<script setup>
/*
|--------------------------------------------------------------------------
| Reviews.vue — My Reviews
|--------------------------------------------------------------------------
|
| Adapted from a pasted reference design ("ShopVerse My Reviews Dashboard")
| onto the real reviews backend built alongside this page — see
| Buyer\ReviewController and useBuyer.js. Two things from the reference
| are deliberately left out:
|
|   - "Helpful Votes" as a stat, and "X people found this helpful" per
|     review — there's no vote/helpfulness column or table for reviews.
|     The third stat card here is "This Month" (real: reviews.length
|     filtered to the current calendar month) instead of a fabricated
|     helpful-vote count.
|   - The "Report" button — this is a buyer's own review of their own
|     purchase; reporting your own review isn't a meaningful action, and
|     there's no review-reporting system to back it anyway.
|
| The reference also shows a separate bold "title" line above each
| review's body text. reviews.comment is the only text column that
| exists (see the migration) — there's no title column — so this treats
| the whole thing as one body of text rather than inventing a headline
| structure the data doesn't have.
|
| Edit is a small inline form on the card itself rather than reusing
| ReviewModal.vue: that modal always resets to a blank rating/comment
| (see its resetForm()), with no way to pre-fill an existing review for
| editing, and — like every other page before this pass — has no matching
| CSS anywhere in the project. Extending it was a separate job from "add
| the review page"; this keeps editing self-contained here instead.
|
*/
import { computed, onMounted, reactive, ref } from 'vue';
import Header from './Header.vue';
import Footer from './Footer.vue';
import { useBuyer } from '../composables/useBuyer';
import { metaFor } from '../composables/useCategoryMeta';

const emit = defineEmits([
    'back',
    'go-home',
    'view-profile',
    'view-orders',
    'view-wishlist',
    'search',
    'select-category',
    'open-cart'
]);

const {
    reviews,
    isLoadingReviews,
    reviewsLoadError,
    loadReviews,
    updateReview,
    deleteReview
} = useBuyer();

onMounted(() => {
    loadReviews();
});

/*
|--------------------------------------------------------------------------
| Stats
|--------------------------------------------------------------------------
*/

const totalReviews = computed(() => reviews.value.length);

const averageRating = computed(() => {
    if (reviews.value.length === 0) {
        return 0;
    }

    const sum = reviews.value.reduce((total, review) => total + Number(review.rating || 0), 0);

    return sum / reviews.value.length;
});

const reviewsThisMonth = computed(() => {
    const now = new Date();

    return reviews.value.filter(review => {
        if (!review.createdAt) {
            return false;
        }

        const date = new Date(review.createdAt);

        return date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth();
    }).length;
});

/*
|--------------------------------------------------------------------------
| Filter + Sort + Search
|--------------------------------------------------------------------------
*/

const ratingFilter = ref('all');
const sortBy = ref('recent');
const searchQuery = ref('');
const page = ref(1);
const PER_PAGE = 5;

const filteredReviews = computed(() => {
    const search = searchQuery.value.trim().toLowerCase();

    let list = reviews.value.filter(review => {
        const matchesRating = ratingFilter.value === 'all' || review.rating === Number(ratingFilter.value);

        const matchesSearch =
            !search ||
            (review.productName || '').toLowerCase().includes(search) ||
            (review.comment || '').toLowerCase().includes(search);

        return matchesRating && matchesSearch;
    });

    list = [...list];

    if (sortBy.value === 'rating-desc') {
        list.sort((a, b) => b.rating - a.rating);
    } else if (sortBy.value === 'rating-asc') {
        list.sort((a, b) => a.rating - b.rating);
    } else {
        list.sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt));
    }

    return list;
});

const totalPages = computed(() => Math.max(1, Math.ceil(filteredReviews.value.length / PER_PAGE)));

const pagedReviews = computed(() => {
    const start = (page.value - 1) * PER_PAGE;

    return filteredReviews.value.slice(start, start + PER_PAGE);
});

const pageNumbers = computed(() => {
    const total = totalPages.value;
    const current = page.value;

    const nums = new Set([1, total, current - 1, current, current + 1]);

    return [...nums].filter(n => n >= 1 && n <= total).sort((a, b) => a - b);
});

function goToPage(n) {
    if (n < 1 || n > totalPages.value || n === page.value) {
        return;
    }

    page.value = n;
}

function resetPage() {
    page.value = 1;
}

/*
|--------------------------------------------------------------------------
| Edit / Delete
|--------------------------------------------------------------------------
*/

const editingId = ref(null);
const editForm = reactive({ rating: 0, comment: '' });
const savingEdit = ref(false);

function startEdit(review) {
    editingId.value = review.id;
    editForm.rating = review.rating;
    editForm.comment = review.comment || '';
}

function cancelEdit() {
    editingId.value = null;
}

async function saveEdit(review) {
    if (editForm.rating < 1) {
        return;
    }

    savingEdit.value = true;

    try {
        await updateReview(review.id, { rating: editForm.rating, comment: editForm.comment.trim() });
        editingId.value = null;
    } catch (err) {
        alert(err?.message || 'Could not save your changes.');
    } finally {
        savingEdit.value = false;
    }
}

async function removeReview(review) {
    const confirmed = window.confirm('Delete this review? This can\'t be undone.');

    if (!confirmed) {
        return;
    }

    try {
        await deleteReview(review.id);
    } catch (err) {
        alert(err?.message || 'Could not delete this review.');
    }
}

/*
|--------------------------------------------------------------------------
| Formatters
|--------------------------------------------------------------------------
*/

function formatDate(date) {
    if (!date) {
        return 'Unknown date';
    }

    return new Date(date).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
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
                <!-- SIDEBAR NAV -->
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
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-wishlist')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5" />
                            </svg>
                            Wishlist
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl bg-slate-100 text-[#0d9488] font-semibold transition-colors"
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

                <div class="flex-1 space-y-8 min-w-0">

                    <!-- Breadcrumb + Title -->
                    <div>
                        <nav class="flex items-center text-sm font-medium text-slate-400 mb-2">
                            <button
                                type="button"
                                class="hover:text-slate-600"
                                @click="emit('view-profile')"
                            >
                                My Account
                            </button>
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mx-2">
                                <path d="m9 18 6-6-6-6" />
                            </svg>
                            <span class="text-[#0d9488]">My Reviews</span>
                        </nav>
                        <h1 class="text-3xl font-bold text-slate-900 tracking-tight">My Reviews</h1>
                        <p class="text-slate-500 mt-1">
                            <template v-if="totalReviews > 0">You have shared {{ totalReviews }} {{ totalReviews === 1 ? 'review' : 'reviews' }} with the community</template>
                            <template v-else>Reviews you write for delivered orders will show up here</template>
                        </p>
                    </div>

                    <!-- Loading / Error -->
                    <div
                        v-if="isLoadingReviews"
                        class="empty-products"
                    >
                        <p>Loading your reviews&hellip;</p>
                    </div>

                    <div
                        v-else-if="reviewsLoadError"
                        class="empty-products"
                    >
                        <p>{{ reviewsLoadError }}</p>
                    </div>

                    <template v-else-if="totalReviews > 0">

                        <!-- Stats Overview -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="bg-white rounded-3xl p-6 border border-slate-100" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">
                                <div class="flex items-center gap-4 mb-2">
                                    <div class="w-10 h-10 rounded-2xl bg-teal-50 text-[#0d9488] flex items-center justify-center">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526" /><circle cx="12" cy="8" r="6" />
                                        </svg>
                                    </div>
                                    <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">Total Reviews</span>
                                </div>
                                <p class="text-3xl font-bold text-slate-900">{{ totalReviews }}</p>
                            </div>
                            <div class="bg-white rounded-3xl p-6 border border-slate-100" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">
                                <div class="flex items-center gap-4 mb-2">
                                    <div class="w-10 h-10 rounded-2xl bg-orange-50 text-orange-400 flex items-center justify-center">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" stroke="none">
                                            <path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" />
                                        </svg>
                                    </div>
                                    <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">Avg. Rating</span>
                                </div>
                                <div class="flex items-baseline gap-2">
                                    <p class="text-3xl font-bold text-slate-900">{{ averageRating.toFixed(1) }}</p>
                                    <p class="text-slate-400 text-sm">out of 5.0</p>
                                </div>
                            </div>
                            <div class="bg-white rounded-3xl p-6 border border-slate-100" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">
                                <div class="flex items-center gap-4 mb-2">
                                    <div class="w-10 h-10 rounded-2xl bg-blue-50 text-blue-500 flex items-center justify-center">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect width="18" height="18" x="3" y="4" rx="2" /><path d="M3 10h18" /><path d="M8 2v4" /><path d="M16 2v4" />
                                        </svg>
                                    </div>
                                    <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">This Month</span>
                                </div>
                                <p class="text-3xl font-bold text-slate-900">{{ reviewsThisMonth }}</p>
                            </div>
                        </div>

                        <!-- Filters -->
                        <div class="bg-white rounded-3xl p-6 border border-slate-100" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">
                            <div class="flex flex-col md:flex-row gap-4 items-center justify-between">
                                <div class="flex flex-wrap items-center gap-3 w-full md:w-auto">
                                    <div class="relative">
                                        <select
                                            v-model="ratingFilter"
                                            class="appearance-none bg-slate-50 border border-slate-200 rounded-2xl px-5 py-3 text-sm font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500/20 pr-10 min-w-[140px]"
                                            @change="resetPage"
                                        >
                                            <option value="all">All Ratings</option>
                                            <option value="5">5 Stars</option>
                                            <option value="4">4 Stars</option>
                                            <option value="3">3 Stars</option>
                                            <option value="2">2 Stars</option>
                                            <option value="1">1 Star</option>
                                        </select>
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                            <path d="m6 9 6 6 6-6" />
                                        </svg>
                                    </div>
                                    <div class="relative">
                                        <select
                                            v-model="sortBy"
                                            class="appearance-none bg-slate-50 border border-slate-200 rounded-2xl px-5 py-3 text-sm font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500/20 pr-10 min-w-[160px]"
                                            @change="resetPage"
                                        >
                                            <option value="recent">Most Recent</option>
                                            <option value="rating-desc">Highest Rating</option>
                                            <option value="rating-asc">Lowest Rating</option>
                                        </select>
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                            <path d="m6 9 6 6 6-6" />
                                        </svg>
                                    </div>
                                </div>
                                <div class="relative w-full md:w-80">
                                    <input
                                        v-model="searchQuery"
                                        type="text"
                                        placeholder="Search reviews..."
                                        class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-3 pl-12 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20"
                                        @input="resetPage"
                                    >
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                        <circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <!-- No results after filtering -->
                        <div
                            v-if="filteredReviews.length === 0"
                            class="empty-products"
                        >
                            <span class="empty-products-icon" aria-hidden="true">🔍</span>
                            <p>No reviews match your filters.</p>
                            <button
                                type="button"
                                class="clear-filters-button"
                                @click="ratingFilter = 'all'; sortBy = 'recent'; searchQuery = ''; resetPage();"
                            >
                                Clear Filters
                            </button>
                        </div>

                        <!-- Review List -->
                        <div
                            v-else
                            class="space-y-6"
                        >
                            <div
                                v-for="review in pagedReviews"
                                :key="review.id"
                                class="bg-white rounded-[2rem] border border-slate-100 p-8 flex flex-col md:flex-row gap-8"
                                style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                            >
                                <div class="w-full md:w-40 shrink-0">
                                    <div
                                        class="aspect-square rounded-3xl border border-slate-100 flex items-center justify-center p-4"
                                        :class="review.category ? 'accent-' + metaFor(review.category).accent : ''"
                                        :style="review.category ? 'background: var(--accent-bg, #f8fafc); color: var(--accent-fg, #cbd5e1);' : 'background: #f8fafc; color: #cbd5e1;'"
                                    >
                                        <img
                                            v-if="review.image"
                                            :src="review.image"
                                            :alt="review.productName"
                                            class="w-full h-full object-contain"
                                        >
                                        <span
                                            v-else-if="review.category"
                                            class="w-12 h-12"
                                            v-html="metaFor(review.category).icon"
                                        ></span>
                                        <svg v-else viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect width="18" height="18" x="3" y="3" rx="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21" />
                                        </svg>
                                    </div>
                                    <div class="mt-4 text-center md:text-left">
                                        <h4 class="font-bold text-slate-900 text-sm line-clamp-1">{{ review.productName }}</h4>
                                    </div>
                                </div>

                                <div class="flex-1 flex flex-col min-w-0">
                                    <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
                                        <div class="flex items-center gap-3">
                                            <div
                                                class="flex text-orange-400"
                                                :aria-label="`${review.rating} out of 5 stars`"
                                            >
                                                <svg
                                                    v-for="star in 5"
                                                    :key="star"
                                                    viewBox="0 0 24 24"
                                                    width="18"
                                                    height="18"
                                                    :fill="star <= review.rating ? 'currentColor' : 'none'"
                                                    stroke="currentColor"
                                                    stroke-width="2"
                                                >
                                                    <path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" />
                                                </svg>
                                            </div>
                                            <span class="text-sm text-slate-400 font-medium">
                                                {{ formatDate(review.createdAt) }}
                                                <template v-if="review.isEdited">&middot; edited</template>
                                            </span>
                                        </div>
                                        <div
                                            v-if="editingId !== review.id"
                                            class="flex items-center gap-2"
                                        >
                                            <button
                                                type="button"
                                                class="p-2 text-slate-400 hover:text-[#0d9488] transition-colors rounded-xl hover:bg-teal-50"
                                                title="Edit review"
                                                @click="startEdit(review)"
                                            >
                                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M13 21h8" /><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z" />
                                                </svg>
                                            </button>
                                            <button
                                                type="button"
                                                class="p-2 text-slate-400 hover:text-red-500 transition-colors rounded-xl hover:bg-red-50"
                                                title="Delete review"
                                                @click="removeReview(review)"
                                            >
                                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M3 6h18" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" /><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Edit Mode -->
                                    <div
                                        v-if="editingId === review.id"
                                        class="bg-slate-50 rounded-2xl p-5 space-y-4"
                                    >
                                        <div class="flex gap-1">
                                            <button
                                                v-for="star in 5"
                                                :key="star"
                                                type="button"
                                                class="text-2xl transition-colors"
                                                :class="star <= editForm.rating ? 'text-orange-400' : 'text-slate-200'"
                                                @click="editForm.rating = star"
                                            >
                                                &#9733;
                                            </button>
                                        </div>
                                        <textarea
                                            v-model="editForm.comment"
                                            rows="4"
                                            maxlength="2000"
                                            class="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20"
                                            placeholder="Update your written review (optional)"
                                        ></textarea>
                                        <div class="flex justify-end gap-3">
                                            <button
                                                type="button"
                                                class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-100 transition-colors"
                                                @click="cancelEdit"
                                            >
                                                Cancel
                                            </button>
                                            <button
                                                type="button"
                                                :disabled="savingEdit || editForm.rating < 1"
                                                class="px-5 py-2 rounded-xl text-sm font-bold text-white bg-[#0d9488] hover:bg-[#0f766e] disabled:opacity-60 disabled:cursor-not-allowed transition-colors"
                                                @click="saveEdit(review)"
                                            >
                                                {{ savingEdit ? 'Saving…' : 'Save Changes' }}
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Read Mode -->
                                    <p
                                        v-else
                                        class="text-slate-600 leading-relaxed"
                                    >
                                        {{ review.comment || 'No written comment was added.' }}
                                    </p>

                                    <div
                                        v-if="review.sellerResponse"
                                        class="mt-4 pt-4 border-t border-slate-50"
                                    >
                                        <span class="text-xs font-bold text-slate-400 uppercase tracking-widest block mb-1">Seller response</span>
                                        <p class="text-sm text-slate-600">{{ review.sellerResponse }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pagination -->
                        <div
                            v-if="totalPages > 1"
                            class="flex items-center justify-center gap-2 pt-4"
                        >
                            <button
                                type="button"
                                class="w-10 h-10 rounded-xl border border-slate-200 flex items-center justify-center text-slate-400 hover:bg-white hover:text-slate-900 transition-all disabled:opacity-40 disabled:cursor-not-allowed"
                                :disabled="page === 1"
                                @click="goToPage(page - 1)"
                            >
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m15 18-6-6 6-6" />
                                </svg>
                            </button>

                            <template
                                v-for="(n, idx) in pageNumbers"
                                :key="n"
                            >
                                <span
                                    v-if="idx > 0 && n - pageNumbers[idx - 1] > 1"
                                    class="text-slate-400 mx-1"
                                >&hellip;</span>
                                <button
                                    type="button"
                                    class="w-10 h-10 rounded-xl flex items-center justify-center font-bold text-sm transition-all"
                                    :class="n === page ? 'bg-[#0d9488] text-white shadow-lg shadow-teal-500/20' : 'border border-slate-200 text-slate-600 hover:bg-white hover:text-slate-900'"
                                    @click="goToPage(n)"
                                >
                                    {{ n }}
                                </button>
                            </template>

                            <button
                                type="button"
                                class="w-10 h-10 rounded-xl border border-slate-200 flex items-center justify-center text-slate-400 hover:bg-white hover:text-slate-900 transition-all disabled:opacity-40 disabled:cursor-not-allowed"
                                :disabled="page === totalPages"
                                @click="goToPage(page + 1)"
                            >
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m9 18 6-6-6-6" />
                                </svg>
                            </button>
                        </div>

                    </template>

                    <!-- Empty -->
                    <div
                        v-else
                        class="flex flex-col items-center justify-center py-20 text-center"
                    >
                        <div class="w-24 h-24 bg-slate-100 rounded-full flex items-center justify-center text-slate-300 mb-6">
                            <svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" />
                            </svg>
                        </div>
                        <h2 class="text-2xl font-bold text-slate-900">No reviews yet</h2>
                        <p class="text-slate-500 mt-2 max-w-xs">Once your orders are delivered, you can rate and review your purchases here.</p>
                        <button
                            type="button"
                            class="mt-8 px-8 py-4 bg-[#0d9488] text-white rounded-2xl font-bold hover:bg-[#0f766e] transition-all"
                            @click="emit('view-orders')"
                        >
                            View My Orders
                        </button>
                    </div>

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