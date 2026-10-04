<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useBuyer } from '../composables/useBuyer';
import { useBuyerChat } from '../composables/useBuyerChat';
import { metaFor, formatPrice } from '../composables/useCategoryMeta';
import { shippingOptions } from '../composables/useShipping';
import { useToasts } from '../composables/useToasts';
import Footer from './Footer.vue';
import Header from './Header.vue';
import ProductCard from './ProductCard.vue';
import ProductReviewsDrawer from './ProductReviewsDrawer.vue';
import StarRating from './StarRating.vue';

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
const { warning } = useToasts();

const quantity = ref(1);
// Guards against a double-tap firing two adds before the button visibly
// settles.
const isAdding = ref(false);
const selectedImageIndex = ref(0);
const failedImages = ref(new Set());
const detailsOpen = ref(false);

const prefersReducedMotion = typeof window !== 'undefined'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const scrollBehavior = prefersReducedMotion ? 'auto' : 'smooth';

/*
|--------------------------------------------------------------------------
| Compact (mobile) layout
|--------------------------------------------------------------------------
|
| Below 960px the page swaps in a back link for the breadcrumb, collapses
| Details/Specifications, and allows the bottom buy bar. Tracked with a
| media-query listener, not a resize handler.
|
*/

const compactQuery = typeof window !== 'undefined' ? window.matchMedia('(max-width: 960px)') : null;
const isCompact = ref(compactQuery ? compactQuery.matches : false);

function handleCompactChange(event) {
    isCompact.value = event.matches;
}

/*
|--------------------------------------------------------------------------
| Variants
|--------------------------------------------------------------------------
|
| Real option/variant data from the backend (ProductController@transform).
| selectedOptionValues tracks one chosen value per option (e.g.
| { Flavor: 'Tuna', 'Pack Weight': '100g' }); selectedVariant resolves
| once every option has a value, by matching product.variants'
| option_values exactly.
|
*/

const hasVariants = computed(() => !!props.product?.hasVariants);

const productOptions = computed(() => props.product?.options || []);

const productVariants = computed(() => props.product?.variants || []);

const selectedOptionValues = ref({});

const hasAnySelection = computed(() => Object.keys(selectedOptionValues.value).length > 0);

function selectOptionValue(optionName, value) {
    selectedOptionValues.value = {
        ...selectedOptionValues.value,
        [optionName]: value,
    };
}

// Picking one value can rule out values of another option, so a buyer can
// paint themselves into a corner — this lets them start over.
function clearSelection() {
    selectedOptionValues.value = {};
}

function isBuyable(variant) {
    return variant.status === 'active' && variant.stock > 0;
}

// Tells apart the two reasons a value can't be picked:
//  - 'na'      no variant exists for it alongside the other current picks
//  - 'soldout' such a variant exists, but none of them can be bought now
// Buyers read these very differently ("never made" vs "come back later"),
// so they get different treatments instead of one generic disabled look.
function optionValueState(optionName, value) {
    const candidate = { ...selectedOptionValues.value, [optionName]: value };

    const matches = productVariants.value.filter((v) => {
        return Object.entries(candidate).every(([k, val]) => v.option_values?.[k] === val);
    });

    if (matches.length === 0) {
        return 'na';
    }

    return matches.some(isBuyable) ? 'ok' : 'soldout';
}

function optionHasUnavailableValues(option) {
    return (option.values || []).some((ov) => {
        return selectedOptionValues.value[option.name] !== ov.value &&
            optionValueState(option.name, ov.value) === 'na';
    });
}

const allOptionsSelected = computed(() => {
    return productOptions.value.length > 0 &&
        productOptions.value.every((opt) => !!selectedOptionValues.value[opt.name]);
});

const missingOptionNames = computed(() => {
    return productOptions.value
        .filter((opt) => !selectedOptionValues.value[opt.name])
        .map((opt) => opt.name.toLowerCase());
});

const selectedVariant = computed(() => {
    if (!allOptionsSelected.value) {
        return null;
    }

    return productVariants.value.find((v) => {
        return Object.entries(selectedOptionValues.value).every(
            ([k, val]) => v.option_values?.[k] === val,
        );
    }) || null;
});

const anyVariantBuyable = computed(() => productVariants.value.some(isBuyable));

const selectionSummary = computed(() => {
    if (!hasVariants.value) {
        return '';
    }

    if (!allOptionsSelected.value) {
        return `Choose your ${missingOptionNames.value.join(' and ')}`;
    }

    return productOptions.value.map((opt) => selectedOptionValues.value[opt.name]).join(', ');
});

/*
|--------------------------------------------------------------------------
| Pricing
|--------------------------------------------------------------------------
|
| For variant products the buyer pays the variant's price (the API fills
| it in from the product price when a variant has none), so that's what
| is shown: one price when every active variant costs the same, a range
| until one is picked when they differ. The compare-at "was" price only
| applies while the shown price is the product's own price, so choosing
| a variant that inherits it no longer makes the discount vanish.
|
*/

const variantPrices = computed(() => {
    return productVariants.value
        .filter((v) => v.status === 'active')
        .map((v) => Number(v.price))
        .filter((price) => Number.isFinite(price));
});

const displayPrice = computed(() => {
    if (!props.product) {
        return null;
    }

    if (selectedVariant.value) {
        return Number(selectedVariant.value.price);
    }

    if (hasVariants.value && variantPrices.value.length > 0) {
        const min = Math.min(...variantPrices.value);
        const max = Math.max(...variantPrices.value);

        return min === max ? min : null;
    }

    return Number(props.product.price);
});

const formattedPrice = computed(() => {
    if (displayPrice.value !== null) {
        return formatPrice(displayPrice.value);
    }

    if (variantPrices.value.length > 0) {
        return `${formatPrice(Math.min(...variantPrices.value))} - ${formatPrice(Math.max(...variantPrices.value))}`;
    }

    return props.product ? formatPrice(props.product.price) : '';
});

const hasDiscount = computed(() => {
    const oldPrice = Number(props.product?.oldPrice);

    return displayPrice.value !== null &&
        displayPrice.value === Number(props.product?.price) &&
        Number.isFinite(oldPrice) &&
        oldPrice > displayPrice.value;
});

const formattedOldPrice = computed(() => {
    return hasDiscount.value ? formatPrice(props.product.oldPrice) : '';
});

const discountPercent = computed(() => {
    return hasDiscount.value
        ? Math.round((1 - displayPrice.value / Number(props.product.oldPrice)) * 100)
        : 0;
});

const lowestShippingFee = computed(() => Math.min(...shippingOptions.map((option) => option.fee)));

/*
|--------------------------------------------------------------------------
| Gallery
|--------------------------------------------------------------------------
|
| product.images is the full normalized gallery from the API. A selected
| variant's own photo is put first without dropping the rest. The API's
| placeholder path and any image that fails to load are treated as "no
| image" so the category tile shows instead.
|
*/

const PLACEHOLDER_IMAGE = '/images/product-placeholder.svg';

const accentClass = computed(() => {
    return props.product ? 'accent-' + metaFor(props.product.category).accent : 'accent-slate';
});

const baseImages = computed(() => {
    const gallery = Array.isArray(props.product?.images)
        ? props.product.images.filter((src) => src && src !== PLACEHOLDER_IMAGE)
        : [];

    if (gallery.length > 0) {
        return gallery;
    }

    const single = props.product?.image || props.product?.imageUrl;

    return single && single !== PLACEHOLDER_IMAGE ? [single] : [];
});

const variantImage = computed(() => selectedVariant.value?.image?.url || '');

const galleryImages = computed(() => {
    const base = baseImages.value.filter((src) => !failedImages.value.has(src));

    if (variantImage.value && !failedImages.value.has(variantImage.value)) {
        return [variantImage.value, ...base.filter((src) => src !== variantImage.value)];
    }

    return base;
});

const hasGallery = computed(() => galleryImages.value.length > 1);

const activeImage = computed(() => galleryImages.value[selectedImageIndex.value] || '');

const activeImageAlt = computed(() => {
    if (!props.product) {
        return '';
    }

    return hasGallery.value
        ? `${props.product.name}, image ${selectedImageIndex.value + 1} of ${galleryImages.value.length}`
        : props.product.name;
});

const thumbsTrack = ref(null);

function selectImage(index) {
    selectedImageIndex.value = index;
}

function showPreviousImage() {
    const count = galleryImages.value.length;

    selectedImageIndex.value = (selectedImageIndex.value - 1 + count) % count;
}

function showNextImage() {
    selectedImageIndex.value = (selectedImageIndex.value + 1) % galleryImages.value.length;
}

function handleImageError(src) {
    failedImages.value = new Set(failedImages.value).add(src);
}

// Keep the active thumbnail in view when the arrows move past the edge of
// the strip.
watch(selectedImageIndex, () => {
    nextTick(() => {
        thumbsTrack.value
            ?.querySelector('.is-active')
            ?.scrollIntoView({ behavior: scrollBehavior, block: 'nearest', inline: 'nearest' });
    });
});

watch(variantImage, (src) => {
    if (src) {
        selectedImageIndex.value = 0;
    }
});

watch(() => galleryImages.value.length, (length) => {
    if (selectedImageIndex.value >= length) {
        selectedImageIndex.value = 0;
    }
});

/*
|--------------------------------------------------------------------------
| Image viewer (zoom)
|--------------------------------------------------------------------------
|
| Full-screen view of the same gallery. Esc closes it, arrow keys move
| between photos, Tab stays inside the dialog, and focus goes back to the
| zoom button on close.
|
*/

const viewerOpen = ref(false);
const zoomButton = ref(null);
const viewerDialog = ref(null);
const viewerCloseButton = ref(null);

function openViewer() {
    if (!activeImage.value) {
        return;
    }

    viewerOpen.value = true;
    document.body.style.overflow = 'hidden';
    nextTick(() => viewerCloseButton.value?.focus());
}

