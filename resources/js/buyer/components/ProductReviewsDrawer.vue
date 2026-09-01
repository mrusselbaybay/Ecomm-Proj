<script setup>
/*
|--------------------------------------------------------------------------
| ProductReviewsDrawer — read product reviews before buying
|--------------------------------------------------------------------------
|
| Opened from a cart line's "View reviews" button. Side drawer on desktop,
| bottom sheet on mobile. Backed by useProductReviews.js -> the public
| GET /api/products/{id}/reviews endpoint. Nothing is fetched until it
| opens, and only 5 rows load at a time ("Load more").
|
| Real data only:
|   - "Verified Purchase" shows ONLY when the row's order_item_id is set
|     (ProductController::transformReview -> verifiedPurchase)
|   - variant is shown only when the reviewed order item recorded one
|   - the "With photos" filter is disabled unless the summary reports any
|   - seller reply shows only when seller_response exists
|   - buyers cannot write a review here
|
| a11y: role="dialog" aria-modal, focus moved in on open and restored on
| close, Tab trapped, Escape closes, body scroll locked, motion reduced
| under prefers-reduced-motion.
*/
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useProductReviews } from '../composables/useProductReviews';
import { useToasts } from '../composables/useToasts';
import StarRating from './StarRating.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    product: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const {
    summary,
    items,
    activeFilter,
    isLoading,
    isLoadingMore,
    error,
    canLoadMore,
    open,
    setFilter,
    loadMore,
    retry,
} = useProductReviews();

const { error: toastError, info: toastInfo } = useToasts();

const panelRef = ref(null);
const closeBtnRef = ref(null);
let lastFocused = null;
let announcedLoaded = false;

const FILTERS = [
    { key: 'all', label: 'All' },
    { key: '5', label: '5★' },
    { key: '4', label: '4★' },
    { key: '3', label: '3★' },
    { key: '2', label: '2★' },
    { key: '1', label: '1★' },
    { key: 'images', label: 'With photos' },
];

const breakdownRows = computed(() => {
    const total = summary.value?.total || 0;

    return [5, 4, 3, 2, 1].map((star) => {
        const count = summary.value?.breakdown?.[star] ?? 0;

        return { star, count, pct: total > 0 ? Math.round((count / total) * 100) : 0 };
    });
});

const hasImages = computed(() => (summary.value?.with_images || 0) > 0);

watch(
    () => props.show,
    async (visible) => {
        if (visible && props.product?.id) {
            announcedLoaded = false;
            lastFocused = document.activeElement;
            document.body.style.overflow = 'hidden';
            open(props.product.id);
            await nextTick();
            closeBtnRef.value?.focus();
        } else {
            document.body.style.overflow = '';

            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus();
                lastFocused = null;
            }
        }
    },
);

// Announce the outcome once per open (the drawer content is not a live
// region — a toast keeps it out of the visual flow).
watch(isLoading, (loading) => {
    if (!props.show || loading || announcedLoaded) {
        return;
    }

    announcedLoaded = true;

    if (error.value) {
        toastError('Reviews could not be loaded.', {
            action: { label: 'Retry', handler: retry },
        });
    } else {
        toastInfo(
            (summary.value?.total || 0) > 0
                ? 'Reviews loaded.'
                : 'This product has no reviews yet.',
        );
    }
});

// Safety net: never leave the page scroll locked if this unmounts while open.
onBeforeUnmount(() => {
    document.body.style.overflow = '';
});

