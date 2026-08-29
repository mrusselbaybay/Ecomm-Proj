<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useBuyer } from '../composables/useBuyer';
import { useBuyerChat } from '../composables/useBuyerChat';
import {
    metaFor,
    discountPercent as sharedDiscountPercent,
    ratingStars as sharedRatingStars,
    formatPrice as sharedFormatPrice
} from '../composables/useCategoryMeta';
import Footer from './Footer.vue';
import Header from './Header.vue';
import ProductCard from './ProductCard.vue';

const props = defineProps({
    product: {
        type: Object,
        default: null
    },
    relatedProducts: {
        type: Array,
        default: () => []
    }
});

const emit = defineEmits([
    'back',
    'buy-now',
    'select-product',
    'search',
    'select-category',
    'open-cart',
    'view-profile',
    'browse-all',
    'browse-categories'
]);

const { addToCart, toggleFavorite, isFavorite } = useBuyer();

const quantity = ref(1);
const activeTab = ref('description');
const selectedImageIndex = ref(0);

/*
|--------------------------------------------------------------------------
| Variants
|--------------------------------------------------------------------------
|
| Real option/variant data from the backend (App\Http\Controllers\
| ProductController@transform), not a fabricated per-category list.
| selectedOptionValues tracks one chosen value per option (e.g.
| { Color: 'Black', Size: 'Large' }); selectedVariant resolves once every
| option has a value picked, by matching against product.variants'
| option_values exactly.
|--------------------------------------------------------------------------
*/

const hasVariants = computed(() => !!props.product?.hasVariants);

const productOptions = computed(() => props.product?.options || []);

const selectedOptionValues = ref({});

// Reset the selection whenever a different product is shown, so leftover
// selections from a previous product's options never leak in.
watch(
    () => props.product?.id,
    () => {
        selectedOptionValues.value = {};
        selectedImageIndex.value = 0;
        quantity.value = 1;
    },
);

function selectOptionValue(optionName, value) {
    selectedOptionValues.value = {
        ...selectedOptionValues.value,
        [optionName]: value,
    };
}

// A value is disabled if no variant matching everything currently
// selected plus this value exists, or every variant matching it is
// unavailable/out of stock — same rule the task requires for the buyer
// picker, computed from real variant rows rather than assumed.
function isOptionValueDisabled(optionName, value) {
    const candidate = { ...selectedOptionValues.value, [optionName]: value };

    return !(props.product?.variants || []).some((v) => {
        return Object.entries(candidate).every(
            ([k, val]) => v.option_values?.[k] === val,
        ) && v.status === 'active' && v.stock > 0;
    });
}

const allOptionsSelected = computed(() => {
    return productOptions.value.length > 0 &&
        productOptions.value.every((opt) => !!selectedOptionValues.value[opt.name]);
});

const selectedVariant = computed(() => {
    if (!allOptionsSelected.value) {
        return null;
    }

    return (props.product?.variants || []).find((v) => {
        return Object.entries(selectedOptionValues.value).every(
            ([k, val]) => v.option_values?.[k] === val,
        );
    }) || null;
});

const selectedVariantUnavailable = computed(() => {
    return !!selectedVariant.value &&
        (selectedVariant.value.status !== 'active' || selectedVariant.value.stock <= 0);
});

function variantLabel(variant) {
    return Object.entries(variant?.option_values || {})
        .map(([name, value]) => `${name}: ${value}`)
        .join(', ');
}

/*
|--------------------------------------------------------------------------
| Category icon / accent color
|--------------------------------------------------------------------------
|
| Uses the same metaFor() map as Dashboard.vue's category cards and product
| grid (imported from the shared composable), so the icon/color shown here
| for a given category is always identical to what's shown elsewhere.
|--------------------------------------------------------------------------
*/

function accentClassFor(category) {
    return 'accent-' + metaFor(category).accent;
}

const accentClass = computed(() => {
    return props.product ? accentClassFor(props.product.category) : 'accent-slate';
});

/*
|--------------------------------------------------------------------------
| Pricing
|--------------------------------------------------------------------------
|
| Uses the same discountPercent()/formatPrice() helpers Dashboard.vue's
| product cards use (via the shared composable), so prices/discounts are
| formatted identically everywhere.
|--------------------------------------------------------------------------
*/

