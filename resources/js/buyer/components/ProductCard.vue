<script setup>
import { computed, ref, onUnmounted } from 'vue';
import StarRating from './StarRating.vue';
import { useBuyer } from '../composables/useBuyer';
import {
    metaFor,
    formatPrice
} from '../composables/useCategoryMeta';

const props = defineProps({
    product: {
        type: Object,
        required: true
    },
    // Rails pass false so above-the-fold cards aren't lazy-loaded.
    lazy: {
        type: Boolean,
        default: true
    },
    // Optional one-line, category-specific detail built from real product
    // data by the parent (e.g. "Cat · Dry Food", "Sizes S–XL").
    detail: {
        type: String,
        default: ''
    }
});

const emit = defineEmits([
    'view'
]);

const { addToCart, toggleFavorite, isFavorite } = useBuyer();

const favorited = computed(() => isFavorite(props.product.id));

// Brand is the genuinely optional field; seller always has a real value
// (ProductController::transform falls back to a generic seller label), so
// it's the sensible fallback rather than hiding attribution entirely.
const sellerOrBrand = computed(() => props.product.brand || props.product.seller || '');

// A "valid" discount needs a real, sane original price — legacy data can
// carry an oldPrice at or below the current price.
const hasDiscount = computed(() => {
    const oldPrice = Number(props.product.oldPrice);
    const price = Number(props.product.price);

    return Number.isFinite(oldPrice) && oldPrice > price;
});

const discountPercent = computed(() => {
    if (!hasDiscount.value) {
        return 0;
    }

    return Math.round((1 - props.product.price / props.product.oldPrice) * 100);
});

// Stock messaging only from the real `stock` field. Variant products keep
// per-variant stock, so the product-level figure isn't trusted for a
// "only N left" claim on them.
const stock = computed(() => {
    const value = Number(props.product.stock);

    return Number.isFinite(value) ? value : null;
});

const isOutOfStock = computed(() => stock.value === 0);

const lowStock = computed(() =>
    !props.product.hasVariants && stock.value !== null && stock.value > 0 && stock.value <= 5
);

const hasRating = computed(() => typeof props.product.rating === 'number');

/*
|--------------------------------------------------------------------------
| Image / Real Gallery
|--------------------------------------------------------------------------
|
| ProductController::transform() returns the full normalized `images` array
| alongside `image`. Falls back to the single `image`, then to the category
| icon tile. Dots only appear when there is genuinely more than one photo.
|
*/
const PLACEHOLDER_IMAGE = '/images/product-placeholder.svg';

const images = computed(() => {
    const gallery = (props.product.images || []).filter(
        src => src && src !== PLACEHOLDER_IMAGE
    );

    if (gallery.length > 0) {
        return gallery;
    }

    const single = props.product.image;

    return single && single !== PLACEHOLDER_IMAGE ? [single] : [];
});

const activeImageIndex = ref(0);
const imageFailed = ref(false);

const activeImage = computed(() => {
    if (imageFailed.value) {
        return '';
    }

    return images.value[activeImageIndex.value] || '';
});

function handleImageError() {
    imageFailed.value = true;
}

function selectImage(index) {
    activeImageIndex.value = index;
    imageFailed.value = false;
}

/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/

const addState = ref('idle'); // idle | added
const heartPopped = ref(false);
let addTimer = null;
let heartTimer = null;

const addLabel = computed(() => {
    if (isOutOfStock.value) {
        return 'Out of stock';
    }

    if (props.product.hasVariants) {
        return 'Choose options';
    }

    return addState.value === 'added' ? 'Added' : 'Add to cart';
});

function handleToggleFavorite() {
    toggleFavorite(props.product.id);

    heartPopped.value = false;
    clearTimeout(heartTimer);
    requestAnimationFrame(() => {
        heartPopped.value = true;
        heartTimer = setTimeout(() => {
            heartPopped.value = false;
        }, 400);
    });
}

function handleAddToCart() {
    // Variant products can't be added blind — the buyer picks a real option
    // combination on the product page first.
    if (props.product.hasVariants) {
        emit('view', props.product);

        return;
    }

    // addToCart raises its own success / out-of-stock / limit toast.
    const result = addToCart(props.product, null, 1);

    if (result?.ok) {
        addState.value = 'added';
        clearTimeout(addTimer);
        addTimer = setTimeout(() => {
            addState.value = 'idle';
        }, 1600);
    }
}