function close() {
    emit('close');
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        close();

        return;
    }

    if (event.key !== 'Tab') {
        return;
    }

    const focusable = panelRef.value?.querySelectorAll(
        'button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
    );

    if (!focusable || focusable.length === 0) {
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function formatDate(value) {
    if (!value) {
        return '';
    }

    return new Date(value).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}
</script>

<template>
    <transition name="drawer">
        <div
            v-if="show"
            class="reviews-overlay"
            @click.self="close"
            @keydown="onKeydown"
        >
            <div
                ref="panelRef"
                class="reviews-panel"
                role="dialog"
                aria-modal="true"
                aria-labelledby="reviews-drawer-title"
            >
                <header class="reviews-head">
                    <div class="min-w-0">
                        <p class="reviews-eyebrow">
                            Product reviews
                        </p>
                        <h2
                            id="reviews-drawer-title"
                            class="reviews-title"
                        >
                            {{ product?.name || 'Reviews' }}
                        </h2>
                    </div>

                    <button
                        ref="closeBtnRef"
                        type="button"
                        class="reviews-close"
                        aria-label="Close reviews"
                        @click="close"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            width="20"
                            height="20"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            aria-hidden="true"
                        >
                            <path d="M18 6 6 18M6 6l12 12" />
                        </svg>
                    </button>
                </header>

                <div class="reviews-body">
                    <!-- Summary -->
                    <section
                        v-if="isLoading && items.length === 0"
                        class="reviews-summary reviews-summary--skeleton"
                        aria-hidden="true"
                    >
                        <div class="sk sk-avg" />
                        <div class="sk-bars">
                            <div
                                v-for="n in 5"
                                :key="n"
                                class="sk sk-bar"
                            />
                        </div>
                    </section>

                    <section
                        v-else-if="summary && summary.total > 0"
                        class="reviews-summary"
                    >
                        <div class="reviews-avg">
                            <span class="reviews-avg-number">{{ (summary.average ?? 0).toFixed(1) }}</span>
                            <StarRating
                                :rating="summary.average"
                                :size="16"
                            />
                            <span class="reviews-avg-count">
                                {{ summary.total }} {{ summary.total === 1 ? 'review' : 'reviews' }}
                            </span>
                        </div>

                        <ul class="reviews-breakdown">
                            <li
                                v-for="row in breakdownRows"
                                :key="row.star"
                            >
                                <span class="reviews-breakdown-label">{{ row.star }}★</span>
                                <span class="reviews-breakdown-track">
                                    <span
                                        class="reviews-breakdown-fill"
                                        :style="{ width: row.pct + '%' }"
                                    />
                                </span>
                                <span class="reviews-breakdown-count">{{ row.count }}</span>
                            </li>
                        </ul>
                    </section>

                    <!-- Filters -->
                    <div
                        v-if="!(isLoading && items.length === 0) && (summary?.total || 0) > 0"
                        class="reviews-filters"
                        role="group"
                        aria-label="Filter reviews"
                    >
                        <button
                            v-for="filter in FILTERS"
                            :key="filter.key"
                            type="button"
                            class="reviews-filter"
                            :class="{ 'reviews-filter--active': activeFilter === filter.key }"
                            :disabled="filter.key === 'images' && !hasImages"
                            :aria-pressed="activeFilter === filter.key"
                            @click="setFilter(filter.key)"
                        >
                            {{ filter.label }}
                        </button>
                    </div>

                    <!-- List states -->
                    <div
                        v-if="isLoading && items.length === 0"
                        class="reviews-list"
                    >
                        <div
                            v-for="n in 3"
                            :key="n"
                            class="review-card"
                            aria-hidden="true"
                        >
                            <div class="sk sk-line sk-line--sm" />
                            <div class="sk sk-line" />
                            <div class="sk sk-line" />
                            <div class="sk sk-line sk-line--short" />
                        </div>
                    </div>

                    <div
                        v-else-if="error"
                        class="reviews-empty"
                    >
                        <p>{{ error }}</p>
                        <button
                            type="button"
                            class="reviews-retry"
                            @click="retry"
                        >
                            Try again
                        </button>
                    </div>

                    <div
                        v-else-if="items.length === 0"
                        class="reviews-empty"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            width="40"
                            height="40"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <path d="M12 17.75 5.828 21l1.179-6.874-5-4.867 6.9-1 3.086-6.253 3.086 6.253 6.9 1-5 4.867L18.172 21z" />
                        </svg>
                        <p v-if="activeFilter === 'all'">
                            This product has no reviews yet.
                        </p>
                        <p v-else>
                            No reviews match this filter.
                        </p>
                        <button
                            v-if="activeFilter !== 'all'"
                            type="button"
                            class="reviews-retry"
                            @click="setFilter('all')"
                        >
                            Show all reviews
                        </button>
                    </div>

                    <ul
                        v-else
                        class="reviews-list"
                    >
                        <li
                            v-for="review in items"
                            :key="review.id"
                            class="review-card"
                        >
                            <div class="review-card-head">
                                <div>
                                    <p class="review-author">
                                        {{ review.author }}
                                        <span
                                            v-if="review.verifiedPurchase"
                                            class="review-verified"
                                        >
                                            <svg
                                                viewBox="0 0 24 24"
                                                width="12"
                                                height="12"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="3"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                aria-hidden="true"
                                            >
                                                <path d="M20 6 9 17l-5-5" />
                                            </svg>
                                            Verified Purchase
                                        </span>
                                    </p>
                                    <p class="review-meta">
                                        {{ formatDate(review.createdAt) }}
                                        <template v-if="review.isEdited"> · edited</template>
                                        <template v-if="review.variant"> · {{ review.variant }}</template>
                                    </p>
                                </div>
                                <StarRating
                                    :rating="review.rating"
                                    :size="14"
                                />
                            </div>

                            <p
                                v-if="review.comment"
                                class="review-body"
                            >
                                {{ review.comment }}
                            </p>
                            <p
                                v-else
                                class="review-body review-body--muted"
                            >
                                No written comment.
                            </p>

                            <div
                                v-if="review.images && review.images.length"
                                class="review-images"
                            >
                                <img
                                    v-for="(img, idx) in review.images"
                                    :key="idx"
                                    :src="img"
                                    :alt="`Photo ${idx + 1} from ${review.author}'s review`"
                                    loading="lazy"
                                >
                            </div>

                            <div
                                v-if="review.sellerResponse"
                                class="review-response"
                            >
                                <p class="review-response-label">
                                    Seller response
                                    <template v-if="review.respondedAt"> · {{ formatDate(review.respondedAt) }}</template>
                                </p>
                                <p>{{ review.sellerResponse }}</p>
                            </div>
                        </li>
                    </ul>

                    <button
                        v-if="canLoadMore"
                        type="button"
                        class="reviews-more"
                        :disabled="isLoadingMore"
                        @click="loadMore"
                    >
                        {{ isLoadingMore ? 'Loading…' : 'Load more reviews' }}
                    </button>
                </div>
            </div>
        </div>
    </transition>
</template>

<style scoped>
.reviews-overlay {
    position: fixed;
    inset: 0;
    z-index: 1090;
    display: flex;
    justify-content: flex-end;
    background: rgba(15, 23, 42, 0.5);
}

.reviews-panel {
    display: flex;
    flex-direction: column;
    width: min(460px, 100%);
    height: 100%;
    background: #ffffff;
    box-shadow: -12px 0 40px -12px rgba(15, 23, 42, 0.35);
}

.reviews-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 20px;
    border-bottom: 1px solid #f1f5f9;
}