function closeViewer() {
    if (!viewerOpen.value) {
        return;
    }

    viewerOpen.value = false;
    document.body.style.overflow = '';
    nextTick(() => zoomButton.value?.focus());
}

function handleViewerKeydown(event) {
    if (event.key === 'Escape') {
        closeViewer();

        return;
    }

    if (event.key === 'ArrowLeft' && hasGallery.value) {
        showPreviousImage();

        return;
    }

    if (event.key === 'ArrowRight' && hasGallery.value) {
        showNextImage();

        return;
    }

    if (event.key === 'Tab') {
        const focusable = viewerDialog.value?.querySelectorAll('button') || [];

        if (focusable.length === 0) {
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
}

/*
|--------------------------------------------------------------------------
| Availability
|--------------------------------------------------------------------------
|
| Everything here comes from real stock fields: product.stock and
| product.lowStockThreshold, or the selected variant's own stock. For a
| variant product, product-level stock is ignored (it can disagree with
| the variants' own totals). No stock data at all means no line.
|
*/

const stockCount = computed(() => {
    if (selectedVariant.value) {
        return selectedVariant.value.stock;
    }

    if (hasVariants.value) {
        return null;
    }

    return typeof props.product?.stock === 'number' ? props.product.stock : null;
});

// One place decides the stock line, why buying is blocked, and whether
// to offer a way out to similar products — so they can never disagree.
const purchaseStatus = computed(() => {
    const product = props.product;

    if (!product) {
        return null;
    }

    if (hasVariants.value) {
        if (!anyVariantBuyable.value) {
            return {
                tone: 'out',
                label: 'Out of stock',
                reason: 'Every option is sold out right now.',
                offerSimilar: true
            };
        }

        if (!allOptionsSelected.value) {
            const missing = missingOptionNames.value.join(' and ');

            return {
                tone: 'neutral',
                label: `Choose your ${missing} to check stock`,
                reason: `Choose your ${missing} to add this to your cart.`,
                guidance: true
            };
        }

        if (!selectedVariant.value) {
            return {
                tone: 'out',
                label: 'Not available in this combination',
                reason: 'This combination isn’t sold. Pick another option.'
            };
        }

        if (!isBuyable(selectedVariant.value)) {
            return {
                tone: 'out',
                label: 'Out of stock',
                reason: 'This combination is sold out. Try another option.'
            };
        }
    }

    if (stockCount.value === null) {
        return { tone: null, label: '', reason: '' };
    }

    if (stockCount.value <= 0) {
        return {
            tone: 'out',
            label: 'Out of stock',
            reason: 'This product is sold out right now.',
            offerSimilar: true
        };
    }

    const threshold = Number(product.lowStockThreshold);

    if (product.lowStockThreshold != null && Number.isFinite(threshold) && stockCount.value <= threshold) {
        return { tone: 'low', label: `Only ${stockCount.value} left`, reason: '' };
    }

    return { tone: 'in', label: 'In stock', reason: '' };
});

const purchaseBlockedReason = computed(() => purchaseStatus.value?.reason || '');

/*
|--------------------------------------------------------------------------
| Details (description + specifications)
|--------------------------------------------------------------------------
|
| One section holds the whole description and every specification — the
| buy box only carries a short excerpt with a link down to it.
|
*/

const EXCERPT_LENGTH = 180;

const descriptionExcerpt = computed(() => {
    const text = (props.product?.description || '').trim();

    return text.length > EXCERPT_LENGTH ? `${text.slice(0, EXCERPT_LENGTH).trimEnd()}…` : text;
});

const specEntries = computed(() => {
    const specs = props.product?.specifications;
    const entries = specs && typeof specs === 'object' ? Object.entries(specs) : [];

    return props.product?.brand ? [['Brand', props.product.brand], ...entries] : entries;
});

const hasDetails = computed(() => !!props.product?.description || specEntries.value.length > 0);

/*
|--------------------------------------------------------------------------
| Reviews
|--------------------------------------------------------------------------
|
| The rating breakdown and the latest few reviews come from the public
| GET /api/products/{id}/reviews endpoint, fetched only for products that
| actually have reviews. Page-local on purpose, so filtering inside the
| drawer never changes what this section shows.
|
*/

const REVIEW_PREVIEW_COUNT = 5;

const reviewCount = computed(() => Number(props.product?.reviewCount) || 0);

const hasReviews = computed(() => {
    return reviewCount.value > 0 && typeof props.product?.rating === 'number';
});

const reviewSummary = ref(null);
const latestReviews = ref([]);
const reviewsLoading = ref(false);
const reviewsError = ref('');
const activeReviewIndex = ref(0);

let reviewsRequestId = 0;

async function loadReviews() {
    reviewSummary.value = null;
    latestReviews.value = [];
    reviewsError.value = '';
    activeReviewIndex.value = 0;

    const product = props.product;

    if (!product || !(Number(product.reviewCount) > 0)) {
        reviewsLoading.value = false;

        return;
    }

    const requestId = ++reviewsRequestId;

    reviewsLoading.value = true;

    try {
        const response = await fetch(
            `/api/products/${encodeURIComponent(product.id)}/reviews?per_page=${REVIEW_PREVIEW_COUNT}`,
            { headers: { Accept: 'application/json' } },
        );
        const body = await response.json().catch(() => ({}));

        // A newer product was opened while this was in flight.
        if (requestId !== reviewsRequestId) {
            return;
        }

        if (!response.ok) {
            throw new Error(body.message || 'Could not load reviews.');
        }

        reviewSummary.value = body.summary || null;
        latestReviews.value = body.data || [];
    } catch (err) {
        if (requestId === reviewsRequestId) {
            reviewsError.value = err?.message || 'Could not load reviews.';
        }
    } finally {
        if (requestId === reviewsRequestId) {
            reviewsLoading.value = false;
        }
    }
}

const ratingBreakdown = computed(() => {
    const summary = reviewSummary.value;
    const total = summary?.total || 0;

    return [5, 4, 3, 2, 1].map((star) => {
        const count = Number(summary?.breakdown?.[star]) || 0;

        return {
            star,
            count,
            percent: total > 0 ? Math.round((count / total) * 100) : 0
        };
    });
});

const activeReview = computed(() => latestReviews.value[activeReviewIndex.value] || null);

function showPreviousReview() {
    if (activeReviewIndex.value > 0) {
        activeReviewIndex.value--;
    }
}

function showNextReview() {
    if (activeReviewIndex.value < latestReviews.value.length - 1) {
        activeReviewIndex.value++;
    }
}

function formatReviewDate(iso) {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime())
        ? ''
        : date.toLocaleDateString('en-PH', { day: 'numeric', month: 'short', year: 'numeric' });
}

const reviewsOpen = ref(false);

function openReviews() {
    if (props.product) {
        reviewsOpen.value = true;
    }
}

/*
|--------------------------------------------------------------------------
| In-page sections
|--------------------------------------------------------------------------
|
| Details / Reviews / You might also like. The desktop side menu follows
| whichever section is in view via an IntersectionObserver.
|
*/

const detailsSection = ref(null);
const reviewsSection = ref(null);
const relatedSection = ref(null);
const activeSection = ref('details');

const sectionLinks = computed(() => {
    const links = [];

    if (hasDetails.value) {
        links.push({ id: 'details', label: 'Details' });
    }

    links.push({ id: 'reviews', label: 'Reviews' });
    links.push({ id: 'related', label: 'You might also like' });

    return links;
});

function sectionElement(id) {
    return { details: detailsSection, reviews: reviewsSection, related: relatedSection }[id]?.value || null;
}

function scrollToSection(id) {
    if (id === 'details') {
        detailsOpen.value = true;
    }

    nextTick(() => {
        sectionElement(id)?.scrollIntoView({ behavior: scrollBehavior, block: 'start' });
    });
}

let sectionObserver = null;

function observeSections() {
    sectionObserver?.disconnect();

    if (typeof IntersectionObserver === 'undefined') {
        return;
    }

    sectionObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                activeSection.value = entry.target.dataset.section;
            }
        });
    }, { rootMargin: '-35% 0px -60% 0px' });

    ['details', 'reviews', 'related'].forEach((id) => {
        const el = sectionElement(id);

        if (el) {
            sectionObserver.observe(el);
        }
    });
}

/*
|--------------------------------------------------------------------------
| Mobile buy bar
|--------------------------------------------------------------------------
|
| Appears only on the compact layout, and only once the in-page quantity
| + Add to Cart row has scrolled up past the sticky header — so it never
| duplicates a button that's already on screen. The page gets matching
| bottom padding while it shows, so it never covers the footer.
|
*/

const buyRow = ref(null);
const optionsBlock = ref(null);
const buyRowPassed = ref(false);

let buyRowObserver = null;

function observeBuyRow() {
    buyRowObserver?.disconnect();

    if (!buyRow.value || typeof IntersectionObserver === 'undefined') {
        return;
    }

    buyRowObserver = new IntersectionObserver(([entry]) => {
        buyRowPassed.value = !entry.isIntersecting && entry.boundingClientRect.top < 0;
    }, { rootMargin: '-96px 0px 0px 0px' });

    buyRowObserver.observe(buyRow.value);
}

const showBuyBar = computed(() => isCompact.value && buyRowPassed.value && !!props.product);

const buyBarAction = computed(() => {
    const status = purchaseStatus.value;

    if (status?.offerSimilar) {
        return 'soldout';
    }

    if (status?.guidance) {
        return 'choose';
    }

    if (status?.reason) {
        return 'change';
    }

    return 'add';
});

function focusFirstOpenOption() {
    const target = productOptions.value.find((opt) => !selectedOptionValues.value[opt.name]) ||
        productOptions.value[0];

    optionsBlock.value?.scrollIntoView({ behavior: scrollBehavior, block: 'center' });

    if (target) {
        nextTick(() => {
            optionsBlock.value
                ?.querySelector(`input[name="pdp-option-${target.id}"]:not(:disabled)`)
                ?.focus({ preventScroll: true });
        });
    }
}