const formattedPrice = computed(() => {
    if (!props.product) {
        return '';
    }

    const price = selectedVariant.value ? selectedVariant.value.price : props.product.price;

    return sharedFormatPrice(price);
});

const hasDiscount = computed(() => {
    // A variant's own price is an override, not a discount off the
    // product's compare-at price — only show the "was" price when no
    // variant-specific price is in effect.
    if (selectedVariant.value) {
        return false;
    }

    return !!props.product?.oldPrice &&
        Number(props.product.oldPrice) > Number(props.product.price);
});

const formattedOldPrice = computed(() => {
    return hasDiscount.value ? sharedFormatPrice(props.product.oldPrice) : '';
});

const discountPercent = computed(() => {
    return props.product ? sharedDiscountPercent(props.product) : 0;
});

const savingsAmount = computed(() => {
    if (!hasDiscount.value) {
        return '';
    }

    return sharedFormatPrice(Number(props.product.oldPrice) - Number(props.product.price));
});

/*
|--------------------------------------------------------------------------
| Gallery
|--------------------------------------------------------------------------
|
| Supports a product.images array when present (multiple angles/thumbnails).
| Falls back to the single product.image/imageUrl field, and to no image
| at all if neither exists (the existing .product-details-image placeholder
| already handles that state).
|
*/

const productImage = computed(() => {
    return selectedVariant.value?.image?.url ||
        props.product?.image ||
        props.product?.imageUrl ||
        '';
});

const galleryImages = computed(() => {
    if (selectedVariant.value?.image?.url) {
        return [selectedVariant.value.image.url];
    }

    if (Array.isArray(props.product?.images) && props.product.images.length > 0) {
        return props.product.images;
    }

    return productImage.value ? [productImage.value] : [];
});

const hasGallery = computed(() => {
    return galleryImages.value.length > 1;
});

const activeImage = computed(() => {
    return galleryImages.value[selectedImageIndex.value] || galleryImages.value[0] || '';
});

function selectImage(index) {
    selectedImageIndex.value = index;
}

/*
|--------------------------------------------------------------------------
| Optional presentation fields
|--------------------------------------------------------------------------
|
| These only render when the product actually carries the field. We don't
| fabricate ratings, stock counts, specs, or reviews that aren't in the data.
|
*/

const hasRating = computed(() => {
    return typeof props.product?.rating === 'number';
});

const ratingStars = computed(() => {
    return hasRating.value ? sharedRatingStars(props.product.rating) : '';
});

const hasStock = computed(() => {
    if (selectedVariant.value) {
        return true;
    }

    return typeof props.product?.stock === 'number';
});

const inStock = computed(() => {
    if (selectedVariant.value) {
        return selectedVariant.value.status === 'active' && selectedVariant.value.stock > 0;
    }

    if (hasVariants.value) {
        // No variant fully selected yet — can't claim in-stock either way.
        return null;
    }

    return hasStock.value ? props.product.stock > 0 : null;
});

const availableStock = computed(() => {
    if (selectedVariant.value) {
        return selectedVariant.value.stock;
    }

    return hasStock.value ? props.product.stock : null;
});

const hasSpecifications = computed(() => {
    return !!props.product?.specifications &&
        Object.keys(props.product.specifications).length > 0;
});

const hasReviews = computed(() => {
    return Array.isArray(props.product?.reviews) &&
        props.product.reviews.length > 0;
});

const favorited = computed(() => {
    return props.product ? isFavorite(props.product.id) : false;
});

/*
|--------------------------------------------------------------------------
| Quantity
|--------------------------------------------------------------------------
*/

function increaseQuantity() {
    // Client-side convenience only — the real limit is enforced
    // server-side at checkout (CheckoutService locks and re-checks the
    // actual row).
    if (availableStock.value !== null && quantity.value >= availableStock.value) {
        return;
    }

    quantity.value++;
}

function decreaseQuantity() {
    if (quantity.value > 1) {
        quantity.value--;
    }
}

/*
|--------------------------------------------------------------------------
| Favorite
|--------------------------------------------------------------------------
*/

