<script setup>
import { ref, watch, nextTick, onMounted, onUnmounted } from 'vue';
import StarRating from './StarRating.vue';
import { vReveal } from '../composables/useReveal';
import {
    metaFor,
    discountPercent,
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
        v-reveal
        class="offers-carousel"
        aria-labelledby="offers-title"
    >

        <div class="offers-panel">

            <p class="offers-eyebrow">Deals</p>

            <h2 id="offers-title">Marked down by sellers</h2>

            <p>{{ products.length }} in-stock {{ products.length === 1 ? 'item' : 'items' }} with a lower price than the seller&rsquo;s original. Discounts are set by each seller.</p>

            <button
                type="button"
                class="offers-cta"
                @click="handleShopDeals"
            >
                Browse by lowest price
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
                            alt=""
                            width="300"
                            height="300"
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
                            -{{ discountPercent(product) }}%
                        </span>
                    </div>

                    <div class="offer-card-info">

                        <span class="offer-card-label">
                            {{ product.brand || product.category }}
                        </span>

                        <span class="offer-card-name">
                            {{ product.name }}
                        </span>

                        <div
                            v-if="hasRating(product)"
                            class="offer-card-rating"
                        >
                            <StarRating
                                :rating="product.rating"
                                :count="product.reviewCount || 0"
                                :size="12"
                            />
                        </div>

                        <div class="offer-card-price-row">
                            <span class="offer-card-price">
                                {{ formatPrice(product.price) }}
                            </span>
                            <s
                                v-if="hasDiscount(product)"
                                class="offer-card-old-price"
                            ><span class="sr-only">Was </span>{{ formatPrice(product.oldPrice) }}</s>
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