function handleBuyBarAction() {
    if (buyBarAction.value === 'add') {
        handleAddToCart();

        return;
    }

    if (buyBarAction.value === 'choose' || buyBarAction.value === 'change') {
        focusFirstOpenOption();
    }
}

/*
|--------------------------------------------------------------------------
| Favorite
|--------------------------------------------------------------------------
*/

const favorited = computed(() => {
    return props.product ? isFavorite(props.product.id) : false;
});

function handleToggleFavorite() {
    if (props.product) {
        toggleFavorite(props.product.id);
    }
}

/*
|--------------------------------------------------------------------------
| Quantity
|--------------------------------------------------------------------------
|
| Client-side convenience only — the real limit is enforced server-side
| at checkout (CheckoutService locks and re-checks the actual row).
|
*/

const canIncreaseQuantity = computed(() => {
    return !purchaseBlockedReason.value &&
        (stockCount.value === null || quantity.value < stockCount.value);
});

function increaseQuantity() {
    if (canIncreaseQuantity.value) {
        quantity.value++;
    }
}

function decreaseQuantity() {
    if (quantity.value > 1) {
        quantity.value--;
    }
}

// Switching to a variant with less stock shouldn't leave an impossible
// quantity selected.
watch(stockCount, (stock) => {
    if (stock !== null && stock > 0 && quantity.value > stock) {
        quantity.value = stock;
    }
});

/*
|--------------------------------------------------------------------------
| Add To Cart / Buy Now
|--------------------------------------------------------------------------
*/

function validateSelection() {
    if (!props.product || purchaseBlockedReason.value) {
        return false;
    }

    if (stockCount.value !== null && quantity.value > stockCount.value) {
        warning(`Only ${stockCount.value} left in stock.`);

        return false;
    }

    return true;
}

function handleAddToCart() {
    if (isAdding.value || !validateSelection()) {
        return;
    }

    isAdding.value = true;

    // addToCart owns the "Added to cart." / stock-limit toast — see
    // useBuyer.js. Nothing else to surface here.
    addToCart(props.product, selectedVariant.value, quantity.value);

    setTimeout(() => {
        isAdding.value = false;
    }, 400);
}

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
| Seller
|--------------------------------------------------------------------------
|
| The API exposes the store name and line of business only — there is no
| seller logo, seller rating, or buyer-facing storefront page yet, so none
| of those are shown or linked.
|
*/

const sellerName = computed(() => props.product?.seller || 'Seller');

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
| Navigation
|--------------------------------------------------------------------------
|
| The embedded Header has no dashboard state of its own, so searches,
| category picks, and breadcrumb clicks bubble up to Dashboard, which does
| the real navigation (home, CategoryListing, etc.).
|
*/

function goBack() {
    emit('back');
}

function handleHeaderSearch(query) {
    emit('search', query);
}

function handleHeaderSelectCategory(category) {
    emit('select-category', category);
}

function selectRelatedProduct(item) {
    emit('select-product', item);
}

/*
|--------------------------------------------------------------------------
| Lifecycle
|--------------------------------------------------------------------------
*/

// The buy row and sections only exist while a product is shown, so the
// observers re-attach whenever those elements are (re)created.
watch(buyRow, observeBuyRow);
watch([detailsSection, reviewsSection, relatedSection], observeSections);

onMounted(() => {
    compactQuery?.addEventListener('change', handleCompactChange);
    observeBuyRow();
    observeSections();
});

onUnmounted(() => {
    compactQuery?.removeEventListener('change', handleCompactChange);
    buyRowObserver?.disconnect();
    sectionObserver?.disconnect();

    if (viewerOpen.value) {
        document.body.style.overflow = '';
    }
});

// Showing a different product (e.g. from "You might also like") starts
// from a clean slate instead of carrying the previous product's state.
watch(
    () => props.product?.id,
    () => {
        selectedOptionValues.value = {};
        selectedImageIndex.value = 0;
        quantity.value = 1;
        failedImages.value = new Set();
        detailsOpen.value = false;
        activeSection.value = 'details';
        buyRowPassed.value = false;
        messageOpen.value = false;
        messageError.value = '';
        closeViewer();
        loadReviews();
    },
    { immediate: true },
);
</script>