function handleToggleFavorite() {
    if (!props.product) {
        return;
    }

    toggleFavorite(props.product.id);
}

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

function validateSelection() {
    if (!props.product) {
        return false;
    }

    if (hasVariants.value) {
        if (!allOptionsSelected.value) {
            alert('Please select an option for every variant before continuing.');

            return false;
        }

        if (!selectedVariant.value || selectedVariantUnavailable.value) {
            alert('That combination is currently unavailable.');

            return false;
        }
    }

    if (inStock.value === false) {
        alert('This product is currently out of stock.');

        return false;
    }

    if (availableStock.value !== null && quantity.value > availableStock.value) {
        alert(`Only ${availableStock.value} left in stock.`);

        return false;
    }

    return true;
}

/*
|--------------------------------------------------------------------------
| Add To Cart
|--------------------------------------------------------------------------
*/

function handleAddToCart() {
    if (!validateSelection()) {
        return;
    }

    addToCart(props.product, selectedVariant.value, quantity.value);

    const variantLine = selectedVariant.value
        ? `\n${variantLabel(selectedVariant.value)}`
        : '';

    alert(
        `${props.product.name} added to cart!\n` +
        `Quantity: ${quantity.value}${variantLine}`
    );
}

/*
|--------------------------------------------------------------------------
| Buy Now
|--------------------------------------------------------------------------
*/

function handleBuyNow() {
    if (!validateSelection()) {
        return;
    }

    emit('buy-now', {
        product: props.product,
        variant: selectedVariant.value,
        quantity: quantity.value
    });
}

/*
|--------------------------------------------------------------------------
| Back
|--------------------------------------------------------------------------
*/

function goBack() {
    emit('back');
}

/*
|--------------------------------------------------------------------------
| Message Seller
|--------------------------------------------------------------------------
|
| Opens an inline composer for the first message, then hands off to
| useBuyerChat.startConversation() — which finds/creates the buyer<->seller
| thread for this product's seller and pops the messaging popup open on it.
|
*/

const { startConversation } = useBuyerChat();

const messageOpen = ref(false);
const messageDraft = ref('');
const messageSending = ref(false);
const messageError = ref('');
const messageInput = ref(null);

function toggleMessageComposer() {
    messageOpen.value = !messageOpen.value;
    messageError.value = '';

    if (messageOpen.value) {
        nextTick(() => messageInput.value?.focus());
    }
}

async function sendSellerMessage() {
    const body = messageDraft.value.trim();

    if (!body || !props.product?.seller_id) {
        return;
    }

    messageSending.value = true;
    messageError.value = '';

    try {
        await startConversation({
            sellerId: props.product.seller_id,
            productId: props.product.id,
            body
        });

        messageDraft.value = '';
        messageOpen.value = false;
    } catch (err) {
        messageError.value = err?.message || 'Could not send your message. Please sign in and try again.';
    } finally {
        messageSending.value = false;
    }
}

/*
|--------------------------------------------------------------------------
| Header relay
|--------------------------------------------------------------------------
|
| The embedded Header has no products/dashboard state of its own, so a
| search or category click here has to bubble all the way up to Dashboard
| (which does the actual navigation + filtering), rather than being
| handled locally.
|--------------------------------------------------------------------------
*/

function handleHeaderSearch(query) {
    emit('search', query);
}

function handleHeaderSelectCategory(category) {
    emit('select-category', category);
}

/*
|--------------------------------------------------------------------------
| Related products
|--------------------------------------------------------------------------
|
| Rendered using the same markup/classes as the existing product grid
| (.product-card, .product-image, .product-price-row, etc.) so it matches
| the homepage cards exactly instead of introducing a second card style.
|
*/

/*
|--------------------------------------------------------------------------
| Related products
|--------------------------------------------------------------------------
|
| Rendering itself (pricing, favorites, quick-add, icons) is handled by
| ProductCard.vue — the same component Dashboard.vue's product grid uses.
| This page only needs to know what happens when one is clicked.
|
*/

function selectRelatedProduct(item) {
    emit('select-product', item);
}
</script>