function handleView() {
    emit('view', props.product);
}

onUnmounted(() => {
    clearTimeout(addTimer);
    clearTimeout(heartTimer);
});
</script>

<template>

    <article
        class="pcard"
        :class="{ 'is-out': isOutOfStock }"
    >

        <div
            class="pcard-media"
            :class="'accent-' + metaFor(product.category).accent"
        >
            <img
                v-if="activeImage"
                class="pcard-img"
                :src="activeImage"
                :alt="product.name"
                :loading="lazy ? 'lazy' : 'eager'"
                decoding="async"
                width="400"
                height="400"
                @error="handleImageError"
            >
            <span
                v-else
                class="product-image-icon"
                aria-hidden="true"
                v-html="metaFor(product.category).icon"
            ></span>

            <div class="pcard-badges">
                <span
                    v-if="hasDiscount"
                    class="badge badge-deal"
                >-{{ discountPercent }}%</span>
                <span
                    v-if="isOutOfStock"
                    class="badge badge-neutral"
                >Sold out</span>
            </div>

            <button
                type="button"
                class="pcard-fav"
                :class="{ 'is-on': favorited, 'is-popped': heartPopped }"
                :aria-pressed="favorited"
                :aria-label="favorited ? `Remove ${product.name} from wishlist` : `Save ${product.name} to wishlist`"
                @click="handleToggleFavorite"
            >
                <svg viewBox="0 0 24 24" width="18" height="18" :fill="favorited ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 20.5s-7.5-4.6-9.2-9.4C1.7 7.9 3.9 4.5 7.4 4.5c2 0 3.5 1.1 4.6 2.7 1.1-1.6 2.6-2.7 4.6-2.7 3.5 0 5.7 3.4 4.6 6.6-1.7 4.8-9.2 9.4-9.2 9.4Z" />
                </svg>
            </button>

            <div
                v-if="images.length > 1"
                class="pcard-dots"
            >
                <button
                    v-for="(image, index) in images.slice(0, 5)"
                    :key="index"
                    type="button"
                    :class="{ 'is-active': index === activeImageIndex }"
                    :aria-label="`Show photo ${index + 1} of ${images.length}`"
                    :aria-pressed="index === activeImageIndex"
                    @click="selectImage(index)"
                ></button>
            </div>
        </div>

        <div class="pcard-body">

            <p
                v-if="sellerOrBrand"
                class="pcard-seller"
            >
                {{ sellerOrBrand }}
            </p>

            <h3 class="pcard-name">
                <a
                    :href="`#product-${product.id}`"
                    class="pcard-link"
                    @click.prevent="handleView"
                >{{ product.name }}</a>
            </h3>

            <p
                v-if="detail"
                class="pcard-detail"
            >
                {{ detail }}
            </p>

            <div class="pcard-rating">
                <StarRating
                    v-if="hasRating"
                    :rating="product.rating"
                    :count="product.reviewCount || 0"
                    :size="13"
                    show-value
                />
                <span
                    v-else
                    class="pcard-rating-empty"
                >No reviews yet</span>
            </div>

            <div class="pcard-price-row">
                <span class="pcard-price">{{ formatPrice(product.price) }}</span>
                <s
                    v-if="hasDiscount"
                    class="pcard-old-price"
                ><span class="sr-only">Was </span>{{ formatPrice(product.oldPrice) }}</s>
            </div>

            <p
                v-if="lowStock"
                class="pcard-stock"
            >
                Only {{ stock }} left
            </p>

            <button
                type="button"
                class="pcard-add"
                :class="{ 'is-added': addState === 'added', 'is-options': product.hasVariants }"
                :disabled="isOutOfStock"
                @click="handleAddToCart"
            >
                <svg
                    v-if="addState === 'added'"
                    viewBox="0 0 24 24"
                    width="16"
                    height="16"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                ><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                <svg
                    v-else-if="!isOutOfStock && !product.hasVariants"
                    viewBox="0 0 24 24"
                    width="16"
                    height="16"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    aria-hidden="true"
                ><path d="M12 5v14M5 12h14" /></svg>
                <span>{{ addLabel }}</span>
                <span class="sr-only">: {{ product.name }}</span>
            </button>

        </div>

    </article>

</template>