<template>

    <div
        class="buyer-page pdp-page"
        :class="{ 'has-buy-bar': showBuyBar }"
    >

        <Header
            :active-category="product ? product.category : ''"
            @select-category="handleHeaderSelectCategory"
            @cart-click="emit('open-cart')"
            @account-click="emit('view-profile')"
            @logo-click="goBack"
            @search="handleHeaderSearch"
        />

        <main
            v-if="product"
            class="pdp"
        >

            <!-- Compact layouts get a single back link; the full trail
                 only earns its space on wide screens. -->
            <button
                v-if="isCompact"
                type="button"
                class="pdp-backlink"
                @click="emit('select-category', product.category)"
            >
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
                {{ product.category }}
            </button>

            <nav
                v-else
                class="pdp-breadcrumb"
                aria-label="Breadcrumb"
            >
                <ol>
                    <li>
                        <button
                            type="button"
                            class="pdp-crumb-link"
                            @click="emit('browse-all')"
                        >
                            Home
                        </button>
                    </li>
                    <li>
                        <button
                            type="button"
                            class="pdp-crumb-link"
                            @click="emit('select-category', product.category)"
                        >
                            {{ product.category }}
                        </button>
                    </li>
                    <li
                        class="pdp-crumb-current"
                        aria-current="page"
                    >
                        {{ product.name }}
                    </li>
                </ol>
            </nav>

            <!-- ======================================================== -->
            <!-- GALLERY + BUY BOX -->
            <!-- ======================================================== -->

            <section
                class="pdp-main"
                aria-labelledby="pdp-title"
            >

                <div class="pdp-gallery">

                    <div
                        class="pdp-frame"
                        :class="[accentClass, { 'has-image': activeImage }]"
                    >
                        <Transition name="pdp-fade">
                            <img
                                v-if="activeImage"
                                :key="activeImage"
                                class="pdp-frame-image"
                                :src="activeImage"
                                :alt="activeImageAlt"
                                @error="handleImageError(activeImage)"
                            >
                        </Transition>

                        <div
                            v-if="!activeImage"
                            class="pdp-frame-fallback"
                        >
                            <span
                                class="product-image-icon product-image-icon--lg"
                                aria-hidden="true"
                                v-html="metaFor(product.category).icon"
                            ></span>
                            <p>No photo available</p>
                        </div>

                        <span
                            v-if="hasDiscount"
                            class="pdp-frame-badge"
                        >
                            -{{ discountPercent }}%
                        </span>

                        <button
                            v-if="activeImage"
                            ref="zoomButton"
                            type="button"
                            class="pdp-zoom"
                            aria-label="View larger image"
                            @click="openViewer"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="11" cy="11" r="7" />
                                <path d="m20 20-3.5-3.5" />
                                <path d="M11 8v6" />
                                <path d="M8 11h6" />
                            </svg>
                        </button>
                    </div>

                    <div
                        v-if="hasGallery"
                        class="pdp-thumbs-row"
                    >
                        <button
                            type="button"
                            class="pdp-round-button"
                            aria-label="Previous image"
                            @click="showPreviousImage"
                        >
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
                        </button>

                        <div
                            ref="thumbsTrack"
                            class="pdp-thumbs"
                            role="group"
                            aria-label="Product images"
                        >
                            <button
                                v-for="(src, index) in galleryImages"
                                :key="index"
                                type="button"
                                class="pdp-thumb"
                                :class="{ 'is-active': index === selectedImageIndex }"
                                :aria-label="`Show image ${index + 1} of ${galleryImages.length}`"
                                :aria-pressed="index === selectedImageIndex"
                                @click="selectImage(index)"
                            >
                                <img
                                    :src="src"
                                    alt=""
                                    loading="lazy"
                                    @error="handleImageError(src)"
                                >
                            </button>
                        </div>

                        <button
                            type="button"
                            class="pdp-round-button"
                            aria-label="Next image"
                            @click="showNextImage"
                        >
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
                        </button>
                    </div>

                </div>

                <!-- Buy box, in the order a buyer decides -->
                <div class="pdp-buy">

                    <div class="pdp-heading">
                        <h1
                            id="pdp-title"
                            class="pdp-title"
                        >
                            {{ product.name }}
                        </h1>
                        <p class="pdp-soldby">
                            Sold by <span>{{ sellerName }}</span>
                        </p>
                        <button
                            v-if="hasReviews"
                            type="button"
                            class="pdp-rating"
                            :aria-label="`Rated ${product.rating.toFixed(1)} out of 5 from ${reviewCount} ${reviewCount === 1 ? 'review' : 'reviews'}. Go to reviews`"
                            @click="scrollToSection('reviews')"
                        >
                            <StarRating
                                :rating="product.rating"
                                :size="15"
                            />
                            <span class="pdp-rating-score">{{ product.rating.toFixed(1) }}</span>
                            <span class="pdp-rating-count">
                                ({{ reviewCount }} {{ reviewCount === 1 ? 'review' : 'reviews' }})
                            </span>
                        </button>
                    </div>

                    <div class="pdp-pricebox">
                        <div class="pdp-price-row">
                            <span class="pdp-price">{{ formattedPrice }}</span>
                            <template v-if="hasDiscount">
                                <span class="pdp-old-price">
                                    <span class="pdp-sr-only">Original price</span>
                                    {{ formattedOldPrice }}
                                </span>
                                <span class="pdp-savings">{{ discountPercent }}% off</span>
                            </template>
                        </div>
                        <p class="pdp-shipfrom">
                            Shipping from {{ formatPrice(lowestShippingFee) }}, charged once per seller order
                        </p>
                        <p
                            v-if="purchaseStatus && purchaseStatus.label"
                            class="pdp-stock"
                            :class="`pdp-stock--${purchaseStatus.tone}`"
                        >
                            {{ purchaseStatus.label }}
                        </p>
                    </div>

                    <p
                        v-if="descriptionExcerpt"
                        class="pdp-excerpt"
                    >
                        {{ descriptionExcerpt }}
                        <button
                            type="button"
                            class="pdp-text-button"
                            @click="scrollToSection('details')"
                        >
                            Details
                        </button>
                    </p>

                    <!-- Options -->
                    <div
                        v-if="hasVariants && productOptions.length > 0"
                        ref="optionsBlock"
                        class="pdp-options"
                    >
                        <fieldset
                            v-for="option in productOptions"
                            :key="option.id"
                            class="pdp-option"
                        >
                            <legend class="pdp-option-legend">
                                {{ option.name }}<template v-if="selectedOptionValues[option.name]">:
                                    <span class="pdp-option-chosen">{{ selectedOptionValues[option.name] }}</span>
                                </template>
                            </legend>

                            <div class="pdp-chips">
                                <label
                                    v-for="ov in option.values"
                                    :key="ov.id"
                                    class="pdp-chip"
                                    :class="{
                                        'is-selected': selectedOptionValues[option.name] === ov.value,
                                        'is-soldout': selectedOptionValues[option.name] !== ov.value && optionValueState(option.name, ov.value) === 'soldout',
                                        'is-unavailable': selectedOptionValues[option.name] !== ov.value && optionValueState(option.name, ov.value) === 'na'
                                    }"
                                >
                                    <input
                                        type="radio"
                                        class="pdp-sr-only"
                                        :name="`pdp-option-${option.id}`"
                                        :value="ov.value"
                                        :checked="selectedOptionValues[option.name] === ov.value"
                                        :disabled="selectedOptionValues[option.name] !== ov.value && optionValueState(option.name, ov.value) !== 'ok'"
                                        @change="selectOptionValue(option.name, ov.value)"
                                    >
                                    <span>{{ ov.value }}</span>
                                    <span
                                        v-if="selectedOptionValues[option.name] !== ov.value && optionValueState(option.name, ov.value) === 'soldout'"
                                        class="pdp-sr-only"
                                    >
                                        (sold out)
                                    </span>
                                    <span
                                        v-else-if="selectedOptionValues[option.name] !== ov.value && optionValueState(option.name, ov.value) === 'na'"
                                        class="pdp-sr-only"
                                    >
                                        (not available with your other choice)
                                    </span>
                                </label>
                            </div>

                            <p
                                v-if="optionHasUnavailableValues(option)"
                                class="pdp-option-hint"
                            >
                                Dashed options aren't available with your other choice.
                            </p>
                        </fieldset>

                        <button
                            v-if="hasAnySelection"
                            type="button"
                            class="pdp-text-button"
                            @click="clearSelection"
                        >
                            Clear selection
                        </button>
                    </div>

                    <!-- Quantity + Add to Cart share a row; Buy Now sits under -->
                    <div class="pdp-actions">
                        <div
                            ref="buyRow"
                            class="pdp-buyrow"
                        >
                            <div
                                class="pdp-qty"
                                role="group"
                                aria-label="Quantity"
                            >
                                <button
                                    type="button"
                                    aria-label="Decrease quantity"
                                    :disabled="quantity <= 1"
                                    @click="decreaseQuantity"
                                >
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14" /></svg>
                                </button>
                                <span
                                    class="pdp-qty-value"
                                    aria-live="polite"
                                >
                                    {{ quantity }}
                                </span>
                                <button
                                    type="button"
                                    aria-label="Increase quantity"
                                    :disabled="!canIncreaseQuantity"
                                    @click="increaseQuantity"
                                >
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14" /><path d="M5 12h14" /></svg>
                                </button>
                            </div>

                            <button
                                type="button"
                                class="pdp-button pdp-button--primary"
                                :disabled="!!purchaseBlockedReason || isAdding"
                                :aria-describedby="purchaseBlockedReason ? 'pdp-purchase-note' : undefined"
                                @click="handleAddToCart"
                            >
                                {{ isAdding ? 'Adding…' : 'Add to Cart' }}
                            </button>

                            <button
                                type="button"
                                class="pdp-favorite"
                                :class="{ 'is-favorite': favorited }"
                                :aria-pressed="favorited"
                                :aria-label="favorited ? 'Remove from favorites' : 'Save to favorites'"
                                @click="handleToggleFavorite"
                            >
                                <svg viewBox="0 0 24 24" width="20" height="20" :fill="favorited ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.8z" />
                                </svg>
                            </button>
                        </div>

                        <button
                            type="button"
                            class="pdp-button pdp-button--secondary"
                            :disabled="!!purchaseBlockedReason"
                            :aria-describedby="purchaseBlockedReason ? 'pdp-purchase-note' : undefined"
                            @click="handleBuyNow"
                        >
                            Buy Now
                        </button>

                        <p
                            v-if="purchaseBlockedReason"
                            id="pdp-purchase-note"
                            class="pdp-purchase-note"
                            :class="purchaseStatus.guidance ? 'pdp-purchase-note--neutral' : 'pdp-purchase-note--warning'"
                            role="status"
                        >
                            {{ purchaseBlockedReason }}
                            <button
                                v-if="purchaseStatus.offerSimilar"
                                type="button"
                                class="pdp-text-button pdp-text-button--inline"
                                @click="scrollToSection('related')"
                            >
                                See other {{ product.category }}
                            </button>
                        </p>
                    </div>

                    <!-- Delivery, returns, seller -->
                    <dl class="pdp-facts">
                        <div class="pdp-fact">
                            <dt>Delivery</dt>
                            <dd>
                                <span
                                    v-for="option in shippingOptions"
                                    :key="option.id"
                                    class="pdp-fact-line"
                                >
                                    {{ option.shortName }} {{ option.eta }}, {{ formatPrice(option.fee) }}
                                </span>
                            </dd>
                        </div>
                        <div class="pdp-fact">
                            <dt>Returns</dt>
                            <dd>Request a return or refund from your orders after delivery</dd>
                        </div>
                        <div class="pdp-fact">
                            <dt>Seller</dt>
                            <dd>
                                <span class="pdp-fact-seller">{{ sellerName }}</span>
                                <span
                                    v-if="product.seller_line_of_business"
                                    class="pdp-fact-sub"
                                >
                                    {{ product.seller_line_of_business }}
                                </span>
                            </dd>
                            <button
                                v-if="product.seller_id"
                                type="button"
                                class="pdp-text-button pdp-fact-action"
                                aria-controls="pdp-message-composer"
                                :aria-expanded="messageOpen"
                                @click="toggleMessageComposer"
                            >
                                {{ messageOpen ? 'Cancel' : 'Contact seller' }}
                            </button>
                        </div>
                    </dl>

                    <div
                        v-if="messageOpen"
                        id="pdp-message-composer"
                        class="pdp-composer"
                    >
                        <label
                            for="pdp-message-input"
                            class="pdp-composer-label"
                        >
                            Message to {{ sellerName }}
                        </label>
                        <textarea
                            id="pdp-message-input"
                            ref="messageInput"
                            v-model="messageDraft"
                            rows="3"
                            :placeholder="`Ask about “${product.name}”`"
                            @keydown.enter.exact.prevent="sendSellerMessage"
                        ></textarea>
                        <p
                            v-if="messageError"
                            class="pdp-composer-error"
                            role="alert"
                        >
                            {{ messageError }}
                        </p>
                        <button
                            type="button"
                            class="pdp-button pdp-button--primary pdp-composer-send"
                            :disabled="messageSending || !messageDraft.trim()"
                            @click="sendSellerMessage"
                        >
                            {{ messageSending ? 'Sending…' : 'Send Message' }}
                        </button>
                    </div>

                </div>

            </section>

            <!-- ======================================================== -->
            <!-- LOWER: in-page menu + Details / Reviews / Related -->
            <!-- ======================================================== -->

            <div class="pdp-lower">

                <nav
                    v-if="!isCompact"
                    class="pdp-subnav"
                    aria-label="On this page"
                >
                    <button
                        v-for="link in sectionLinks"
                        :key="link.id"
                        type="button"
                        class="pdp-subnav-link"
                        :class="{ 'is-active': activeSection === link.id }"
                        :aria-current="activeSection === link.id ? 'true' : undefined"
                        @click="scrollToSection(link.id)"
                    >
                        {{ link.label }}
                    </button>
                </nav>

                <div class="pdp-sections">

                    <!-- Details: description + all specifications -->
                    <section
                        v-if="hasDetails"
                        ref="detailsSection"
                        class="pdp-section"
                        data-section="details"
                        aria-labelledby="pdp-details-heading"
                    >
                        <details
                            v-if="isCompact"
                            class="pdp-accordion"
                            :open="detailsOpen"
                            @toggle="detailsOpen = $event.target.open"
                        >
                            <summary id="pdp-details-heading">Details and specifications</summary>
                            <div class="pdp-accordion-body">
                                <p
                                    v-if="product.description"
                                    class="pdp-description"
                                >
                                    {{ product.description }}
                                </p>
                                <dl
                                    v-if="specEntries.length > 0"
                                    class="pdp-specs"
                                >
                                    <div
                                        v-for="[label, value] in specEntries"
                                        :key="label"
                                        class="pdp-spec"
                                    >
                                        <dt>{{ label }}</dt>
                                        <dd>{{ value }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </details>

                        <template v-else>
                            <h2
                                id="pdp-details-heading"
                                class="pdp-section-title"
                            >
                                Details
                            </h2>
                            <p
                                v-if="product.description"
                                class="pdp-description"
                            >
                                {{ product.description }}
                            </p>
                            <dl
                                v-if="specEntries.length > 0"
                                class="pdp-specs"
                            >
                                <div
                                    v-for="[label, value] in specEntries"
                                    :key="label"
                                    class="pdp-spec"
                                >
                                    <dt>{{ label }}</dt>
                                    <dd>{{ value }}</dd>
                                </div>
                            </dl>
                        </template>
                    </section>

                    <!-- Reviews -->
                    <section
                        ref="reviewsSection"
                        class="pdp-section"
                        data-section="reviews"
                        aria-labelledby="pdp-reviews-heading"
                    >
                        <h2
                            id="pdp-reviews-heading"
                            class="pdp-section-title"
                        >
                            Reviews
                        </h2>

                        <div
                            v-if="hasReviews"
                            class="pdp-reviews"
                        >

                            <div class="pdp-reviews-summary">
                                <div class="pdp-reviews-score">
                                    <span class="pdp-reviews-number">{{ product.rating.toFixed(1) }}</span>
                                    <StarRating
                                        :rating="product.rating"
                                        :size="16"
                                    />
                                    <p class="pdp-reviews-based">
                                        Based on {{ reviewCount }} {{ reviewCount === 1 ? 'review' : 'reviews' }}
                                    </p>
                                </div>

                                <ul
                                    class="pdp-breakdown"
                                    aria-label="Rating breakdown"
                                >
                                    <li
                                        v-for="row in ratingBreakdown"
                                        :key="row.star"
                                        class="pdp-breakdown-row"
                                    >
                                        <span class="pdp-breakdown-star">
                                            {{ row.star }}
                                            <svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.123 2.123 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" /></svg>
                                        </span>
                                        <span
                                            class="pdp-breakdown-bar"
                                            :class="{ 'is-loading': reviewsLoading && !reviewSummary }"
                                            aria-hidden="true"
                                        >
                                            <span
                                                class="pdp-breakdown-fill"
                                                :style="{ width: `${row.percent}%` }"
                                            ></span>
                                        </span>
                                        <span class="pdp-breakdown-percent">
                                            <template v-if="reviewSummary">{{ row.percent }}%</template>
                                        </span>
                                        <span
                                            v-if="reviewSummary"
                                            class="pdp-sr-only"
                                        >
                                            {{ row.count }} {{ row.count === 1 ? 'review' : 'reviews' }} with {{ row.star }} {{ row.star === 1 ? 'star' : 'stars' }}
                                        </span>
                                    </li>
                                </ul>
                            </div>

                            <div class="pdp-review-card">

                                <div
                                    v-if="reviewsLoading && latestReviews.length === 0"
                                    class="pdp-review-skeleton"
                                    aria-hidden="true"
                                >
                                    <span class="pdp-skeleton pdp-skeleton--short"></span>
                                    <span class="pdp-skeleton pdp-skeleton--tiny"></span>
                                    <span class="pdp-skeleton"></span>
                                    <span class="pdp-skeleton"></span>
                                </div>

                                <div
                                    v-else-if="reviewsError"
                                    class="pdp-review-error"
                                >
                                    <p>{{ reviewsError }}</p>
                                    <button
                                        type="button"
                                        class="pdp-text-button"
                                        @click="loadReviews"
                                    >
                                        Try again
                                    </button>
                                </div>

                                <template v-else-if="activeReview">
                                    <div class="pdp-review-head">
                                        <p class="pdp-review-author">{{ activeReview.author }}</p>
                                        <p class="pdp-review-date">{{ formatReviewDate(activeReview.createdAt) }}</p>
                                    </div>

                                    <StarRating
                                        :rating="activeReview.rating"
                                        :size="14"
                                    />

                                    <p
                                        v-if="activeReview.comment"
                                        class="pdp-review-comment"
                                    >
                                        {{ activeReview.comment }}
                                    </p>
                                    <p
                                        v-else
                                        class="pdp-review-comment pdp-review-comment--empty"
                                    >
                                        Rated without a written comment.
                                    </p>

                                    <p
                                        v-if="activeReview.verifiedPurchase"
                                        class="pdp-review-verified"
                                    >
                                        Verified purchase
                                    </p>

                                    <div class="pdp-review-footer">
                                        <div
                                            v-if="latestReviews.length > 1"
                                            class="pdp-review-nav"
                                        >
                                            <button
                                                type="button"
                                                class="pdp-round-button"
                                                aria-label="Previous review"
                                                :disabled="activeReviewIndex === 0"
                                                @click="showPreviousReview"
                                            >
                                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
                                            </button>

                                            <div class="pdp-review-dots">
                                                <button
                                                    v-for="(review, index) in latestReviews"
                                                    :key="review.id"
                                                    type="button"
                                                    class="pdp-review-dot"
                                                    :class="{ 'is-active': index === activeReviewIndex }"
                                                    :aria-label="`Show review ${index + 1} of ${latestReviews.length}`"
                                                    :aria-pressed="index === activeReviewIndex"
                                                    @click="activeReviewIndex = index"
                                                ></button>
                                            </div>

                                            <button
                                                type="button"
                                                class="pdp-round-button"
                                                aria-label="Next review"
                                                :disabled="activeReviewIndex === latestReviews.length - 1"
                                                @click="showNextReview"
                                            >
                                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
                                            </button>
                                        </div>

                                        <button
                                            type="button"
                                            class="pdp-text-button"
                                            @click="openReviews"
                                        >
                                            {{ reviewCount === 1 ? 'Open review' : `Read all ${reviewCount} reviews` }}
                                        </button>
                                    </div>
                                </template>

                            </div>

                        </div>

                        <div
                            v-else
                            class="pdp-empty"
                        >
                            <p class="pdp-empty-title">No reviews yet</p>
                            <p class="pdp-empty-text">Buyers can review this product once their order is delivered.</p>
                        </div>
                    </section>

                    <!-- You might also like -->
                    <section
                        ref="relatedSection"
                        class="pdp-section"
                        data-section="related"
                        aria-labelledby="pdp-related-heading"
                    >
                        <h2
                            id="pdp-related-heading"
                            class="pdp-section-title"
                        >
                            You might also like
                        </h2>

                        <div
                            v-if="relatedProducts.length > 0"
                            class="product-grid pdp-related"
                        >
                            <ProductCard
                                v-for="item in relatedProducts"
                                :key="item.id"
                                :product="item"
                                @view="selectRelatedProduct"
                            />
                        </div>

                        <div
                            v-else
                            class="pdp-empty"
                        >
                            <p class="pdp-empty-title">Nothing else in {{ product.category }} yet</p>
                            <p class="pdp-empty-text">New listings from local sellers show up here as they arrive.</p>
                            <button
                                type="button"
                                class="pdp-button pdp-button--ghost"
                                @click="emit('browse-all')"
                            >
                                Browse all products
                            </button>
                        </div>
                    </section>

                </div>

            </div>

        </main>

        <!-- ============================================================ -->
        <!-- PRODUCT NOT FOUND -->
        <!-- ============================================================ -->

        <main
            v-else
            class="pdp-not-found"
        >
            <h1>Product not found</h1>
            <p>It may have been removed or is no longer available.</p>
            <button
                type="button"
                class="pdp-button pdp-button--primary"
                @click="goBack"
            >
                Back to products
            </button>
        </main>

        <Footer
            @browse-all="emit('browse-all')"
            @browse-categories="emit('browse-categories')"
            @cart-click="emit('open-cart')"
        />

        <ProductReviewsDrawer
            v-if="product"
            :show="reviewsOpen"
            :product="{ id: product.id, name: product.name, rating: product.rating, reviewCount: reviewCount }"
            @close="reviewsOpen = false"
        />

        <!-- ============================================================ -->
        <!-- MOBILE BUY BAR -->
        <!-- ============================================================ -->

        <Transition name="pdp-bar">
            <div
                v-if="showBuyBar"
                class="pdp-buybar"
            >
                <div class="pdp-buybar-info">
                    <span class="pdp-buybar-price">{{ formattedPrice }}</span>
                    <span
                        v-if="selectionSummary"
                        class="pdp-buybar-choice"
                    >
                        {{ selectionSummary }}
                    </span>
                </div>
                <button
                    type="button"
                    class="pdp-button pdp-button--primary pdp-buybar-button"
                    :disabled="buyBarAction === 'soldout' || isAdding"
                    @click="handleBuyBarAction"
                >
                    <template v-if="buyBarAction === 'soldout'">Sold out</template>
                    <template v-else-if="buyBarAction === 'choose'">Choose options</template>
                    <template v-else-if="buyBarAction === 'change'">Change option</template>
                    <template v-else>{{ isAdding ? 'Adding…' : 'Add to Cart' }}</template>
                </button>
            </div>
        </Transition>

        <!-- ============================================================ -->
        <!-- IMAGE VIEWER -->
        <!-- ============================================================ -->

        <Teleport to="body">
            <Transition name="pdp-fade">
                <div
                    v-if="viewerOpen && product"
                    ref="viewerDialog"
                    class="pdp-viewer"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="`${product.name} photos`"
                    @keydown="handleViewerKeydown"
                    @click.self="closeViewer"
                >
                    <button
                        ref="viewerCloseButton"
                        type="button"
                        class="pdp-viewer-close"
                        aria-label="Close image viewer"
                        @click="closeViewer"
                    >
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>
                    </button>

                    <img
                        v-if="activeImage"
                        class="pdp-viewer-image"
                        :src="activeImage"
                        :alt="activeImageAlt"
                    >

                    <template v-if="hasGallery">
                        <button
                            type="button"
                            class="pdp-viewer-nav pdp-viewer-nav--prev"
                            aria-label="Previous image"
                            @click="showPreviousImage"
                        >
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
                        </button>
                        <button
                            type="button"
                            class="pdp-viewer-nav pdp-viewer-nav--next"
                            aria-label="Next image"
                            @click="showNextImage"
                        >
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
                        </button>
                    </template>
                </div>
            </Transition>
        </Teleport>

    </div>

</template>

<style scoped>
/*
| Radius system for this page: media frames 18px, panels 16px,
| controls 10px, pills/round buttons fully round.
*/

.pdp-page {
    background: #ffffff;
}

/* Keeps the footer clear of the fixed mobile buy bar while it shows. */
.pdp-page.has-buy-bar {
    padding-bottom: calc(76px + env(safe-area-inset-bottom, 0px));
}

.pdp {
    max-width: 1240px;
    margin: 0 auto;
    padding: 24px 48px 112px;

    display: flex;
    flex-direction: column;
    gap: 28px;
}

.pdp-sr-only {
    position: absolute;

    width: 1px;
    height: 1px;
    margin: -1px;
    padding: 0;

    overflow: hidden;

    white-space: nowrap;
    clip: rect(0, 0, 0, 0);
    border: 0;
}

/* ---------------- Breadcrumb / back link ---------------- */

.pdp-breadcrumb ol {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;

    margin: 0;
    padding: 0;

    list-style: none;

    font-size: 13px;
    color: var(--nx-muted);
}

.pdp-breadcrumb li + li::before {
    content: "";

    display: inline-block;
    width: 6px;
    height: 6px;
    margin: 0 10px 1px 2px;

    border-top: 1.5px solid var(--nx-muted-2);
    border-right: 1.5px solid var(--nx-muted-2);
    transform: rotate(45deg);
}

.pdp-crumb-link {
    padding: 2px 0;

    border: none;
    background: none;

    color: var(--nx-muted);
    font: inherit;

    cursor: pointer;
    transition: color 0.15s ease;
}

.pdp-crumb-link:hover {
    color: var(--nx-accent-dark);
}

.pdp-crumb-current {
    max-width: 40ch;

    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;

    color: var(--nx-ink);
}

.pdp-backlink {
    align-self: flex-start;

    display: inline-flex;
    align-items: center;
    gap: 4px;

    min-height: 36px;
    padding: 0;

    border: none;
    background: none;

    color: var(--nx-accent-dark);
    font: inherit;
    font-size: 14px;
    font-weight: 700;

    cursor: pointer;
}

.pdp-crumb-link:focus-visible,
.pdp-backlink:focus-visible {
    outline: 2px solid var(--nx-accent);
    outline-offset: 2px;
    border-radius: 4px;
}

/* ---------------- Main: gallery + buy box ---------------- */

.pdp-main {
    display: grid;
    grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr);
    gap: 64px;
    align-items: start;
}