<template>

    <div class="buyer-page">

        <Header
            :active-category="product ? product.category : ''"
            @select-category="handleHeaderSelectCategory"
            @cart-click="emit('open-cart')"
            @account-click="emit('view-profile')"
            @logo-click="goBack"
            @search="handleHeaderSearch"
        />

        <div
            v-if="product"
            class="product-details-page"
        >

        <!-- ============================================================ -->
        <!-- BREADCRUMB -->
        <!-- ============================================================ -->

        <nav class="product-breadcrumb">
            <button
                type="button"
                class="breadcrumb-link"
                @click="goBack"
            >
                Home
            </button>
            <span class="breadcrumb-separator">/</span>
            <span class="breadcrumb-current">
                {{ product.category }}
            </span>
            <span class="breadcrumb-separator">/</span>
            <span class="breadcrumb-current breadcrumb-current--active">
                {{ product.name }}
            </span>
        </nav>

        <!-- ============================================================ -->
        <!-- PRODUCT DETAILS -->
        <!-- ============================================================ -->

        <div class="product-details-card">

            <!-- Product Image -->
            <div>

                <div
                    class="product-details-image"
                    :class="accentClass"
                >
                    <img
                        v-if="activeImage"
                        :src="activeImage"
                        :alt="product.name"
                    >
                    <span
                        v-else
                        class="product-image-icon product-image-icon--lg"
                        v-html="metaFor(product.category).icon"
                    ></span>
                    <span
                        v-if="hasDiscount"
                        class="product-discount-badge"
                    >
                        -{{ discountPercent }}% OFF
                    </span>
                </div>

                <div
                    v-if="hasGallery"
                    class="product-thumbnails"
                >
                    <button
                        v-for="(image, index) in galleryImages"
                        :key="index"
                        type="button"
                        class="product-thumbnail"
                        :class="{ active: index === selectedImageIndex }"
                        @click="selectImage(index)"
                    >
                        <img
                            :src="image"
                            :alt="`${product.name} thumbnail ${index + 1}`"
                        >
                    </button>
                </div>

            </div>

            <!-- Product Information -->
            <div class="product-details-info">

                <!-- Category -->
                <span class="product-category">
                    {{ product.category }}
                </span>

                <!-- Product Name -->
                <h1>
                    {{ product.name }}
                </h1>

                <!-- Rating / Stock -->
                <div
                    v-if="hasRating || hasStock"
                    class="product-rating"
                >
                    <span
                        v-if="hasRating"
                        class="product-rating-stars"
                    >
                        {{ ratingStars }}
                    </span>
                    <span
                        v-if="hasRating"
                        class="product-rating-count"
                    >
                        {{ product.rating.toFixed(1) }}
                        <template v-if="product.reviewCount">
                            ({{ product.reviewCount }} Reviews)
                        </template>
                    </span>
                    <span
                        v-if="hasStock"
                        class="product-stock"
                        :class="inStock ? 'product-stock--in' : 'product-stock--out'"
                    >
                        {{ inStock ? 'In Stock' : 'Out of Stock' }}
                    </span>
                </div>

                <!-- Price -->
                <p class="product-details-price">
                    {{ formattedPrice }}
                    <span
                        v-if="hasDiscount"
                        class="product-old-price"
                    >
                        {{ formattedOldPrice }}
                    </span>
                    <span
                        v-if="hasDiscount"
                        class="product-savings"
                    >
                        Save {{ savingsAmount }}
                    </span>
                </p>

                <!-- Description -->
                <p class="product-description">
                    {{ product.description || 'Select your preferred variation and quantity before adding this product to your cart or purchasing it immediately.' }}
                </p>

                <!-- ==================================================== -->
                <!-- VARIANTS -->
                <!-- ==================================================== -->

                <template v-if="hasVariants">
                    <div
                        v-for="option in productOptions"
                        :key="option.id"
                        class="variation-section"
                    >

                        <label :for="`product-option-${option.id}`">
                            {{ option.name }}
                        </label>

                        <select
                            :id="`product-option-${option.id}`"
                            :value="selectedOptionValues[option.name] || ''"
                            @change="selectOptionValue(option.name, $event.target.value)"
                        >

                            <option
                                value=""
                                disabled
                            >
                                Select {{ option.name }}
                            </option>

                            <option
                                v-for="ov in option.values"
                                :key="ov.id"
                                :value="ov.value"
                                :disabled="isOptionValueDisabled(option.name, ov.value)"
                            >
                                {{ ov.value }}{{ isOptionValueDisabled(option.name, ov.value) ? ' (Unavailable)' : '' }}
                            </option>

                        </select>

                    </div>
                </template>

                <p
                    v-if="hasVariants && selectedVariantUnavailable"
                    class="product-stock product-stock--out"
                    style="margin: -0.5rem 0 0.5rem"
                >
                    This combination is currently unavailable.
                </p>

                <!-- ==================================================== -->
                <!-- QUANTITY -->
                <!-- ==================================================== -->

                <div class="quantity-section">

                    <label>
                        Quantity
                    </label>

                    <div class="quantity-control">

                        <button
                            type="button"
                            @click="decreaseQuantity"
                        >
                            -
                        </button>

                        <span>
                            {{ quantity }}
                        </span>

                        <button
                            type="button"
                            @click="increaseQuantity"
                        >
                            +
                        </button>

                    </div>

                </div>

                <!-- ==================================================== -->
                <!-- ACTIONS -->
                <!-- ==================================================== -->

                <div class="product-actions">

                    <button
                        type="button"
                        class="add-to-cart-button"
                        @click="handleAddToCart"
                    >
                        Add to Cart
                    </button>

                    <button
                        type="button"
                        class="buy-now-button"
                        @click="handleBuyNow"
                    >
                        Buy Now
                    </button>

                    <button
                        type="button"
                        class="favorite-toggle-button"
                        :class="{ 'is-favorite': favorited }"
                        :aria-pressed="favorited"
                        :title="favorited ? 'Remove from favorites' : 'Add to favorites'"
                        @click="handleToggleFavorite"
                    >
                        <svg viewBox="0 0 24 24" width="18" height="18" :fill="favorited ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="2">
                            <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.8z" />
                        </svg>
                    </button>

                </div>

                <!-- ==================================================== -->
                <!-- MESSAGE SELLER -->
                <!-- ==================================================== -->

                <div
                    v-if="product.seller_id"
                    class="message-seller"
                >
                    <button
                        type="button"
                        class="message-seller-toggle"
                        @click="toggleMessageComposer"
                    >
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
                        </svg>
                        {{ messageOpen ? 'Cancel' : `Message ${product.seller || 'Seller'}` }}
                    </button>

                    <div
                        v-if="messageOpen"
                        class="message-seller-composer"
                    >
                        <textarea
                            ref="messageInput"
                            v-model="messageDraft"
                            rows="3"
                            :placeholder="`Ask ${product.seller || 'the seller'} about “${product.name}”…`"
                            @keydown.enter.exact.prevent="sendSellerMessage"
                        ></textarea>
                        <p
                            v-if="messageError"
                            class="message-seller-error"
                        >
                            {{ messageError }}
                        </p>
                        <button
                            type="button"
                            class="message-seller-send"
                            :disabled="messageSending || !messageDraft.trim()"
                            @click="sendSellerMessage"
                        >
                            {{ messageSending ? 'Sending…' : 'Send Message' }}
                        </button>
                    </div>
                </div>

                <!-- ==================================================== -->
                <!-- SHIPPING / RETURNS -->
                <!-- ==================================================== -->

                <div class="shipping-info-grid">
                    <div class="shipping-info-item">
                        <span class="shipping-info-icon">🚚</span>
                        <div>
                            <p class="shipping-info-title">Free Shipping</p>
                            <p class="shipping-info-text">Orders above ₱2,500</p>
                        </div>
                    </div>
                    <div class="shipping-info-item">
                        <span class="shipping-info-icon">↩️</span>
                        <div>
                            <p class="shipping-info-title">30 Days Return</p>
                            <p class="shipping-info-text">Easy and free</p>
                        </div>
                    </div>
                </div>

            </div>

        </div>

        <!-- ============================================================ -->
        <!-- INFORMATION TABS -->
        <!-- ============================================================ -->

        <div class="product-tabs">

            <div class="product-tabs-nav">

                <button
                    type="button"
                    class="product-tab-button"
                    :class="{ active: activeTab === 'description' }"
                    @click="activeTab = 'description'"
                >
                    Description
                </button>

                <button
                    v-if="hasSpecifications"
                    type="button"
                    class="product-tab-button"
                    :class="{ active: activeTab === 'specifications' }"
                    @click="activeTab = 'specifications'"
                >
                    Specifications
                </button>

                <button
                    v-if="hasReviews"
                    type="button"
                    class="product-tab-button"
                    :class="{ active: activeTab === 'reviews' }"
                    @click="activeTab = 'reviews'"
                >
                    Reviews ({{ product.reviews.length }})
                </button>

                <button
                    type="button"
                    class="product-tab-button"
                    :class="{ active: activeTab === 'shipping' }"
                    @click="activeTab = 'shipping'"
                >
                    Shipping &amp; Delivery
                </button>

            </div>

            <div class="product-tabs-content">

                <div v-if="activeTab === 'description'">
                    <p>
                        {{ product.description || 'No additional description is available for this product yet.' }}
                    </p>
                </div>

                <div v-else-if="activeTab === 'specifications' && hasSpecifications">
                    <div
                        v-for="(value, key) in product.specifications"
                        :key="key"
                        class="spec-row"
                    >
                        <span class="spec-label">{{ key }}</span>
                        <span class="spec-value">{{ value }}</span>
                    </div>
                </div>

                <div v-else-if="activeTab === 'reviews' && hasReviews">
                    <div
                        v-for="review in product.reviews"
                        :key="review.id"
                        class="review-row"
                    >
                        <p class="review-author">{{ review.author }}</p>
                        <p class="review-comment">{{ review.comment }}</p>
                    </div>
                </div>

                <div v-else-if="activeTab === 'shipping'">
                    <p>
                        Orders ship within 1-2 business days. Free shipping applies on orders above ₱2,500.
                        Items can be returned within 30 days of delivery, provided they're unused and in their
                        original packaging.
                    </p>
                </div>

            </div>

        </div>

        <!-- ============================================================ -->
        <!-- RELATED PRODUCTS -->
        <!-- ============================================================ -->

        <div v-if="relatedProducts.length > 0">

            <div class="buyer-section-head">
                <h2>Related Products</h2>
            </div>

            <div class="product-grid">

                <ProductCard
                    v-for="item in relatedProducts"
                    :key="item.id"
                    :product="item"
                    @view="selectRelatedProduct"
                />

            </div>

        </div>

    </div>

    <!-- ================================================================ -->
    <!-- PRODUCT NOT FOUND -->
    <!-- ================================================================ -->

    <div
        v-else
        class="product-not-found"
    >

        <h2>
            Product not found.
        </h2>

        <button
            type="button"
            class="back-button"
            @click="goBack"
        >
            Back to Products
        </button>

    </div>

    <Footer
        @browse-all="emit('browse-all')"
        @browse-categories="emit('browse-categories')"
        @cart-click="emit('open-cart')"
    />

    </div>

</template>

<style scoped>
.message-seller {
    margin-top: 1rem;
}

.message-seller-toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.6rem 1rem;
    border: 1px solid #cbd5e1;
    border-radius: 0.75rem;
    background: #fff;
    color: #0f766e;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: background-color 0.15s ease, border-color 0.15s ease;
}

.message-seller-toggle:hover {
    background: #f0fdfa;
    border-color: #0d9488;
}

.message-seller-composer {
    margin-top: 0.75rem;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.message-seller-composer textarea {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #cbd5e1;
    border-radius: 0.75rem;
    font: inherit;
    font-size: 0.875rem;
    resize: vertical;
}

.message-seller-composer textarea:focus {
    outline: none;
    border-color: #0d9488;
    box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.12);
}

.message-seller-error {
    color: #dc2626;
    font-size: 0.8rem;
}

.message-seller-send {
    align-self: flex-start;
    padding: 0.6rem 1.25rem;
    border: none;
    border-radius: 0.75rem;
    background: #0d9488;
    color: #fff;
    font-size: 0.875rem;
    font-weight: 700;
    cursor: pointer;
    transition: background-color 0.15s ease;
}

.message-seller-send:hover:not(:disabled) {
    background: #0f766e;
}

.message-seller-send:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>