.reviews-eyebrow {
    margin: 0 0 2px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #94a3b8;
}

.reviews-title {
    margin: 0;
    font-size: 17px;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.3;
}

.reviews-close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    border: none;
    background: #f8fafc;
    color: #475569;
    border-radius: 10px;
    cursor: pointer;
}

.reviews-close:hover {
    background: #f1f5f9;
}

.reviews-body {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.reviews-summary {
    display: flex;
    gap: 20px;
    padding-bottom: 18px;
    border-bottom: 1px solid #f1f5f9;
}

.reviews-avg {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
}

.reviews-avg-number {
    font-size: 34px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1;
    font-variant-numeric: tabular-nums;
}

.reviews-avg-count {
    font-size: 12px;
    color: #64748b;
}

.reviews-breakdown {
    flex: 1;
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.reviews-breakdown li {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #64748b;
}

.reviews-breakdown-label {
    width: 24px;
    flex-shrink: 0;
    font-variant-numeric: tabular-nums;
}

.reviews-breakdown-track {
    flex: 1;
    height: 6px;
    background: #f1f5f9;
    border-radius: 999px;
    overflow: hidden;
}

.reviews-breakdown-fill {
    display: block;
    height: 100%;
    background: #f59e0b;
    border-radius: 999px;
}

.reviews-breakdown-count {
    width: 24px;
    text-align: right;
    flex-shrink: 0;
    font-variant-numeric: tabular-nums;
}

.reviews-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.reviews-filter {
    min-height: 34px;
    padding: 0 12px;
    border-radius: 999px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    font-size: 12.5px;
    font-weight: 600;
    color: #475569;
    cursor: pointer;
}

.reviews-filter:hover:not(:disabled) {
    border-color: #0d9488;
    color: #0f766e;
}

.reviews-filter--active {
    background: #0d9488;
    border-color: #0d9488;
    color: #ffffff;
}

.reviews-filter:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.reviews-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.review-card {
    border: 1px solid #f1f5f9;
    border-radius: 14px;
    padding: 14px;
}

.review-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
}

.review-author {
    margin: 0;
    font-size: 13.5px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
}

.review-verified {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 7px;
    border-radius: 999px;
    background: #ecfdf5;
    color: #047857;
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.02em;
}

.review-meta {
    margin: 2px 0 0;
    font-size: 12px;
    color: #94a3b8;
}

.review-body {
    margin: 0;
    font-size: 13.5px;
    line-height: 1.55;
    color: #334155;
    white-space: pre-line;
    overflow-wrap: anywhere;
}

.review-body--muted {
    color: #94a3b8;
    font-style: italic;
}

.review-images {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}

.review-images img {
    width: 68px;
    height: 68px;
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
}

.review-response {
    margin-top: 10px;
    padding: 10px 12px;
    background: #f8fafc;
    border-radius: 10px;
    font-size: 12.5px;
    color: #475569;
    line-height: 1.5;
}

.review-response-label {
    margin: 0 0 3px;
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #94a3b8;
}

.review-response p {
    margin: 0;
    overflow-wrap: anywhere;
}

.reviews-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding: 40px 16px;
    text-align: center;
    color: #64748b;
    font-size: 13.5px;
}