/* The photo stays in view while the buyer works down the options. Top
   offset clears the sticky site header (ticker + nav). */
.pdp-gallery {
    position: sticky;
    top: 124px;

    display: flex;
    flex-direction: column;
    gap: 16px;

    min-width: 0;
}

.pdp-frame {
    position: relative;

    aspect-ratio: 1 / 1;

    border-radius: 18px;
    overflow: hidden;

    background: var(--accent-bg);
}

/* A real photo gets a neutral off-white stage so the frame reads as the
   same shape every time; portrait and landscape shots letterbox into it.
   The category tint is kept only for the no-photo tile. */
.pdp-frame.has-image {
    background: var(--nx-line-soft);
}

/* contain, not cover: a product page should show the whole item. */
.pdp-frame-image {
    position: absolute;
    inset: 0;

    width: 100%;
    height: 100%;

    object-fit: contain;
}

.pdp-fade-enter-active,
.pdp-fade-leave-active {
    transition: opacity 0.2s ease;
}

.pdp-fade-enter-from,
.pdp-fade-leave-to {
    opacity: 0;
}

.pdp-frame-fallback {
    position: absolute;
    inset: 0;

    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 14px;
}

.pdp-frame-fallback p {
    margin: 0;

    color: var(--nx-muted);
    font-size: 13px;
    font-weight: 600;
}

