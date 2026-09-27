<script setup>
import { ref, watch, nextTick, onMounted, onUnmounted } from 'vue';
import {
    metaFor,
    discountPercent,
    ratingStars,
    formatPrice
} from '../composables/useCategoryMeta';

const props = defineProps({
    products: {
        type: Array,
        default: () => []
    }
});

const emit = defineEmits([
    'view-product',
    'shop-deals'
]);

/*
|--------------------------------------------------------------------------
| Card Data
|--------------------------------------------------------------------------
|
| A "valid" discount needs a real, sane original price — not just a
| truthy oldPrice (legacy data could carry an oldPrice at or below the
| current price). Rating only renders when the product actually has one;
| unlike ProductCard.vue's compact grid card, this carousel card omits
| the row entirely rather than showing "No reviews yet" filler, since a
| dense horizontal strip has less room for a null-state line per card.
|
*/
const PLACEHOLDER_IMAGE = '/images/product-placeholder.svg';
const failedImages = ref(new Set());

function cardImage(product) {
    const src = product.image;

    if (!src || src === PLACEHOLDER_IMAGE || failedImages.value.has(product.id)) {
        return '';
    }

    return src;
}

function handleImageError(product) {
    failedImages.value = new Set(failedImages.value).add(product.id);
}

function hasDiscount(product) {
    const oldPrice = Number(product.oldPrice);
    const price = Number(product.price);

    return Number.isFinite(oldPrice) && oldPrice > price;
}

function hasRating(product) {
    return typeof product.rating === 'number';
}

function handleView(product) {
    emit('view-product', product);
}

function handleShopDeals() {
    emit('shop-deals');
}

/*
|--------------------------------------------------------------------------
| Carousel Scroll
|--------------------------------------------------------------------------
|
| Native horizontal scroll is the single source of truth for position —
| nothing here animates the track separately while also touching
| scrollLeft. Prev/Next call scrollBy() on the same real element;
| disabled state is read back from its real scroll position afterward,
| not assumed from the click.
|
*/
const track = ref(null);
const canScrollPrev = ref(false);
const canScrollNext = ref(false);

function updateScrollState() {
    const el = track.value;

    if (!el) {
        return;
    }

    canScrollPrev.value = el.scrollLeft > 4;
    canScrollNext.value = el.scrollLeft < el.scrollWidth - el.clientWidth - 4;
}

function scrollByCards(direction) {
    const el = track.value;

    if (!el) {
        return;
    }

    const card = el.querySelector('.offer-card');
    const step = card ? card.getBoundingClientRect().width + 16 : 220;

    el.scrollBy({ left: direction * step, behavior: 'smooth' });
}

watch(() => props.products, () => {
    nextTick(updateScrollState);
});

onMounted(() => {
    nextTick(updateScrollState);
    window.addEventListener('resize', updateScrollState);
});

onUnmounted(() => {
    window.removeEventListener('resize', updateScrollState);
});
</script>

<template>

    <section
        v-if="products.length > 0"
        class="offers-carousel"
    >

        <div class="offers-panel">

            <span
                class="offers-icon"
                aria-hidden="true"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z" />
                    <circle cx="7.5" cy="7.5" r="1.5" />
                </svg>
            </span>

            <h2>Deals worth checking out</h2>

            <p>Real markdowns on in-stock favorites from verified local sellers.</p>

            <button
                type="button"
                class="offers-cta"
                @click="handleShopDeals"
            >
                Browse All Products
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14" /><path d="m13 6 6 6-6 6" /></svg>
            </button>

        </div>

        <div class="offers-carousel-viewport">

            <button
                type="button"
                class="offers-nav prev"
                aria-label="Previous deals"
                :disabled="!canScrollPrev"
                @click="scrollByCards(-1)"
            >
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
            </button>

            <div
                ref="track"
                class="offers-track"
                @scroll="updateScrollState"
            >
                <button
                    v-for="product in products"
                    :key="product.id"
                    type="button"
                    class="offer-card"
                    @click="handleView(product)"
                >

                    <div
                        class="offer-card-image"
                        :class="'accent-' + metaFor(product.category).accent"
                    >
                        <img
                            v-if="cardImage(product)"
                            :src="cardImage(product)"
                            :alt="product.name"
                            loading="lazy"
                            @error="handleImageError(product)"
                        >
                        <span
                            v-else
                            class="product-image-icon"
                            v-html="metaFor(product.category).icon"
                        ></span>
                        <span
                            v-if="hasDiscount(product)"
                            class="offer-card-badge"
                        >
                            -{{ discountPercent(product) }}% OFF
                        </span>
                    </div>

                    <div class="offer-card-info">

                        <span class="offer-card-label">
                            {{ product.brand || product.category }}
                        </span>

                        <h3 class="offer-card-name">
                            {{ product.name }}
                        </h3>

                        <div
                            v-if="hasRating(product)"
                            class="offer-card-rating"
                        >
                            <span class="product-rating-stars">{{ ratingStars(product.rating) }}</span>
                            <span v-if="product.reviewCount">({{ product.reviewCount }})</span>
                        </div>

                        <div class="offer-card-price-row">
                            <span class="offer-card-price">
                                {{ formatPrice(product.price) }}
                            </span>
                            <span
                                v-if="hasDiscount(product)"
                                class="offer-card-old-price"
                            >
                                {{ formatPrice(product.oldPrice) }}
                            </span>
                        </div>

                    </div>

                </button>
            </div>

            <button
                type="button"
                class="offers-nav next"
                aria-label="Next deals"
                :disabled="!canScrollNext"
                @click="scrollByCards(1)"
            >
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6" /></svg>
            </button>

        </div>

    </section>

</template>
