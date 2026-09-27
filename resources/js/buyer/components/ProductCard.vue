<script setup>
import { computed, ref } from 'vue';
import { useBuyer } from '../composables/useBuyer';
import {
    metaFor,
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

// "Seller or brand name if available" — brand is the genuinely optional
// field; seller always has a real value (falls back to "NEXMART Seller"
// server-side, see ProductController::transform), so it's the sensible
// fallback rather than hiding attribution entirely.
const sellerOrBrand = computed(() => props.product.brand || props.product.seller || '');

// No product/seller logo asset exists anywhere in this backend today
// (checked app/Support + the API transform) — this stays gated behind a
// real field rather than a fabricated placeholder avatar, so the circle
// simply never renders until a real logo URL exists to show.
const sellerLogo = computed(() => props.product.sellerLogo || '');

// A "valid" discount needs a real, sane original price — not just a
// truthy oldPrice. Legacy/bad data could carry an oldPrice at or below
// the current price, which would render a nonsensical or negative
// percentage; guard against that explicitly rather than trusting truthy.
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

/*
|--------------------------------------------------------------------------
| Image / Real Gallery
|--------------------------------------------------------------------------
|
| The API's ProductController::transform() already returns the full
| normalized `images` array alongside the single `image` field — no
| second request needed for a real gallery. Falls back to the single
| `image`, then to the icon tile. Dots only ever appear when there is
| genuinely more than one usable photo (see the template).
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

const isAdding = ref(false);

function handleToggleFavorite() {
    toggleFavorite(props.product.id);
}

function handleAddToCart() {
    // Variant products can't be added blind — the buyer must pick a real
    // option combination first (see ProductDetails.vue), so send them to
    // the product page instead of guessing a variant here.
    if (props.product.hasVariants) {
        emit('view', props.product);

        return;
    }

    if (isAdding.value) {
        return;
    }

    isAdding.value = true;
    // addToCart surfaces its own success / out-of-stock / limit toast —
    // out-of-stock is handled entirely by that existing feedback path,
    // same as everywhere else this component is used.
    addToCart(props.product, null, 1);

    setTimeout(() => {
        isAdding.value = false;
    }, 400);
}

function handleView() {
    emit('view', props.product);
}
</script>

<template>

    <article class="product-card">

        <!-- Full-card hit target — first in the DOM so every real
             control below (dots, logo, favorite, add-to-cart) naturally
             paints above it and receives its own click; the plain visual
             text block opts out of pointer events so a click on the
             name/seller/description still falls through to this and
             opens the product. -->
        <button
            type="button"
            class="product-card-hitzone"
            :aria-label="`View ${product.name}`"
            @click="handleView"
        ></button>

        <img
            v-if="activeImage"
            class="product-card-photo"
            :src="activeImage"
            :alt="product.name"
            loading="lazy"
            @error="handleImageError"
        >
        <div
            v-else
            class="product-card-fallback"
            :class="'accent-' + metaFor(product.category).accent"
        >
            <span
                class="product-image-icon"
                v-html="metaFor(product.category).icon"
            ></span>
        </div>

        <div
            class="product-card-scrim"
            aria-hidden="true"
        ></div>

        <span
            v-if="sellerLogo"
            class="product-card-logo"
        >
            <img
                :src="sellerLogo"
                alt=""
            >
        </span>

        <span
            v-if="hasDiscount"
            class="product-discount-badge"
        >
            -{{ discountPercent }}% OFF
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

        <div
            v-if="images.length > 1"
            class="product-card-dots"
        >
            <button
                v-for="(image, index) in images"
                :key="index"
                type="button"
                :class="{ active: index === activeImageIndex }"
                :aria-label="`Photo ${index + 1} of ${images.length}`"
                @click="selectImage(index)"
            ></button>
        </div>

        <!-- Bottom-anchored text — shifts up slightly on hover/focus to
             make room for the price + Add to Cart row settling into
             place below it. Price itself never hides: it's always
             rendered in that row, before and after hover — only the
             button reveals. -->
        <div class="product-card-text">

            <div class="product-card-info">
                <h3 class="product-card-name">
                    {{ product.name }}
                </h3>
                <p
                    v-if="sellerOrBrand"
                    class="product-card-seller"
                >
                    {{ sellerOrBrand }}
                </p>
                <p
                    v-if="product.description"
                    class="product-card-desc"
                >
                    {{ product.description }}
                </p>
            </div>

            <div class="product-card-bottom-row">

                <div class="product-card-price-group">
                    <span class="product-card-price">
                        {{ formatPrice(product.price) }}
                    </span>
                    <span
                        v-if="hasDiscount"
                        class="product-card-old-price"
                    >
                        {{ formatPrice(product.oldPrice) }}
                    </span>
                </div>

                <button
                    type="button"
                    class="product-card-add-button"
                    :disabled="isAdding"
                    @click="handleAddToCart"
                >
                    {{ isAdding ? 'Adding…' : 'Add to Cart' }}
                </button>

            </div>

        </div>

    </article>

</template>