.pdp-frame-badge {
    position: absolute;
    top: 14px;
    left: 14px;

    padding: 4px 10px;

    border-radius: 999px;
    background: var(--nx-deal);
    color: #ffffff;

    font-size: 12px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}

.pdp-zoom {
    position: absolute;
    top: 14px;
    right: 14px;

    width: 40px;
    height: 40px;

    display: flex;
    align-items: center;
    justify-content: center;

    border: none;
    border-radius: 999px;
    background: #ffffff;
    color: var(--nx-ink);
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.12);

    cursor: zoom-in;
    transition: transform 0.15s ease;
}

.pdp-zoom:hover {
    transform: scale(1.06);
}

.pdp-zoom:focus-visible {
    outline: 2px solid var(--nx-accent);
    outline-offset: 2px;
}

.pdp-thumbs-row {
    display: flex;
    align-items: center;
    gap: 12px;
}

.pdp-round-button {
    flex-shrink: 0;

    width: 36px;
    height: 36px;

    display: flex;
    align-items: center;
    justify-content: center;

    border: 1px solid var(--nx-line);
    border-radius: 999px;
    background: #ffffff;
    color: var(--nx-ink);

    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease, transform 0.1s ease;
}

.pdp-round-button:hover:not(:disabled) {
    border-color: var(--nx-accent);
    background: var(--nx-accent-soft);
}

.pdp-round-button:active:not(:disabled) {
    transform: scale(0.94);
}

.pdp-round-button:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.pdp-round-button:focus-visible {
    outline: 2px solid var(--nx-accent);
    outline-offset: 2px;
}

.pdp-thumbs {
    flex: 1;
    min-width: 0;

    display: grid;
    grid-auto-flow: column;
    grid-auto-columns: calc((100% - 36px) / 4);
    gap: 12px;

    overflow-x: auto;
    scroll-snap-type: x proximity;
    scrollbar-width: none;
    padding: 3px;
}

.pdp-thumbs::-webkit-scrollbar {
    display: none;
}

.pdp-thumb {
    aspect-ratio: 1 / 1;
    scroll-snap-align: start;

    padding: 0;

    border: 2px solid transparent;
    border-radius: 10px;
    background: var(--nx-line-soft);
    overflow: hidden;

    cursor: pointer;
    transition: border-color 0.15s ease, opacity 0.15s ease;
}

.pdp-thumb img {
    width: 100%;
    height: 100%;

    object-fit: cover;
}

.pdp-thumb:not(.is-active) {
    opacity: 0.75;
}

.pdp-thumb:hover {
    opacity: 1;
}

.pdp-thumb.is-active {
    border-color: var(--nx-ink);
}

.pdp-thumb:focus-visible {
    outline: 2px solid var(--nx-accent);
    outline-offset: 2px;
}

/* ---------------- Buy box ---------------- */

.pdp-buy {
    display: flex;
    flex-direction: column;
    gap: 22px;

    min-width: 0;
}

.pdp-heading {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
}

.pdp-title {
    margin: 0;

    color: var(--nx-ink);

    font-size: clamp(26px, 2.5vw, 32px);
    font-weight: 800;
    line-height: 1.15;
    letter-spacing: -0.4px;
    overflow-wrap: anywhere;
}

.pdp-soldby {
    margin: 0;

    color: var(--nx-muted);
    font-size: 13.5px;
}

.pdp-soldby span {
    color: var(--nx-ink);
    font-weight: 600;
}

.pdp-rating {
    display: inline-flex;
    align-items: center;
    gap: 8px;

    margin-top: 2px;
    padding: 2px 0;

    border: none;
    background: none;

    font: inherit;
    cursor: pointer;
}