.reviews-retry,
.reviews-more {
    min-height: 40px;
    padding: 0 18px;
    border-radius: 10px;
    border: 1px solid #0d9488;
    background: #ffffff;
    color: #0f766e;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
}

.reviews-more {
    align-self: center;
}

.reviews-retry:hover,
.reviews-more:hover:not(:disabled) {
    background: #f0fdfa;
}

.reviews-more:disabled {
    opacity: 0.6;
    cursor: progress;
}

.reviews-filter:focus-visible,
.reviews-close:focus-visible,
.reviews-retry:focus-visible,
.reviews-more:focus-visible {
    outline: 2px solid #0d9488;
    outline-offset: 2px;
}

/* Skeletons */
.sk {
    background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 37%, #f1f5f9 63%);
    background-size: 400% 100%;
    animation: sk-shimmer 1.4s ease infinite;
    border-radius: 6px;
}

.sk-avg {
    width: 64px;
    height: 64px;
    border-radius: 12px;
    flex-shrink: 0;
}

.sk-bars {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.sk-bar {
    height: 10px;
}

.sk-line {
    height: 12px;
    margin-bottom: 8px;
}

.sk-line--sm {
    width: 40%;
}

.sk-line--short {
    width: 60%;
    margin-bottom: 0;
}

@keyframes sk-shimmer {
    0% { background-position: 100% 50%; }
    100% { background-position: 0 50%; }
}

/* Motion */
.drawer-enter-active,
.drawer-leave-active {
    transition: opacity 0.22s ease;
}

.drawer-enter-active .reviews-panel,
.drawer-leave-active .reviews-panel {
    transition: transform 0.26s cubic-bezier(0.22, 1, 0.36, 1);
}

.drawer-enter-from,
.drawer-leave-to {
    opacity: 0;
}

.drawer-enter-from .reviews-panel,
.drawer-leave-to .reviews-panel {
    transform: translateX(100%);
}

@media (max-width: 640px) {
    .reviews-overlay {
        align-items: flex-end;
    }

    .reviews-panel {
        width: 100%;
        height: auto;
        max-height: 88vh;
        border-radius: 20px 20px 0 0;
    }

    .drawer-enter-from .reviews-panel,
    .drawer-leave-to .reviews-panel {
        transform: translateY(100%);
    }
}

@media (prefers-reduced-motion: reduce) {
    .drawer-enter-active,
    .drawer-leave-active,
    .drawer-enter-active .reviews-panel,
    .drawer-leave-active .reviews-panel {
        transition: opacity 0.15s linear;
    }

    .drawer-enter-from .reviews-panel,
    .drawer-leave-to .reviews-panel {
        transform: none;
    }

    .sk {
        animation: none;
    }
}
</style>
