<script setup>
import { computed } from 'vue';
import { useBuyer } from '../composables/useBuyer';
import {
    metaFor,
    discountPercent,
    ratingStars,
    formatPrice
} from '../composables/useCategoryMeta';

const props = defineProps({
    product: {
        type: Object,
        required: true
    }
});

const emit = defineEmits([
    'view'
]);

const { addToCart, toggleFavorite, isFavorite } = useBuyer();

const favorited = computed(() => isFavorite(props.product.id));

const hasDiscount = computed(() => !!props.product.oldPrice);

function handleToggleFavorite() {
    toggleFavorite(props.product.id);
}

function handleQuickAdd() {
    // Variant products can't be quick-added blind — the buyer must pick
    // a real option combination first (see ProductDetails.vue), so send
    // them to the product page instead of guessing a variant here.
    if (props.product.hasVariants) {
        emit('view', props.product);
        return;
    }

    addToCart(props.product, null, 1);
}

function handleView() {
    emit('view', props.product);
}
</script>

<template>

    <article class="product-card">

        <div
            class="product-image"
            :class="'accent-' + metaFor(product.category).accent"
        >

            <span
                v-if="hasDiscount"
                class="product-discount-badge"
            >
                -{{ discountPercent(product) }}% OFF
            </span>

            <button
                type="button"
                class="product-favorite-button"
                :class="{ 'is-favorite': favorited }"
                :title="favorited ? 'Remove from favorites' : 'Add to favorites'"
                @click="handleToggleFavorite"
            >
                <svg viewBox="0 0 24 24" width="16" height="16" :fill="favorited ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="2">
                    <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.8z" />
                </svg>
            </button>

            <span
                class="product-image-icon"
                v-html="metaFor(product.category).icon"
            ></span>

            <div class="product-quick-add-wrap">
                <button
                    type="button"
                    class="product-quick-add-button"
                    @click="handleQuickAdd"
                >
                    Quick Add to Cart
                </button>
            </div>

        </div>

        <div class="product-info">

            <span class="product-category">
                {{ product.category }}
            </span>

            <h3>
                {{ product.name }}
            </h3>

            <div class="product-rating">
                <span class="product-rating-stars">
                    {{ ratingStars(product.rating) }}
                </span>
                <span
                    v-if="product.reviewCount"
                    class="product-rating-count"
                >
                    ({{ product.reviewCount }})
                </span>
                <span
                    v-else
                    class="product-rating-count"
                >
                    No reviews yet
                </span>
            </div>

            <div class="product-price-row">

                <div class="product-price-block">
                    <span class="product-price">
                        {{ formatPrice(product.price) }}
                    </span>
                    <span
                        v-if="hasDiscount"
                        class="product-old-price"
                    >
                        {{ formatPrice(product.oldPrice) }}
                    </span>
                </div>

                <button
                    type="button"
                    class="view-product-button"
                    title="View Product"
                    @click="handleView"
                >
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="9" cy="21" r="1" />
                        <circle cx="20" cy="21" r="1" />
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                    </svg>
                </button>

            </div>

        </div>

    </article>

</template>