.pdp-rating-score {
    color: var(--nx-ink);
    font-size: 13.5px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.pdp-rating-count {
    color: var(--nx-muted);
    font-size: 13.5px;
}

.pdp-rating:hover .pdp-rating-count {
    color: var(--nx-accent-dark);
    text-decoration: underline;
    text-underline-offset: 3px;
}

.pdp-rating:focus-visible {
    outline: 2px solid var(--nx-accent);
    outline-offset: 3px;
    border-radius: 6px;
}

.pdp-pricebox {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.pdp-price-row {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 6px 12px;
}

.pdp-price {
    color: var(--nx-ink);

    font-size: 30px;
    font-weight: 800;
    letter-spacing: -0.5px;
    font-variant-numeric: tabular-nums;
}

.pdp-old-price {
    color: var(--nx-muted-2);

    font-size: 16px;
    font-weight: 600;
    text-decoration: line-through;
    font-variant-numeric: tabular-nums;
}

.pdp-savings {
    padding: 2px 9px;

    border-radius: 999px;
    background: var(--nx-deal-soft);
    color: var(--nx-deal);

    font-size: 12.5px;
    font-weight: 700;
}

.pdp-shipfrom {
    margin: 0;

    color: var(--nx-text-2);
    font-size: 13.5px;
}

.pdp-stock {
    display: inline-flex;
    align-items: center;
    gap: 8px;

    margin: 2px 0 0;

    font-size: 13.5px;
    font-weight: 650;
}

.pdp-stock::before {
    content: "";

    width: 8px;
    height: 8px;

    border-radius: 999px;
    background: currentColor;
}

.pdp-stock--in { color: var(--nx-accent-dark); }
.pdp-stock--low { color: #b45309; }
.pdp-stock--out { color: #b91c1c; }
.pdp-stock--neutral { color: var(--nx-muted); }

.pdp-excerpt {
    margin: 0;
    max-width: 58ch;

    color: var(--nx-text-2);
    font-size: 15px;
    line-height: 1.6;
}

.pdp-text-button {
    align-self: flex-start;

    padding: 2px 0;

    border: none;
    background: none;

    color: var(--nx-accent-dark);
    font: inherit;
    font-size: 13.5px;
    font-weight: 700;

    cursor: pointer;
}

.pdp-excerpt .pdp-text-button,
.pdp-text-button--inline {
    margin-left: 4px;
}

.pdp-text-button:hover {
    text-decoration: underline;
    text-underline-offset: 3px;
}

.pdp-text-button:focus-visible {
    outline: 2px solid var(--nx-accent);
    outline-offset: 2px;
    border-radius: 4px;
}

/* ---------------- Options ---------------- */

.pdp-options {
    display: flex;
    flex-direction: column;
    gap: 20px;

    padding-top: 22px;
    border-top: 1px solid var(--nx-line-soft);
}

.pdp-option {
    margin: 0;
    padding: 0;

    border: none;
    min-width: 0;
}

.pdp-option-legend {
    display: block;

    margin-bottom: 10px;
    padding: 0;

    color: var(--nx-ink);
    font-size: 13.5px;
    font-weight: 700;
}

.pdp-option-chosen {
    color: var(--nx-muted);
    font-weight: 500;
}

.pdp-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.pdp-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;

    min-width: 64px;
    min-height: 44px;
    padding: 0 16px;

    border: 1.5px solid var(--nx-line);
    border-radius: 10px;
    background: #ffffff;
    color: var(--nx-ink);

    font-size: 13.5px;
    font-weight: 650;

    cursor: pointer;
    transition: border-color 0.15s ease, background 0.15s ease;
}

.pdp-chip:hover:not(.is-soldout):not(.is-unavailable) {
    border-color: var(--nx-muted-2);
}

/* Selected: a dark outline + check, not a solid fill, so it doesn't
   compete with the Add to Cart button. */
.pdp-chip.is-selected {
    border: 2px solid var(--nx-ink);
    background: var(--nx-sunken);
}

.pdp-chip.is-selected::after {
    content: "";

    width: 6px;
    height: 10px;

    border: solid var(--nx-ink);
    border-width: 0 2px 2px 0;
    transform: rotate(45deg) translateY(-1px);
}

/* Exists, but can't be bought right now. */
.pdp-chip.is-soldout {
    border-color: #e8edf1;
    background: #f5f7f9;
    color: var(--nx-muted-2);
    text-decoration: line-through;
    cursor: not-allowed;
}

/* Never made alongside the buyer's other choice. */
.pdp-chip.is-unavailable {
    border-style: dashed;
    color: var(--nx-muted-2);
    cursor: not-allowed;
}

.pdp-chip:has(input:focus-visible) {
    outline: 2px solid var(--nx-accent);
    outline-offset: 2px;
}

.pdp-option-hint {
    margin: 8px 0 0;

    color: var(--nx-muted);
    font-size: 12.5px;
}

/* ---------------- Actions ---------------- */

.pdp-actions {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.pdp-buyrow {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) 50px;
    gap: 10px;
    align-items: stretch;
}

.pdp-qty {
    display: inline-flex;
    align-items: center;

    border: 1.5px solid var(--nx-line);
    border-radius: 10px;
    overflow: hidden;
}

.pdp-qty button {
    width: 42px;
    height: 48px;

    display: flex;
    align-items: center;
    justify-content: center;

    border: none;
    background: #ffffff;
    color: var(--nx-ink);

    cursor: pointer;
    transition: background 0.15s ease;
}

.pdp-qty button:hover:not(:disabled) {
    background: var(--nx-line-soft);
}

.pdp-qty button:disabled {
    color: var(--nx-line-strong);
    cursor: not-allowed;
}

.pdp-qty button:focus-visible {
    outline: 2px solid var(--nx-accent);
    outline-offset: -2px;
}

.pdp-qty-value {
    min-width: 30px;

    color: var(--nx-ink);
    text-align: center;
    font-size: 15px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.pdp-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;

    min-height: 50px;
    padding: 0 20px;

    border: 1.5px solid transparent;
    border-radius: 10px;

    font: inherit;
    font-size: 14.5px;
    font-weight: 700;
    white-space: nowrap;

    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease, transform 0.1s ease;
}

.pdp-button:active:not(:disabled) {
    transform: scale(0.98);
}

.pdp-button:focus-visible {
    outline: 2px solid var(--nx-accent);
    outline-offset: 2px;
}

.pdp-button:disabled {
    cursor: not-allowed;
}

.pdp-button--primary {
    background: var(--nx-accent);
    color: #ffffff;
}

.pdp-button--primary:hover:not(:disabled) {
    background: var(--nx-accent-dark);
}

.pdp-button--primary:disabled {
    background: #cfe9e6;
    color: #4d7f7a;
}

.pdp-button--secondary {
    border-color: var(--nx-ink);
    background: #ffffff;
    color: var(--nx-ink);
}

.pdp-button--secondary:hover:not(:disabled) {
    background: var(--nx-ink);
    color: #ffffff;
}

.pdp-button--secondary:disabled {
    border-color: var(--nx-line);
    color: var(--nx-muted-2);
}

.pdp-button--ghost {
    min-height: 44px;
    padding: 0 16px;

    border-color: var(--nx-line);
    background: #ffffff;
    color: var(--nx-accent-dark);

    font-size: 13.5px;
}

.pdp-button--ghost:hover:not(:disabled) {
    border-color: var(--nx-accent);
    background: var(--nx-accent-soft);
}

.pdp-favorite {
    height: 50px;

    display: flex;
    align-items: center;
    justify-content: center;

    border: 1.5px solid var(--nx-line);
    border-radius: 10px;
    background: #ffffff;
    color: var(--nx-ink);

    cursor: pointer;
    transition: color 0.15s ease, border-color 0.15s ease, background 0.15s ease, transform 0.1s ease;
}

.pdp-favorite:hover {
    color: #ef4444;
    border-color: #fecaca;
}

.pdp-favorite.is-favorite {
    color: #ef4444;
    border-color: #fecaca;
    background: #fef2f2;
}

.pdp-favorite:active {
    transform: scale(0.94);
}

.pdp-favorite:focus-visible {
    outline: 2px solid var(--nx-accent);
    outline-offset: 2px;
}

.pdp-purchase-note {
    margin: 2px 0 0;

    font-size: 13px;
    font-weight: 600;
}

.pdp-purchase-note--neutral {
    color: var(--nx-muted);
}

.pdp-purchase-note--warning {
    color: #b91c1c;
}

/* ---------------- Facts: delivery / returns / seller ---------------- */

.pdp-facts {
    display: flex;
    flex-direction: column;

    margin: 0;

    border-top: 1px solid var(--nx-line-soft);
}

.pdp-fact {
    display: grid;
    grid-template-columns: 96px minmax(0, 1fr) auto;
    gap: 12px;
    align-items: baseline;

    padding: 13px 0;

    border-bottom: 1px solid var(--nx-line-soft);

    font-size: 13.5px;
}

.pdp-fact dt {
    color: var(--nx-muted);
}

.pdp-fact dd {
    margin: 0;

    display: flex;
    flex-direction: column;
    gap: 2px;

    color: var(--nx-ink);
}

.pdp-fact-seller {
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;

    font-weight: 600;
}

.pdp-fact-sub {
    color: var(--nx-muted);
    font-size: 12.5px;
}

.pdp-fact-action {
    font-size: 13px;
}

/* ---------------- Message composer ---------------- */

.pdp-composer {
    display: flex;
    flex-direction: column;
    gap: 8px;

    margin-top: -8px;
}

.pdp-composer-label {
    color: var(--nx-ink);
    font-size: 13px;
    font-weight: 700;
}

.pdp-composer textarea {
    width: 100%;
    padding: 12px;

    border: 1.5px solid var(--nx-line);
    border-radius: 10px;

    color: var(--nx-ink);
    font: inherit;
    font-size: 14px;

    resize: vertical;
}

.pdp-composer textarea::placeholder {
    color: var(--nx-muted-2);
}

.pdp-composer textarea:focus {
    outline: none;
    border-color: var(--nx-accent);
    box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.14);
}

.pdp-composer-error {
    margin: 0;

    color: #b91c1c;
    font-size: 12.5px;
}

.pdp-composer-send {
    align-self: flex-start;
    min-height: 42px;
}

/* ---------------- Lower: in-page menu + sections ---------------- */

.pdp-lower {
    display: grid;
    grid-template-columns: 200px minmax(0, 1fr);
    gap: 56px;

    margin-top: 72px;
}

.pdp-subnav {
    position: sticky;
    top: 124px;
    align-self: start;

    display: flex;
    flex-direction: column;
    gap: 2px;
}

.pdp-subnav-link {
    padding: 8px 12px;

    border: none;
    border-radius: 8px;
    background: none;

    color: var(--nx-muted);
    font: inherit;
    font-size: 14px;
    font-weight: 600;
    text-align: left;

    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease;
}

.pdp-subnav-link:hover {
    color: var(--nx-ink);
}

.pdp-subnav-link.is-active {
    background: var(--nx-line-soft);
    color: var(--nx-ink);
}

.pdp-subnav-link:focus-visible {
    outline: 2px solid var(--nx-accent);
    outline-offset: 2px;
}

.pdp-sections {
    display: flex;
    flex-direction: column;
    gap: 64px;

    min-width: 0;
}

.pdp-section {
    scroll-margin-top: 128px;
}

.pdp-section-title {
    margin: 0 0 18px;

    color: var(--nx-ink);

    font-size: 21px;
    font-weight: 800;
    letter-spacing: -0.2px;
}

.pdp-description {
    margin: 0 0 24px;
    max-width: 68ch;

    color: var(--nx-text-2);
    font-size: 15px;
    line-height: 1.7;
    white-space: pre-line;
}

.pdp-specs {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    column-gap: 40px;

    margin: 0;
}

.pdp-spec {
    display: grid;
    grid-template-columns: minmax(120px, 40%) minmax(0, 1fr);
    gap: 12px;

    padding: 11px 0;

    border-bottom: 1px solid #f1f4f7;

    font-size: 14px;
}

.pdp-spec dt {
    color: var(--nx-muted);
}

.pdp-spec dd {
    margin: 0;

    color: var(--nx-ink);
    font-weight: 600;
    overflow-wrap: anywhere;
}

/* ---------------- Reviews ---------------- */

.pdp-reviews {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.1fr);
    gap: 40px;
    align-items: start;
}

.pdp-reviews-summary {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 32px;
    align-items: center;
}

.pdp-reviews-score {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.pdp-reviews-number {
    color: var(--nx-ink);

    font-size: 48px;
    font-weight: 800;
    line-height: 1;
    letter-spacing: -1px;
    font-variant-numeric: tabular-nums;
}

.pdp-reviews-based {
    margin: 0;

    color: var(--nx-muted);
    font-size: 12.5px;
}

.pdp-breakdown {
    display: flex;
    flex-direction: column;
    gap: 10px;

    margin: 0;
    padding: 0;

    list-style: none;
}

.pdp-breakdown-row {
    display: grid;
    grid-template-columns: 32px minmax(0, 1fr) 40px;
    align-items: center;
    gap: 10px;
}

.pdp-breakdown-star {
    display: inline-flex;
    align-items: center;
    gap: 4px;

    color: var(--nx-ink);
    font-size: 13px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}

.pdp-breakdown-star svg {
    color: #f59e0b;
}

.pdp-breakdown-bar {
    height: 8px;

    border-radius: 999px;
    background: var(--nx-line-soft);
    overflow: hidden;
}

.pdp-breakdown-bar.is-loading {
    animation: pdp-pulse 1.2s ease-in-out infinite;
}

.pdp-breakdown-fill {
    display: block;

    height: 100%;

    border-radius: 999px;
    background: var(--nx-accent);
}

.pdp-breakdown-percent {
    color: var(--nx-muted);
    font-size: 12.5px;
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.pdp-review-card {
    display: flex;
    flex-direction: column;
    gap: 10px;

    min-height: 200px;
    padding: 22px;

    border: 1px solid #edf1f5;
    border-radius: 16px;
    background: #ffffff;
}

.pdp-review-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
}

.pdp-review-author {
    margin: 0;

    color: var(--nx-ink);
    font-size: 14px;
    font-weight: 700;
}

.pdp-review-date {
    margin: 0;

    color: var(--nx-muted);
    font-size: 12.5px;
    white-space: nowrap;
}

.pdp-review-comment {
    margin: 4px 0 0;

    color: var(--nx-text-2);
    font-size: 14px;
    line-height: 1.6;

    display: -webkit-box;
    -webkit-line-clamp: 5;
    line-clamp: 5;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.pdp-review-comment--empty {
    color: var(--nx-muted);
    font-style: italic;
}

.pdp-review-verified {
    margin: 0;

    color: var(--nx-accent-dark);
    font-size: 12px;
    font-weight: 600;
}

.pdp-review-footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;

    margin-top: auto;
    padding-top: 12px;
}

.pdp-review-nav {
    display: flex;
    align-items: center;
    gap: 12px;
}

.pdp-review-dots {
    display: flex;
    gap: 2px;
}

/* 24px hit area around a small visible dot. */
.pdp-review-dot {
    width: 24px;
    height: 24px;

    display: flex;
    align-items: center;
    justify-content: center;

    padding: 0;

    border: none;
    background: none;

    cursor: pointer;
}

.pdp-review-dot::before {
    content: "";

    width: 7px;
    height: 7px;

    border-radius: 999px;
    background: var(--nx-line-strong);

    transition: background 0.15s ease, width 0.15s ease;
}

.pdp-review-dot.is-active::before {
    width: 18px;
    background: var(--nx-ink);
}

.pdp-review-dot:focus-visible {
    outline: 2px solid var(--nx-accent);
    outline-offset: 1px;
    border-radius: 999px;
}

.pdp-review-error p {
    margin: 0;

    color: var(--nx-muted);
    font-size: 13.5px;
}

.pdp-review-skeleton {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.pdp-skeleton {
    display: block;

    height: 12px;

    border-radius: 6px;
    background: var(--nx-line-soft);
    animation: pdp-pulse 1.2s ease-in-out infinite;
}

.pdp-skeleton--short { width: 40%; }
.pdp-skeleton--tiny { width: 25%; }

@keyframes pdp-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.55; }
}

/* ---------------- Empty states ---------------- */

.pdp-empty {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;

    padding: 22px;

    border: 1px dashed #d6dee6;
    border-radius: 16px;
    background: #ffffff;
}

.pdp-empty-title {
    margin: 0;

    color: var(--nx-ink);
    font-size: 15px;
    font-weight: 700;
}

.pdp-empty-text {
    margin: 0 0 6px;

    color: var(--nx-muted);
    font-size: 13.5px;
}

/* ---------------- Mobile accordion ---------------- */

.pdp-accordion {
    border-top: 1px solid var(--nx-line-soft);
    border-bottom: 1px solid var(--nx-line-soft);
}

.pdp-accordion summary {
    display: flex;
    align-items: center;
    justify-content: space-between;

    min-height: 52px;

    color: var(--nx-ink);
    font-size: 16px;
    font-weight: 700;

    list-style: none;
    cursor: pointer;
}

.pdp-accordion summary::-webkit-details-marker {
    display: none;
}

.pdp-accordion summary::after {
    content: "+";

    color: var(--nx-muted);
    font-size: 20px;
    font-weight: 500;
}

.pdp-accordion[open] summary::after {
    content: "\2212";
}

.pdp-accordion summary:focus-visible {
    outline: 2px solid var(--nx-accent);
    outline-offset: 2px;
    border-radius: 4px;
}

.pdp-accordion-body {
    padding-bottom: 16px;
}

/* ---------------- Mobile buy bar ---------------- */

.pdp-buybar {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 40;

    display: flex;
    align-items: center;
    gap: 12px;

    padding: 10px 16px calc(12px + env(safe-area-inset-bottom, 0px));

    border-top: 1px solid var(--nx-line);
    background: #ffffff;
    box-shadow: 0 -6px 18px rgba(15, 23, 42, 0.06);
}

.pdp-buybar-info {
    flex: 1;
    min-width: 0;

    display: flex;
    flex-direction: column;
}

.pdp-buybar-price {
    color: var(--nx-ink);
    font-size: 16px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}

.pdp-buybar-choice {
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;

    color: var(--nx-muted);
    font-size: 12.5px;
}

.pdp-buybar-button {
    min-height: 46px;
}

.pdp-bar-enter-active,
.pdp-bar-leave-active {
    transition: transform 0.22s ease;
}

.pdp-bar-enter-from,
.pdp-bar-leave-to {
    transform: translateY(100%);
}

/* ---------------- Not found ---------------- */

.pdp-not-found {
    min-height: 60vh;
    padding: 48px 24px;

    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;

    text-align: center;
}

.pdp-not-found h1 {
    margin: 0;

    color: var(--nx-ink);
    font-size: 24px;
    font-weight: 800;
}

.pdp-not-found p {
    margin: 0 0 8px;
    color: var(--nx-muted);
}

/* ---------------- Image viewer ---------------- */

.pdp-viewer {
    position: fixed;
    inset: 0;
    z-index: 1000;

    display: flex;
    align-items: center;
    justify-content: center;

    padding: 64px 80px;

    background: rgba(15, 23, 42, 0.92);
}

.pdp-viewer-image {
    max-width: 100%;
    max-height: 100%;

    border-radius: 12px;
    object-fit: contain;
}

.pdp-viewer-close,
.pdp-viewer-nav {
    position: absolute;

    width: 48px;
    height: 48px;

    display: flex;
    align-items: center;
    justify-content: center;

    border: none;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.12);
    color: #ffffff;

    cursor: pointer;
    transition: background 0.15s ease;
}

.pdp-viewer-close:hover,
.pdp-viewer-nav:hover {
    background: rgba(255, 255, 255, 0.24);
}

.pdp-viewer-close:focus-visible,
.pdp-viewer-nav:focus-visible {
    outline: 2px solid #ffffff;
    outline-offset: 2px;
}

.pdp-viewer-close {
    top: 16px;
    right: 16px;
}

.pdp-viewer-nav {
    top: 50%;
    transform: translateY(-50%);
}

.pdp-viewer-nav--prev { left: 16px; }
.pdp-viewer-nav--next { right: 16px; }

/* ---------------- Responsive ---------------- */

@media (max-width: 1100px) {
    .pdp-reviews {
        grid-template-columns: 1fr;
        gap: 28px;
    }
}

@media (max-width: 960px) {
    .pdp {
        padding: 12px 16px 72px;
        gap: 16px;
    }

    .pdp-main {
        grid-template-columns: 1fr;
        gap: 22px;
    }

    .pdp-gallery {
        position: static;
    }

    /* Shorter than square so the price and stock line land in the first
       screen instead of below a full-width photo. */
    .pdp-frame {
        aspect-ratio: 4 / 3.3;
        border-radius: 14px;
    }

    .pdp-buy {
        gap: 18px;
    }

    .pdp-price {
        font-size: 26px;
    }

    .pdp-lower {
        grid-template-columns: 1fr;
        gap: 0;

        margin-top: 40px;
    }

    .pdp-sections {
        gap: 40px;
    }

    .pdp-specs {
        grid-template-columns: 1fr;
    }

    .pdp-fact {
        grid-template-columns: 84px minmax(0, 1fr);
    }

    .pdp-fact-action {
        grid-column: 2;
        justify-self: start;
    }

    /* Related products become a swipeable row instead of a tall grid. */
    .pdp-related {
        grid-template-columns: none;
        grid-auto-flow: column;
        grid-auto-columns: 46%;
        gap: 12px;

        overflow-x: auto;
        scroll-snap-type: x proximity;
        scrollbar-width: none;
    }

    .pdp-related::-webkit-scrollbar {
        display: none;
    }

    .pdp-related > * {
        scroll-snap-align: start;
    }
}

@media (max-width: 560px) {
    .pdp-reviews-summary {
        grid-template-columns: 1fr;
        gap: 24px;
    }

    .pdp-viewer {
        padding: 72px 12px;
    }

    .pdp-viewer-nav {
        top: auto;
        bottom: 16px;
        transform: none;
    }
}

@media (prefers-reduced-motion: reduce) {
    .pdp-fade-enter-active,
    .pdp-fade-leave-active,
    .pdp-bar-enter-active,
    .pdp-bar-leave-active,
    .pdp-thumb,
    .pdp-chip,
    .pdp-button,
    .pdp-favorite,
    .pdp-round-button,
    .pdp-zoom,
    .pdp-crumb-link,
    .pdp-subnav-link,
    .pdp-review-dot::before {
        transition: none;
    }

    .pdp-button:active:not(:disabled),
    .pdp-favorite:active,
    .pdp-round-button:active:not(:disabled),
    .pdp-zoom:hover {
        transform: none;
    }

    .pdp-breakdown-bar.is-loading,
    .pdp-skeleton {
        animation: none;
    }
}
</style>
