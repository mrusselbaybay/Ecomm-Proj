<script setup>
/*
|--------------------------------------------------------------------------
| ProductDetails
|--------------------------------------------------------------------------
|
| Layout
|   Desktop: gallery (thumbnail rail + large uncropped image) beside the
|   purchase panel, then "About this product" beside "Specifications",
|   then reviews (summary column + full list), then related products.
|   Mobile: swipeable gallery first, then the purchase panel, everything
|   else stacked; a compact buy bar appears only after the in-page Add to
|   cart row has scrolled away.
|
| Everything shown comes from the product API (ProductController@transform)
| and the public reviews endpoint — no invented ratings, discounts, sales
| counts or delivery promises. Delivery fees mirror useShipping.js and
| payment mirrors usePayment.js, the same sources checkout uses.
|
*/
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useBuyer } from '../composables/useBuyer';
import { useBuyerChat } from '../composables/useBuyerChat';
import { buyerApi } from '../composables/useBuyerApi';
import { requestBuyerView } from '../composables/useBuyerNav';
import { useBuyerSession } from '../composables/useBuyerSession';
import { rememberStoreState, rememberedStoreState } from '../composables/useStoreBrowseState';
import { fetchJson, joinedLabel, storeEndpoint, storeProductsEndpoint } from '../composables/useStores';
import { metaFor, formatPrice } from '../composables/useCategoryMeta';
import { shippingOptions } from '../composables/useShipping';
import { paymentMethods } from '../composables/usePayment';
import { useToasts } from '../composables/useToasts';
import { reviewsVersion } from '../composables/useReviewSync';
import Footer from './Footer.vue';
import Header from './Header.vue';
import ProductCard from './ProductCard.vue';
import ProductVoucherCards from './ProductVoucherCards.vue';
import StarRating from './StarRating.vue';
import StoreLogo from './StoreLogo.vue';
import VariantPicker from './VariantPicker.vue';

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
const { warning, success, error: toastError } = useToasts();

const quantity = ref(1);
// Guards against a double-tap firing two adds before the button settles.
const isAdding = ref(false);
const justAdded = ref(false);
const selectedImageIndex = ref(0);
const failedImages = ref(new Set());

const prefersReducedMotion = typeof window !== 'undefined'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const scrollBehavior = prefersReducedMotion ? 'auto' : 'smooth';

/*
|--------------------------------------------------------------------------
| Compact (mobile) layout
|--------------------------------------------------------------------------
*/

const compactQuery = typeof window !== 'undefined' ? window.matchMedia('(max-width: 900px)') : null;
const isCompact = ref(compactQuery ? compactQuery.matches : false);

function handleCompactChange(event) {
    isCompact.value = event.matches;
}

/*
|--------------------------------------------------------------------------
| Variants
|--------------------------------------------------------------------------
|
| The buyer picks one of the seller's actual variants (product.variants),
| not one value per attribute. Each choice is labelled from that variant's
| own option_values, e.g. "Chicken / 500g / Cats", so only combinations the
| seller created can ever be chosen, and selection is by variant id.
|
| Attribute order is the product's option order (the order the seller set
| the options up in), then any extra keys a variant carries, alphabetically
| — never a hardcoded or category-specific list.
|
*/

const hasVariants = computed(() => !!props.product?.hasVariants);

const productOptions = computed(() => props.product?.options || []);

const productVariants = computed(() => props.product?.variants || []);

const selectedVariantId = ref(null);

// Set when the buyer tries to buy before choosing, so the picker explains
// itself right where the control is.
const showMissing = ref(false);

const variantPicker = ref(null);

function isBuyable(variant) {
    return variant.status === 'active' && variant.stock > 0;
}

function hasValue(value) {
    return value !== null && value !== undefined && String(value).trim() !== '';
}

function orderedValues(variant) {
    const values = variant.option_values || {};
    const known = productOptions.value.map((opt) => opt.name).filter((name) => hasValue(values[name]));
    const extra = Object.keys(values).filter((key) => !known.includes(key) && hasValue(values[key])).sort();

    return [...known, ...extra].map((key) => String(values[key]).trim());
}

// Groups related variants together by following the seller's value order
// inside each option (Beef / 100g, Beef / 250g, Chicken / 100g ...).
function valueRank(variant) {
    return productOptions.value.map((opt) => {
        const index = (opt.values || []).findIndex((v) => v.value === variant.option_values?.[opt.name]);

        return index === -1 ? Number.MAX_SAFE_INTEGER : index;
    });
}

const attributeNames = computed(() => {
    const names = productOptions.value.map((opt) => opt.name);

    return names.length > 1 ? names.join(' / ') : (names[0] || '');
});

const variantChoices = computed(() => {
    const prices = productVariants.value.map((v) => Number(v.price)).filter((price) => Number.isFinite(price));
    const pricesDiffer = new Set(prices).size > 1;

    return productVariants.value
        .map((variant, index) => ({ variant, index, rank: valueRank(variant) }))
        .sort((a, b) => {
            for (let i = 0; i < a.rank.length; i++) {
                if (a.rank[i] !== b.rank[i]) {
                    return a.rank[i] - b.rank[i];
                }
            }

            return a.index - b.index;
        })
        .map(({ variant }) => {
            const state = variant.status !== 'active' ? 'unavailable' : (variant.stock > 0 ? 'ok' : 'soldout');

            return {
                id: variant.id,
                label: orderedValues(variant).join(' / ') || variant.sku || 'Option',
                // The variant's own photo, else the product's first photo;
                // the picker falls back to a neutral icon.
                image: variant.image?.url || baseImages.value[0] || '',
                priceLabel: pricesDiffer && Number.isFinite(Number(variant.price)) ? formatPrice(variant.price) : '',
                state,
                note: state === 'soldout' ? 'Out of stock' : state === 'unavailable' ? 'Unavailable' : ''
            };
        });
});

const selectedVariant = computed(() =>
    productVariants.value.find((v) => v.id === selectedVariantId.value) || null
);

const selectedChoice = computed(() =>
    variantChoices.value.find((choice) => choice.id === selectedVariantId.value) || null
);

// Kept under this name: the pricing, stock and buy-bar logic below reads it.
const allOptionsSelected = computed(() => !!selectedVariant.value);

function clearSelection() {
    selectedVariantId.value = null;
    showMissing.value = false;
}

const anyVariantBuyable = computed(() => productVariants.value.some(isBuyable));

const selectionSummary = computed(() => {
    if (!hasVariants.value) {
        return '';
    }

    return selectedChoice.value ? selectedChoice.value.label : 'Choose an option';
});

const displaySku = computed(() => selectedVariant.value?.sku || (!hasVariants.value ? props.product?.sku : '') || '');

watch(selectedVariant, (variant) => {
    if (variant) {
        showMissing.value = false;
    }
});

/*
|--------------------------------------------------------------------------
| Pricing
|--------------------------------------------------------------------------
|
| Variant products show the variant's price; a range until one is picked
| when active variants differ. The compare-at price only applies while the
| shown price is the product's own price.
|
*/

const variantPrices = computed(() =>
    productVariants.value
        .filter((v) => v.status === 'active')
        .map((v) => Number(v.price))
        .filter((price) => Number.isFinite(price))
);

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

// Once the price has changed (another variant chosen), each new price
// settles in (.nx-value-in) so the change is noticed. Never on first show.
const priceChanged = ref(false);

const formattedPrice = computed(() => {
    if (displayPrice.value !== null) {
        return formatPrice(displayPrice.value);
    }

    if (variantPrices.value.length > 0) {
        return `${formatPrice(Math.min(...variantPrices.value))} – ${formatPrice(Math.max(...variantPrices.value))}`;
    }

    return props.product ? formatPrice(props.product.price) : '';
});

watch(formattedPrice, (next, previous) => {
    if (previous && next !== previous) {
        priceChanged.value = true;
    }
});

const isPriceRange = computed(() => displayPrice.value === null && variantPrices.value.length > 1);

const hasDiscount = computed(() => {
    const oldPrice = Number(props.product?.oldPrice);

    return displayPrice.value !== null
        && displayPrice.value === Number(props.product?.price)
        && Number.isFinite(oldPrice)
        && oldPrice > displayPrice.value;
});

const formattedOldPrice = computed(() => (hasDiscount.value ? formatPrice(props.product.oldPrice) : ''));

const savings = computed(() => (hasDiscount.value ? Number(props.product.oldPrice) - displayPrice.value : 0));

const discountPercent = computed(() =>
    (hasDiscount.value ? Math.round((1 - displayPrice.value / Number(props.product.oldPrice)) * 100) : 0)
);

const livePaymentMethods = paymentMethods.filter((method) => method.available);

/*
|--------------------------------------------------------------------------
| Gallery
|--------------------------------------------------------------------------
|
| product.images is the full normalized gallery. A selected variant's own
| photo is put first without dropping the rest. The API placeholder and
| images that fail to load are treated as "no image".
|
*/

const PLACEHOLDER_IMAGE = '/images/product-placeholder.svg';

const accentClass = computed(() =>
    (props.product ? 'accent-' + metaFor(props.product.category).accent : 'accent-slate')
);

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

function imageAlt(index) {
    if (!props.product) {
        return '';
    }

    return hasGallery.value
        ? `${props.product.name}, photo ${index + 1} of ${galleryImages.value.length}`
        : props.product.name;
}

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

// Photos that have finished loading. Until then the gallery box shows a
// shimmer at its final size, so nothing moves when the photo arrives.
const loadedImages = ref(new Set());

function markImageLoaded(src) {
    if (src && !loadedImages.value.has(src)) {
        loadedImages.value = new Set(loadedImages.value).add(src);
    }
}

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

// Mobile: a native scroll-snap track. Its scroll position is the source of
// truth while swiping; selecting an image elsewhere scrolls it into place.
const swipeTrack = ref(null);
let swipeFrame = 0;

function handleSwipeScroll() {
    cancelAnimationFrame(swipeFrame);
    swipeFrame = requestAnimationFrame(() => {
        const el = swipeTrack.value;

        if (el && el.clientWidth) {
            const index = Math.round(el.scrollLeft / el.clientWidth);

            if (index !== selectedImageIndex.value) {
                selectedImageIndex.value = index;
            }
        }
    });
}

watch(selectedImageIndex, (index) => {
    const el = swipeTrack.value;

    if (el && el.clientWidth && Math.round(el.scrollLeft / el.clientWidth) !== index) {
        el.scrollTo({ left: index * el.clientWidth, behavior: scrollBehavior });
    }
});

/*
|--------------------------------------------------------------------------
| Image viewer (product photos and review photos)
|--------------------------------------------------------------------------
|
| One full-screen dialog for both. Esc closes, arrows move between photos,
| Tab stays inside, and focus returns to whatever opened it.
|
*/

const viewer = ref(null); // { images: string[], index: number, label: string }
const viewerDialog = ref(null);
const viewerCloseButton = ref(null);
let viewerReturnFocus = null;

function openViewer(images, index, label, trigger) {
    if (!images?.length) {
        return;
    }

    viewerReturnFocus = trigger || document.activeElement;
    viewer.value = { images, index, label };
    document.body.style.overflow = 'hidden';
    nextTick(() => viewerCloseButton.value?.focus());
}

function openProductViewer(event) {
    openViewer(galleryImages.value, selectedImageIndex.value, `${props.product.name} photos`, event?.currentTarget);
}

function closeViewer() {
    if (!viewer.value) {
        return;
    }

    viewer.value = null;
    document.body.style.overflow = '';
    nextTick(() => viewerReturnFocus?.focus?.());
}

function moveViewer(step) {
    const total = viewer.value.images.length;

    viewer.value = { ...viewer.value, index: (viewer.value.index + step + total) % total };
}

function handleViewerKeydown(event) {
    if (event.key === 'Escape') {
        closeViewer();

        return;
    }

    if (viewer.value?.images.length > 1 && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
        moveViewer(event.key === 'ArrowLeft' ? -1 : 1);

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
| From real stock fields only: product.stock / lowStockThreshold, or the
| selected variant's own stock. No stock data at all means no line.
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

// One place decides the stock line, why buying is blocked, and whether to
// offer similar products — so they can never disagree.
const purchaseStatus = computed(() => {
    const product = props.product;

    if (!product) {
        return null;
    }

    // Opened from a search card, which carries no options: Dashboard is
    // fetching the full product, and nothing can be chosen until it lands.
    if (hasVariants.value && product.detailsPending) {
        return { tone: 'neutral', label: 'Loading options…', reason: 'Loading this product’s options…' };
    }

    if (hasVariants.value && product.detailsError) {
        return { tone: 'out', label: 'Options unavailable', reason: product.detailsError };
    }

    if (hasVariants.value) {
        if (!anyVariantBuyable.value) {
            return { tone: 'out', label: 'Out of stock', reason: 'Every option is sold out right now.', offerSimilar: true };
        }

        if (!allOptionsSelected.value) {
            return { tone: 'neutral', label: 'Choose an option to see stock', reason: 'Choose an option first.', guidance: true };
        }

        if (!selectedVariant.value) {
            return { tone: 'out', label: 'Not available in this combination', reason: 'This combination isn’t sold. Pick another option.' };
        }

        if (!isBuyable(selectedVariant.value)) {
            return { tone: 'out', label: 'Out of stock', reason: 'This combination is sold out. Try another option.' };
        }
    }

    if (stockCount.value === null) {
        return { tone: null, label: '', reason: '' };
    }

    if (stockCount.value <= 0) {
        return { tone: 'out', label: 'Out of stock', reason: 'This product is sold out right now.', offerSimilar: true };
    }

    const threshold = Number(product.lowStockThreshold);

    if (product.lowStockThreshold != null && Number.isFinite(threshold) && stockCount.value <= threshold) {
        return { tone: 'low', label: `Only ${stockCount.value} left`, reason: '' };
    }

    return { tone: 'in', label: 'In stock', reason: '' };
});

const purchaseBlockedReason = computed(() => purchaseStatus.value?.reason || '');

// "N available" beside the quantity control, only from a real stock number
// for something in stock right now.
const availableLabel = computed(() => {
    const count = stockCount.value;
    const tone = purchaseStatus.value?.tone;

    // Low stock already reads "Only N left", so the count isn't repeated.
    return typeof count === 'number' && count > 0 && tone === 'in'
        ? `${count.toLocaleString('en-PH')} available`
        : '';
});

// Units on delivered orders (ProductController soldCount); hidden at zero.
const soldCount = computed(() => Math.max(0, Math.floor(Number(props.product?.soldCount) || 0)));

// Missing choices keep the buttons usable so a press can point at what's
// missing; genuinely unbuyable states disable them.
const purchaseDisabled = computed(() => !!purchaseBlockedReason.value && !purchaseStatus.value?.guidance);

/*
|--------------------------------------------------------------------------
| Description + specifications
|--------------------------------------------------------------------------
*/

const LONG_DESCRIPTION = 520;

const descriptionParagraphs = computed(() =>
    (props.product?.description || '')
        .split(/\n\s*\n|\r?\n/)
        .map((p) => p.trim())
        .filter(Boolean)
);

const isLongDescription = computed(() => (props.product?.description || '').trim().length > LONG_DESCRIPTION);

const descriptionExpanded = ref(false);

const specEntries = computed(() => {
    const specs = props.product?.specifications;
    const entries = specs && typeof specs === 'object'
        ? Object.entries(specs).filter(([, value]) => value !== null && String(value).trim() !== '')
        : [];

    return props.product?.brand ? [['Brand', props.product.brand], ...entries] : entries;
});

const hasAbout = computed(() => descriptionParagraphs.value.length > 0 || specEntries.value.length > 0);

/*
|--------------------------------------------------------------------------
| Reviews
|--------------------------------------------------------------------------
|
| GET /api/products/{id}/reviews: newest first, filterable by exact star
| rating and "has photos", paginated. The summary is always unfiltered.
| The API has no sort parameter and no helpful votes, so neither is shown.
|
*/

const REVIEWS_PER_PAGE = 6;

const reviewCount = computed(() => Number(props.product?.reviewCount) || 0);

const hasReviews = computed(() => reviewCount.value > 0 && typeof props.product?.rating === 'number');

const reviewSummary = ref(null);
const reviewItems = ref([]);
const reviewMeta = ref(null);
const reviewsLoading = ref(false);
const reviewsLoadingMore = ref(false);
const reviewsError = ref('');
const reviewFilter = ref('all'); // 'all' | 'photos' | 1..5

let reviewsRequestId = 0;

async function fetchReviews(page) {
    const params = new URLSearchParams({ per_page: String(REVIEWS_PER_PAGE), page: String(page) });

    if (typeof reviewFilter.value === 'number') {
        params.set('rating', String(reviewFilter.value));
    }

    if (reviewFilter.value === 'photos') {
        params.set('has_images', '1');
    }

    const response = await fetch(
        `/api/products/${encodeURIComponent(props.product.id)}/reviews?${params}`,
        { headers: { Accept: 'application/json' } }
    );
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(body.message || 'Could not load reviews.');
    }

    return body;
}

async function loadReviews() {
    reviewsError.value = '';

    if (!props.product || !hasReviews.value) {
        reviewItems.value = [];
        reviewMeta.value = null;
        reviewsLoading.value = false;

        return;
    }

    const requestId = ++reviewsRequestId;

    reviewsLoading.value = true;

    try {
        const body = await fetchReviews(1);

        // A newer product or filter was requested while this was in flight.
        if (requestId !== reviewsRequestId) {
            return;
        }

        reviewSummary.value = body.summary || reviewSummary.value;
        reviewItems.value = body.data || [];
        reviewMeta.value = body.meta || null;
    } catch (err) {
        if (requestId === reviewsRequestId) {
            reviewsError.value = err?.message || 'Could not load reviews.';
            reviewItems.value = [];
        }
    } finally {
        if (requestId === reviewsRequestId) {
            reviewsLoading.value = false;
        }
    }
}

async function loadMoreReviews() {
    const meta = reviewMeta.value;

    if (!meta || meta.current_page >= meta.last_page || reviewsLoadingMore.value) {
        return;
    }

    const requestId = reviewsRequestId;

    reviewsLoadingMore.value = true;

    try {
        const body = await fetchReviews(meta.current_page + 1);

        if (requestId !== reviewsRequestId) {
            return;
        }

        reviewItems.value = [...reviewItems.value, ...(body.data || [])];
        reviewMeta.value = body.meta || meta;
    } catch (err) {
        warning(err?.message || 'Could not load more reviews.');
    } finally {
        reviewsLoadingMore.value = false;
    }
}

// Same product, new review numbers: the live product arrived (Dashboard
// refreshes it on open) or a review was written / edited / deleted in this
// tab (an edit can change only the text, hence reviewsVersion). Reload the
// list so it matches the rating shown above it.
watch(
    () => [props.product?.id, reviewCount.value, props.product?.rating, reviewsVersion.value],
    ([id], [previousId]) => {
        if (id && id === previousId) {
            loadReviews();
        }
    }
);

function setReviewFilter(filter) {
    reviewFilter.value = reviewFilter.value === filter ? 'all' : filter;
    loadReviews();
}

const ratingBreakdown = computed(() => {
    const summary = reviewSummary.value;
    const total = summary?.total || 0;

    return [5, 4, 3, 2, 1].map((star) => {
        const count = Number(summary?.breakdown?.[star]) || 0;

        return { star, count, percent: total > 0 ? Math.round((count / total) * 100) : 0 };
    });
});

const photoReviewCount = computed(() => Number(reviewSummary.value?.with_images) || 0);

const reviewFilterLabel = computed(() => {
    if (reviewFilter.value === 'photos') {
        return 'with photos';
    }

    return typeof reviewFilter.value === 'number' ? `with ${reviewFilter.value} ${reviewFilter.value === 1 ? 'star' : 'stars'}` : '';
});

// The heading and summary count: the loaded summary once it's in (same
// eligible reviews as the product's own count, App\Support\ReviewStats).
const reviewTotal = computed(() => Number(reviewSummary.value?.total ?? reviewCount.value) || 0);

/*
| Long reviews start clamped to five lines with "Read more". Expanding and
| collapsing ease the text's height between the two sizes (an accordion:
| the one place a height transition is the right tool), instantly under
| reduced motion.
*/

const LONG_REVIEW_CHARS = 420;
const LONG_REVIEW_LINES = 5;

const expandedReviews = ref(new Set());

function isLongReview(review) {
    const text = String(review?.comment || '');

    return text.length > LONG_REVIEW_CHARS || text.split('\n').length > LONG_REVIEW_LINES;
}

function toggleReview(review) {
    const next = new Set(expandedReviews.value);
    const expanding = !next.has(review.id);

    if (expanding) {
        next.add(review.id);
    } else {
        next.delete(review.id);
    }

    const el = document.getElementById(`pd-rtext-${review.id}`);

    if (!el || prefersReducedMotion) {
        expandedReviews.value = next;

        return;
    }

    el.style.maxHeight = `${el.getBoundingClientRect().height}px`;
    expandedReviews.value = next;

    nextTick(() => {
        const lineHeight = parseFloat(getComputedStyle(el).lineHeight) || 24;
        const target = expanding ? el.scrollHeight : lineHeight * LONG_REVIEW_LINES;
        let finished = false;

        const finish = () => {
            if (!finished) {
                finished = true;
                el.style.maxHeight = '';
                el.removeEventListener('transitionend', finish);
            }
        };

        el.getBoundingClientRect();
        el.style.maxHeight = `${target}px`;
        el.addEventListener('transitionend', finish);
        setTimeout(finish, 400);
    });
}

// A different product starts with every review collapsed.
watch(() => props.product?.id, () => {
    expandedReviews.value = new Set();
});

const hasMoreReviews = computed(() => !!reviewMeta.value && reviewMeta.value.current_page < reviewMeta.value.last_page);

function formatReviewDate(iso) {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime())
        ? ''
        : date.toLocaleDateString('en-PH', { day: 'numeric', month: 'short', year: 'numeric' });
}

function variantLabel(variant) {
    if (!variant) {
        return '';
    }

    if (typeof variant === 'string') {
        return variant;
    }

    if (typeof variant === 'object') {
        const values = variant.option_values || variant;

        return Object.entries(values)
            .filter(([, value]) => typeof value === 'string' || typeof value === 'number')
            .map(([key, value]) => `${key}: ${value}`)
            .join(', ');
    }

    return '';
}

const reviewsSection = ref(null);
const relatedSection = ref(null);

function scrollToReviews() {
    reviewsSection.value?.scrollIntoView({ behavior: scrollBehavior, block: 'start' });
}

/*
|--------------------------------------------------------------------------
| Mobile buy bar
|--------------------------------------------------------------------------
|
| Compact layout only, and only once the in-page quantity + Add to cart row
| has scrolled up past the header — it never duplicates a visible button.
|
| A position check on scroll (passive, one rAF per frame) rather than an
| IntersectionObserver: an observer only reports edge crossings, so an
| instant jump past the row (anchor scroll under reduced motion, scroll
| restore on Back) would never show the bar.
|
*/

const BUY_BAR_OFFSET = 120;

const buyRow = ref(null);
const optionsBlock = ref(null);
const buyRowPassed = ref(false);

let buyRowFrame = 0;

function checkBuyRow() {
    cancelAnimationFrame(buyRowFrame);
    buyRowFrame = requestAnimationFrame(() => {
        const rect = buyRow.value?.getBoundingClientRect();

        buyRowPassed.value = !!rect && rect.bottom < BUY_BAR_OFFSET;
    });
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

function focusFirstMissingOption() {
    optionsBlock.value?.scrollIntoView({ behavior: scrollBehavior, block: 'center' });
    nextTick(() => variantPicker.value?.focus());
}

function handleBuyBarAction() {
    if (buyBarAction.value === 'add') {
        handleAddToCart();

        return;
    }

    if (buyBarAction.value === 'choose') {
        showMissing.value = true;
    }

    focusFirstMissingOption();
}

/*
|--------------------------------------------------------------------------
| Favorite
|--------------------------------------------------------------------------
*/

const favorited = computed(() => (props.product ? isFavorite(props.product.id) : false));

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
| Client-side convenience only; the real limit is enforced server-side at
| checkout (CheckoutService locks and re-checks the actual row).
|
*/

const canIncreaseQuantity = computed(() =>
    !purchaseDisabled.value && (stockCount.value === null || quantity.value < stockCount.value)
);

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

watch(stockCount, (stock) => {
    if (stock !== null && stock > 0 && quantity.value > stock) {
        quantity.value = stock;
    }
});

/*
|--------------------------------------------------------------------------
| Add to cart / Buy now
|--------------------------------------------------------------------------
*/

function validateSelection() {
    if (!props.product) {
        return false;
    }

    if (purchaseStatus.value?.guidance) {
        showMissing.value = true;
        focusFirstMissingOption();

        return false;
    }

    if (purchaseBlockedReason.value) {
        return false;
    }

    if (stockCount.value !== null && quantity.value > stockCount.value) {
        warning(`Only ${stockCount.value} left in stock.`);

        return false;
    }

    return true;
}

let addedTimer = null;

function handleAddToCart() {
    if (isAdding.value || !validateSelection()) {
        return;
    }

    isAdding.value = true;

    // addToCart owns the "Added to cart." / stock-limit toast (useBuyer.js).
    const result = addToCart(props.product, selectedVariant.value, quantity.value);

    setTimeout(() => {
        isAdding.value = false;
    }, 300);

    if (result?.ok) {
        justAdded.value = true;
        clearTimeout(addedTimer);
        addedTimer = setTimeout(() => {
            justAdded.value = false;
        }, 1800);
    }
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
| The product carries the store name and line of business; the seller's
| storefront (StorePage.vue) has the rest, and is linked from "Sold by"
| and from the seller block.
|
*/

const sellerName = computed(() => props.product?.seller || 'Seller');


function visitStore() {
    if (!props.product?.seller_id) {
        return;
    }

    requestBuyerView('store', {
        id: props.product.seller_id,
        name: sellerName.value,
        category: props.product.seller_line_of_business || null
    });
}

/*
|--------------------------------------------------------------------------
| Shop overview (below the main container)
|--------------------------------------------------------------------------
|
| Who sells this and how the shop is doing, from the public store API:
| GET /api/stores/{id} (identity, product-review rating, active listings,
| join date, completed-sale totals, follower count), a few of the shop's
| product reviews (other than this product's, which have their own
| section) and a few of its other active products. Every number shown is
| one the API returns; a figure that is zero or missing is left out.
|
*/

const SHOP_REVIEWS = 3;
const SHOP_PRODUCTS = 4;

const shop = ref(null);
const shopReviews = ref([]);
const shopReviewTotal = ref(0);
const shopReviewsLoaded = ref(false);
const shopProducts = ref([]);
const shopLoading = ref(false);
const openingReviewProduct = ref(null);

const shopId = computed(() => props.product?.seller_id || null);

async function loadShop() {
    const id = shopId.value;
    const productId = props.product?.id;

    shop.value = null;
    shopReviews.value = [];
    shopReviewTotal.value = 0;
    shopReviewsLoaded.value = false;
    shopProducts.value = [];

    if (!id) {
        return;
    }

    shopLoading.value = true;

    const reviewParams = new URLSearchParams({ per_page: String(SHOP_REVIEWS) });

    if (productId) {
        reviewParams.set('exclude_product', productId);
    }

    const [storeResult, reviewsResult, productsResult] = await Promise.allSettled([
        fetchJson(storeEndpoint(id)),
        fetchJson(`/api/stores/${encodeURIComponent(id)}/reviews?${reviewParams}`),
        fetchJson(storeProductsEndpoint(id, { per_page: SHOP_PRODUCTS + 1 }))
    ]);

    // A newer product may have opened while these were loading.
    if (shopId.value !== id) {
        return;
    }

    if (storeResult.status === 'fulfilled') {
        shop.value = storeResult.value.data || null;
        followerCount.value = shop.value?.followerCount ?? null;
    }

    if (reviewsResult.status === 'fulfilled') {
        shopReviews.value = reviewsResult.value.data || [];
        shopReviewTotal.value = reviewsResult.value.summary?.total || 0;
    }

    shopReviewsLoaded.value = true;

    if (productsResult.status === 'fulfilled') {
        shopProducts.value = (productsResult.value.data || [])
            .filter(item => item.id !== productId)
            .slice(0, SHOP_PRODUCTS);
    }

    shopLoading.value = false;
}

watch(() => props.product?.id, loadShop, { immediate: true });

// Category picks below skip anything already shown from this shop.
const relatedToShow = computed(() => {
    const shown = new Set(shopProducts.value.map(item => item.id));

    return (props.relatedProducts || []).filter(item => !shown.has(item.id));
});

const shopName = computed(() => shop.value?.name || sellerName.value);
const shopHasRating = computed(() => typeof shop.value?.rating === 'number' && shop.value?.reviewCount > 0);
const shopJoined = computed(() => joinedLabel(shop.value?.joinedAt));

function compactCount(value) {
    const n = Number(value) || 0;

    return n >= 1000 ? `${(n / 1000).toFixed(1).replace(/\.0$/, '')}k` : String(n);
}

// Distinct numbers, each labelled for what it is. Zero means "not shown".
const shopStats = computed(() => {
    const s = shop.value;

    if (!s) {
        return [];
    }

    const stats = [];
    const sales = s.sales || {};

    if (s.productCount > 0) {
        stats.push({ key: 'listings', value: compactCount(s.productCount), label: s.productCount === 1 ? 'product listed' : 'products listed' });
    }

    if (sales.itemsSold > 0) {
        stats.push({ key: 'sold', value: compactCount(sales.itemsSold), label: sales.itemsSold === 1 ? 'item sold' : 'items sold' });
    }

    if (sales.buyerCount > 0) {
        stats.push({ key: 'buyers', value: compactCount(sales.buyerCount), label: sales.buyerCount === 1 ? 'buyer' : 'buyers' });
    }

    return stats;
});

function openShop(tab = 'products') {
    if (!shopId.value) {
        return;
    }

    rememberStoreState(shopId.value, { ...rememberedStoreState(shopId.value), tab, page: 1 });
    visitStore();
}

async function openReviewProduct(product) {
    if (!product?.id || openingReviewProduct.value) {
        return;
    }

    openingReviewProduct.value = product.id;

    try {
        const body = await fetchJson(`/api/products/${encodeURIComponent(product.id)}`);

        emit('select-product', body.data || body);
    } catch (err) {
        warning(err?.status === 404 ? 'That product is no longer available.' : 'Could not open that product. Please try again.');
    } finally {
        openingReviewProduct.value = null;
    }
}

function shopReviewDate(iso) {
    const date = iso ? new Date(iso) : null;

    return date && !Number.isNaN(date.getTime())
        ? date.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
        : '';
}

/*
| Follow shop: the real follow state from /api/buyer/follows/{id}, a
| button that waits for the server (so a double click can't send two
| requests), and a sign-in link for guests.
*/

const { buyerProfile } = useBuyerSession();

const isFollowing = ref(false);
const followerCount = ref(null);
const followBusy = ref(false);
const followReady = ref(false);

function applyFollowStatus(status) {
    isFollowing.value = Boolean(status?.isFollowing);

    if (typeof status?.followerCount === 'number') {
        followerCount.value = status.followerCount;
    }
}

async function loadFollowStatus() {
    followReady.value = false;
    isFollowing.value = false;

    if (!buyerProfile.value || !shopId.value) {
        followReady.value = true;

        return;
    }

    try {
        applyFollowStatus(await buyerApi(`/buyer/follows/${encodeURIComponent(shopId.value)}`));
    } catch {
        // The first toggle's response corrects the state if this failed.
    } finally {
        followReady.value = true;
    }
}

async function toggleFollow() {
    if (followBusy.value || !shopId.value) {
        return;
    }

    const following = isFollowing.value;

    followBusy.value = true;

    try {
        applyFollowStatus(await buyerApi(`/buyer/follows/${encodeURIComponent(shopId.value)}`, {
            method: following ? 'DELETE' : 'POST'
        }));
    } catch (err) {
        warning(err?.status === 401
            ? 'Your session has ended. Sign in again to follow shops.'
            : err?.message || 'Could not update your follow. Please try again.');
    } finally {
        followBusy.value = false;
    }
}

watch([shopId, buyerProfile], loadFollowStatus, { immediate: true });

const followerLabel = computed(() => {
    const count = followerCount.value;

    return count ? `${compactCount(count)} ${count === 1 ? 'follower' : 'followers'}` : '';
});

const { messageSeller } = useBuyerChat();
const reportDialogOpen = ref(false);
const reportTarget = ref('store');
const reportReason = ref('');
const reportDetails = ref('');
const reportAnonymous = ref(false);
const reportFiles = ref([]);
const reportBusy = ref(false);
const reportSubmitError = ref('');
const reportDropActive = ref(false);
const reportEvidenceInput = ref(null);
const reportPreviewUrls = new Map();
watch(reportDialogOpen, (isOpen) => {
    if (!isOpen) {
        for (const url of reportPreviewUrls.values()) URL.revokeObjectURL(url);
        reportPreviewUrls.clear();
    }
});
const blockedShop = ref(false);
const reportReasons = {
    store: [
        { value: 'fraud_deception', label: 'Fraud and Deception' },
        { value: 'legal_regulatory', label: 'Legal and Regulatory' },
        { value: 'discriminatory_offensive', label: 'Discriminatory or Offensive Conduct' },
        { value: 'policy_violations', label: 'Policy Violations' },
        { value: 'product_issues', label: 'Product Issues' },
        { value: 'pricing_fees', label: 'Pricing and Fees' },
        { value: 'shipping_problems', label: 'Shipping Problems' },
        { value: 'customer_service', label: 'Customer Service and Communication' },
    ],
    product: [
        { value: 'prohibited_items', label: 'Prohibited (Banned) Items' },
        { value: 'counterfeit_copyright', label: 'Counterfeits and Copyright' },
        { value: 'offensive_items', label: 'Offensive or Potentially Offensive Items' },
        { value: 'fraudulent_listing', label: 'Fraudulent Listings (illegal seller demands, etc.)' },
        { value: 'off_platform_transactions', label: 'Directing Transactions Outside Shopee' },
        { value: 'others', label: 'Others' },
    ],
};

watch([shopId, buyerProfile], async () => {
    blockedShop.value = false;

    if (!buyerProfile.value || !shopId.value) return;

    try {
        const ids = await buyerApi('/buyer/blocked-stores');
        blockedShop.value = (Array.isArray(ids?.data) ? ids.data : ids).includes(shopId.value);
    } catch {
        blockedShop.value = false;
    }
}, { immediate: true });

function openReport(target) {
    if (!buyerProfile.value) {
        warning('Sign in to report this product.');
        return;
    }

    reportTarget.value = target;
    reportReason.value = '';
    reportDetails.value = '';
    reportAnonymous.value = false;
    reportFiles.value = [];
    reportSubmitError.value = '';
    for (const url of reportPreviewUrls.values()) URL.revokeObjectURL(url);
    reportPreviewUrls.clear();
    reportDialogOpen.value = true;
}

function selectReportFiles(event) {
    addReportFiles(event.target.files);
    event.target.value = '';
}

function addReportFiles(fileList) {
    const next = [...reportFiles.value];
    for (const file of Array.from(fileList || [])) {
        if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/webm', 'video/quicktime'].includes(file.type)) {
            toastError(`${file.name} isn't a supported image or video.`);
            continue;
        }
        if (file.size > 50 * 1024 * 1024) {
            toastError(`${file.name} is larger than 50 MB.`);
            continue;
        }
        if (next.length >= 8) {
            warning('You can attach up to 8 files.');
            break;
        }
        if (!next.some(existing => existing.name === file.name && existing.size === file.size && existing.lastModified === file.lastModified)) next.push(file);
    }
    reportFiles.value = next;
}

function removeReportFile(index) {
    const [removed] = reportFiles.value.splice(index, 1);
    if (removed && reportPreviewUrls.has(removed)) {
        URL.revokeObjectURL(reportPreviewUrls.get(removed));
        reportPreviewUrls.delete(removed);
    }
}

function reportPreviewUrl(file) {
    if (!reportPreviewUrls.has(file)) reportPreviewUrls.set(file, URL.createObjectURL(file));
    return reportPreviewUrls.get(file);
}

function handleReportDrop(event) {
    reportDropActive.value = false;
    addReportFiles(event.dataTransfer?.files);
}

async function submitReport() {
    if (!shopId.value || !reportReason.value || !reportDetails.value.trim() || reportBusy.value) return;
    reportBusy.value = true;
    reportSubmitError.value = '';

    try {
        const evidence = await Promise.all(reportFiles.value.map(async (file) => {
            const form = new FormData();
            form.append('file', file);
            form.append('path', `${buyerProfile.value.id}/reports/${crypto.randomUUID()}-${file.name.replace(/[^A-Za-z0-9_.-]/g, '_')}`);
            const uploaded = await buyerApi('/storage/report-evidence', { method: 'POST', body: form });
            return uploaded.path;
        }));

        await buyerApi('/buyer/reports', {
            method: 'POST',
            body: JSON.stringify({
                target_type: reportTarget.value,
                target_id: reportTarget.value === 'product' ? props.product.id : shopId.value,
                reason: reportReason.value,
                details: reportDetails.value.trim(),
                anonymous: reportAnonymous.value,
                evidence,
            }),
        });

        reportDialogOpen.value = false;
        success(reportTarget.value === 'product' ? 'Product report submitted for review.' : 'Shop report submitted for review.');
    } catch (err) {
        reportSubmitError.value = err?.message || 'Could not submit this report. Your details are still here; please try again.';
    } finally {
        reportBusy.value = false;
    }
}

/** Opens Messages on this shop's thread, asking about this product. */
function messageShop() {
    if (!props.product?.seller_id || blockedShop.value) {
        if (blockedShop.value) warning('You have blocked this shop.');
        return;
    }

    messageSeller({
        sellerId: props.product.seller_id,
        seller: shopName.value,
        sellerLogo: shop.value?.logo || null,
        sellerCategory: shop.value?.category || props.product.seller_line_of_business || null,
        product: {
            id: props.product.id,
            name: props.product.name,
            price: displayPrice.value,
            image: galleryImages.value[0] || null
        }
    });
}

/*
|--------------------------------------------------------------------------
| Navigation
|--------------------------------------------------------------------------
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

function scrollToRelated() {
    relatedSection.value?.scrollIntoView({ behavior: scrollBehavior, block: 'start' });
}

/*
|--------------------------------------------------------------------------
| Lifecycle
|--------------------------------------------------------------------------
*/

onMounted(() => {
    compactQuery?.addEventListener('change', handleCompactChange);
    window.addEventListener('scroll', checkBuyRow, { passive: true });
    window.addEventListener('resize', checkBuyRow, { passive: true });
    checkBuyRow();
});

onUnmounted(() => {
    compactQuery?.removeEventListener('change', handleCompactChange);
    window.removeEventListener('scroll', checkBuyRow);
    window.removeEventListener('resize', checkBuyRow);
    cancelAnimationFrame(buyRowFrame);
    cancelAnimationFrame(swipeFrame);
    clearTimeout(addedTimer);

    if (viewer.value) {
        document.body.style.overflow = '';
    }
});

// A different product (e.g. from "You might also like") starts clean.
watch(
    () => props.product?.id,
    () => {
        // A single buyable variant needs no decision from the buyer.
        const variants = props.product?.variants || [];

        selectedVariantId.value = variants.length === 1 && isBuyable(variants[0]) ? variants[0].id : null;
        showMissing.value = false;
        selectedImageIndex.value = 0;
        quantity.value = 1;
        failedImages.value = new Set();
        descriptionExpanded.value = false;
        buyRowPassed.value = false;
        justAdded.value = false;
        reviewFilter.value = 'all';
        reviewSummary.value = null;
        closeViewer();
        loadReviews();
    },
    { immediate: true }
);
</script>

<template>

    <div
        class="buyer-page pd-page"
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
            id="main-content"
            class="pd"
            tabindex="-1"
        >

            <nav
                class="crumbs pd-crumbs"
                aria-label="Breadcrumb"
            >
                <ol>
                    <li>
                        <button
                            type="button"
                            @click="emit('browse-all')"
                        >
                            Home
                        </button>
                    </li>
                    <li>
                        <button
                            type="button"
                            @click="emit('select-category', product.category)"
                        >
                            {{ product.category }}
                        </button>
                    </li>
                    <li
                        class="pd-crumb-current"
                        aria-current="page"
                    >
                        {{ product.name }}
                    </li>
                </ol>
            </nav>

            <!-- ======================================================= -->
            <!-- GALLERY + PURCHASE PANEL -->
            <!-- ======================================================= -->

            <section
                class="pd-main"
                aria-labelledby="pd-title"
            >

                <div
                    class="pd-gallery"
                    :class="{ 'has-thumbs': hasGallery && !isCompact }"
                >

                    <!-- Desktop thumbnail rail -->
                    <div
                        v-if="hasGallery && !isCompact"
                        class="pd-thumbs"
                        role="group"
                        aria-label="Product photos"
                    >
                        <button
                            v-for="(src, index) in galleryImages"
                            :key="src"
                            type="button"
                            class="pd-thumb"
                            :class="{ 'is-active': index === selectedImageIndex }"
                            :aria-label="`Show photo ${index + 1} of ${galleryImages.length}`"
                            :aria-pressed="index === selectedImageIndex"
                            @click="selectImage(index)"
                        >
                            <img
                                :src="src"
                                alt=""
                                width="72"
                                height="72"
                                loading="lazy"
                                @error="handleImageError(src)"
                            >
                        </button>
                    </div>

                    <!-- Desktop main image -->
                    <div
                        v-if="!isCompact"
                        class="pd-stage"
                        :class="[accentClass, { 'is-loading': activeImage && !loadedImages.has(activeImage) }]"
                    >
                        <Transition name="pd-fade">
                            <button
                                v-if="activeImage"
                                :key="activeImage"
                                type="button"
                                class="pd-stage-zoom"
                                :aria-label="`Open larger view of ${imageAlt(selectedImageIndex)}`"
                                @click="openProductViewer"
                            >
                                <img
                                    class="pd-stage-img"
                                    :src="activeImage"
                                    :alt="imageAlt(selectedImageIndex)"
                                    width="800"
                                    height="800"
                                    fetchpriority="high"
                                    @load="markImageLoaded(activeImage)"
                                    @error="handleImageError(activeImage)"
                                >
                            </button>
                        </Transition>

                        <div
                            v-if="!activeImage"
                            class="pd-stage-empty"
                        >
                            <span
                                class="product-image-icon"
                                aria-hidden="true"
                                v-html="metaFor(product.category).icon"
                            ></span>
                            <p>No photo yet</p>
                        </div>

                        <template v-if="hasGallery">
                            <button
                                type="button"
                                class="pd-stage-nav is-prev"
                                aria-label="Previous photo"
                                @click="showPreviousImage"
                            >
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
                            </button>
                            <button
                                type="button"
                                class="pd-stage-nav is-next"
                                aria-label="Next photo"
                                @click="showNextImage"
                            >
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
                            </button>
                        </template>

                        <span
                            v-if="activeImage"
                            class="pd-stage-hint"
                            aria-hidden="true"
                        >
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5M11 8v6M8 11h6" /></svg>
                            Click to zoom
                        </span>
                    </div>

                    <!-- Mobile swipe gallery -->
                    <div
                        v-else
                        class="pd-swipe"
                        :class="accentClass"
                    >
                        <div
                            v-if="galleryImages.length"
                            ref="swipeTrack"
                            class="pd-swipe-track"
                            role="group"
                            aria-roledescription="carousel"
                            aria-label="Product photos"
                            @scroll.passive="handleSwipeScroll"
                        >
                            <button
                                v-for="(src, index) in galleryImages"
                                :key="src"
                                type="button"
                                class="pd-swipe-slide"
                                :class="{ 'is-loading': !loadedImages.has(src) }"
                                :aria-label="`Open larger view of ${imageAlt(index)}`"
                                @click="openViewer(galleryImages, index, `${product.name} photos`, $event.currentTarget)"
                            >
                                <img
                                    :src="src"
                                    :alt="imageAlt(index)"
                                    width="800"
                                    height="800"
                                    :loading="index === 0 ? 'eager' : 'lazy'"
                                    @load="markImageLoaded(src)"
                                    @error="handleImageError(src)"
                                >
                            </button>
                        </div>
                        <div
                            v-else
                            class="pd-stage-empty"
                        >
                            <span
                                class="product-image-icon"
                                aria-hidden="true"
                                v-html="metaFor(product.category).icon"
                            ></span>
                            <p>No photo yet</p>
                        </div>
                        <div
                            v-if="hasGallery"
                            class="pd-swipe-dots"
                        >
                            <button
                                v-for="(src, index) in galleryImages"
                                :key="`dot-${src}`"
                                type="button"
                                :class="{ 'is-active': index === selectedImageIndex }"
                                :aria-label="`Show photo ${index + 1} of ${galleryImages.length}`"
                                :aria-pressed="index === selectedImageIndex"
                                @click="selectImage(index)"
                            ></button>
                        </div>
                    </div>

                    <div class="pd-gallery-foot">
                        <button
                            type="button"
                            class="pd-fav"
                            :class="{ 'is-on': favorited }"
                            :aria-pressed="favorited"
                            @click="handleToggleFavorite"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" :fill="favorited ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 20.5s-7.5-4.6-9.2-9.4C1.7 7.9 3.9 4.5 7.4 4.5c2 0 3.5 1.1 4.6 2.7 1.1-1.6 2.6-2.7 4.6-2.7 3.5 0 5.7 3.4 4.6 6.6-1.7 4.8-9.2 9.4-9.2 9.4Z" />
                            </svg>
                            {{ favorited ? 'Saved to wishlist' : 'Save to wishlist' }}
                        </button>
                    </div>
                </div>

                <!-- Purchase panel: title, proof, price, terms, choice, buy -->
                <div class="pd-panel">

                    <div class="pd-title-row">
                        <h1 id="pd-title" class="pd-title">{{ product.name }}</h1>
                        <button
                            type="button"
                            class="pd-product-report-button"
                            aria-label="Report product"
                            title="Report product"
                            @click="openReport('product')"
                        >
                            <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 21V4"/><path d="M5 4c5-3 9 3 14 0v11c-5 3-9-3-14 0"/></svg>
                        </button>
                    </div>

                    <div class="pd-meta">
                        <button
                            v-if="hasReviews"
                            type="button"
                            class="pd-rating"
                            @click="scrollToReviews"
                        >
                            <span class="pd-rating-score">{{ product.rating.toFixed(1) }}</span>
                            <StarRating
                                :rating="product.rating"
                                :size="15"
                            />
                        </button>
                        <span
                            v-if="hasReviews"
                            class="pd-meta-sep"
                            aria-hidden="true"
                        ></span>
                        <button
                            v-if="hasReviews"
                            type="button"
                            class="pd-meta-stat"
                            @click="scrollToReviews"
                        >
                            <strong>{{ reviewCount.toLocaleString('en-PH') }}</strong> {{ reviewCount === 1 ? 'review' : 'reviews' }}
                        </button>
                        <span
                            v-else
                            class="pd-meta-muted"
                        >No reviews yet</span>
                        <template v-if="soldCount > 0">
                            <span
                                class="pd-meta-sep"
                                aria-hidden="true"
                            ></span>
                            <span class="pd-meta-stat">
                                <strong>{{ soldCount.toLocaleString('en-PH') }}</strong> sold
                            </span>
                        </template>
                        <span
                            class="pd-meta-sep"
                            aria-hidden="true"
                        ></span>
                        <span class="pd-meta-muted pd-meta-seller">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9.5 4.6 4h14.8L21 9.5" /><path d="M4 9.5V20h16V9.5" /><path d="M3 9.5a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0" /><path d="M10 20v-5h4v5" /></svg>
                            Sold by
                            <button
                                v-if="product.seller_id"
                                type="button"
                                class="pd-store-link"
                                @click="visitStore"
                            >{{ sellerName }}</button>
                            <strong v-else>{{ sellerName }}</strong>
                        </span>
                    </div>

                    <div class="pd-price-block">
                        <p class="pd-price-row">
                            <span
                                v-if="isPriceRange"
                                class="pd-price-from"
                            >Price range</span>
                            <span
                                :key="formattedPrice"
                                class="pd-price"
                                :class="{ 'nx-value-in': priceChanged }"
                            >{{ formattedPrice }}</span>
                            <s
                                v-if="hasDiscount"
                                class="pd-old-price"
                            ><span class="sr-only">Original price </span>{{ formattedOldPrice }}</s>
                            <span
                                v-if="hasDiscount"
                                class="pd-discount"
                            >-{{ discountPercent }}%</span>
                        </p>
                        <p
                            v-if="hasDiscount"
                            class="pd-savings"
                        >
                            You save {{ formatPrice(savings) }}
                        </p>
                        <p
                            v-if="isPriceRange"
                            class="pd-price-note"
                        >
                            Final price depends on the option you choose.
                        </p>
                    </div>

                    <ProductVoucherCards v-if="product?.id && displayPrice !== null" :product-id="product.id" :price="displayPrice" />

                    <!-- Terms: label column + value column -->
                    <dl class="pd-rows">
                        <div class="pd-row">
                            <dt>
                                <svg class="pd-row-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7" /><circle cx="7" cy="17.5" r="1.5" /><circle cx="17" cy="17.5" r="1.5" /></svg>
                                Shipping
                            </dt>
                            <dd>
                                <span
                                    v-for="option in shippingOptions"
                                    :key="option.id"
                                    class="pd-row-line"
                                >{{ option.shortName }} <span class="pd-row-muted">{{ option.eta }}</span> · {{ formatPrice(option.fee) }}</span>
                                <span class="pd-row-sub">Charged once per order from this seller</span>
                            </dd>
                        </div>
                        <div class="pd-row">
                            <dt>
                                <svg class="pd-row-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5" /><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11" /></svg>
                                Returns
                            </dt>
                            <dd>
                                <span class="pd-row-line">Request a return or refund from Orders once your order is delivered</span>
                                <span class="pd-row-sub">One open request per item</span>
                            </dd>
                        </div>
                        <div
                            v-if="livePaymentMethods.length"
                            class="pd-row"
                        >
                            <dt>
                                <svg class="pd-row-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 7V5.5A1.5 1.5 0 0 0 17.5 4h-12A1.5 1.5 0 0 0 4 5.5v13A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V16" /><path d="M14 10h7v6h-7a3 3 0 0 1 0-6Z" /><circle cx="15.5" cy="13" r=".6" fill="currentColor" /></svg>
                                Payment
                            </dt>
                            <dd>
                                <span class="pd-pay-methods">
                                    <span
                                        v-for="method in livePaymentMethods"
                                        :key="method.id"
                                        class="pd-pay-method"
                                    >
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.5" y="6" width="19" height="12" rx="1.5" /><circle cx="12" cy="12" r="2.5" /><path d="M6 9.5v5M18 9.5v5" /></svg>
                                        {{ method.name }}
                                    </span>
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <div class="pd-rows pd-rows-buy">
                        <!-- Variant selection: one choice per real variant -->
                        <div
                            v-if="hasVariants && variantChoices.length > 0"
                            ref="optionsBlock"
                            class="pd-row pd-options"
                            :class="{ 'is-missing': showMissing && !selectedVariant }"
                        >
                            <p class="pd-row-label">
                                {{ attributeNames || 'Option' }}
                            </p>
                            <div class="pd-row-value">
                                <VariantPicker
                                    ref="variantPicker"
                                    v-model="selectedVariantId"
                                    :choices="variantChoices"
                                    :attribute-names="attributeNames"
                                    :invalid="showMissing && !selectedVariant"
                                    :described-by="showMissing && !selectedVariant ? 'pd-missing-variant' : undefined"
                                />

                                <p
                                    v-if="showMissing && !selectedVariant"
                                    id="pd-missing-variant"
                                    class="pd-option-error"
                                    role="alert"
                                >
                                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16.5v.01" /></svg>
                                    Choose an option to continue
                                </p>

                                <p
                                    v-if="allOptionsSelected"
                                    class="pd-summary-line"
                                    aria-live="polite"
                                >
                                    <span class="pd-summary-label">Selected</span>
                                    {{ selectionSummary }}
                                    <button
                                        v-if="variantChoices.length > 1"
                                        type="button"
                                        class="link-btn pd-clear"
                                        @click="clearSelection"
                                    >
                                        Clear
                                    </button>
                                </p>
                            </div>
                        </div>

                        <!-- Quantity + availability -->
                        <div class="pd-row pd-qty-row">
                            <p class="pd-row-label">Quantity</p>
                            <div class="pd-row-value pd-qty-line">
                                <div
                                    class="pd-qty"
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
                                        class="pd-qty-value"
                                        aria-live="polite"
                                    >
                                        <span class="sr-only">Quantity </span>{{ quantity }}
                                    </span>
                                    <button
                                        type="button"
                                        aria-label="Increase quantity"
                                        :disabled="!canIncreaseQuantity"
                                        @click="increaseQuantity"
                                    >
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                                    </button>
                                </div>

                                <div
                                    class="pd-availability"
                                    aria-live="polite"
                                >
                                    <p
                                        v-if="purchaseStatus && purchaseStatus.label"
                                        class="pd-stock"
                                        :class="`is-${purchaseStatus.tone}`"
                                    >
                                        <svg
                                            v-if="purchaseStatus.tone === 'in'"
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
                                            v-else-if="purchaseStatus.tone === 'low' || purchaseStatus.tone === 'out'"
                                            viewBox="0 0 24 24"
                                            width="16"
                                            height="16"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2"
                                            stroke-linecap="round"
                                            aria-hidden="true"
                                        ><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16.5v.01" /></svg>
                                        {{ purchaseStatus.label }}
                                    </p>
                                    <p
                                        v-if="availableLabel"
                                        class="pd-available"
                                    >
                                        {{ availableLabel }}
                                    </p>
                                    <p
                                        v-if="displaySku"
                                        class="pd-sku"
                                    >
                                        SKU {{ displaySku }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Actions: Add to cart (outlined) + Buy now (filled) -->
                    <div class="pd-actions">
                        <div
                            ref="buyRow"
                            class="pd-buyrow"
                        >
                            <button
                                type="button"
                                class="pd-btn pd-btn-secondary"
                                :class="{ 'is-added': justAdded }"
                                :disabled="purchaseDisabled || isAdding"
                                :aria-describedby="purchaseBlockedReason ? 'pd-purchase-note' : undefined"
                                @click="handleAddToCart"
                            >
                                <svg
                                    v-if="justAdded"
                                    viewBox="0 0 24 24"
                                    width="18"
                                    height="18"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2.4"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    aria-hidden="true"
                                ><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                                <svg
                                    v-else
                                    viewBox="0 0 24 24"
                                    width="18"
                                    height="18"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    aria-hidden="true"
                                ><path d="M3 4h2l2.4 11.2a1.5 1.5 0 0 0 1.5 1.2h8.4a1.5 1.5 0 0 0 1.5-1.1L21 8H6.2" /><circle cx="9.5" cy="20" r="1.2" /><circle cx="17" cy="20" r="1.2" /></svg>
                                {{ justAdded ? 'Added to cart' : 'Add to cart' }}
                            </button>

                            <button
                                type="button"
                                class="pd-btn pd-btn-primary"
                                :disabled="purchaseDisabled"
                                :aria-describedby="purchaseBlockedReason ? 'pd-purchase-note' : undefined"
                                @click="handleBuyNow"
                            >
                                Buy now
                            </button>
                        </div>

                        <p
                            v-if="purchaseBlockedReason && !purchaseStatus.guidance"
                            id="pd-purchase-note"
                            class="pd-purchase-note"
                            role="status"
                        >
                            {{ purchaseBlockedReason }}
                            <button
                                v-if="purchaseStatus.offerSimilar"
                                type="button"
                                class="link-btn"
                                @click="scrollToRelated"
                            >
                                See similar products
                            </button>
                        </p>
                    </div>
                </div>
            </section>

            <!-- ======================================================= -->
            <!-- SHOP OVERVIEW: identity + activity, reviews, more items -->
            <!-- ======================================================= -->

            <section
                class="pd-shop"
                aria-labelledby="pd-shop-name"
            >
                <!-- Identity + activity -->
                <div class="pd-shop-head">
                    <StoreLogo
                        size="lg"
                        :name="shopName"
                        :src="shop?.logo || ''"
                        :category="shop?.category || product.seller_line_of_business || ''"
                    />

                    <div class="pd-shop-id">
                        <p class="pd-shop-eyebrow">Sold by</p>
                        <h2
                            id="pd-shop-name"
                            class="pd-shop-name"
                        >
                            <button
                                v-if="shopId"
                                type="button"
                                class="pd-shop-name-link"
                                @click="openShop()"
                            >
                                {{ shopName }}
                            </button>
                            <template v-else>{{ shopName }}</template>
                            <span
                                v-if="shop?.isVerified"
                                class="pd-shop-verified"
                            >
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                                Verified
                            </span>
                        </h2>

                        <p class="pd-shop-line">
                            <span v-if="shop?.category || product.seller_line_of_business">{{ shop?.category || product.seller_line_of_business }}</span>
                            <span
                                v-if="shop?.location"
                                class="pd-shop-loc"
                            >
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 5-8 12-8 12s-8-7-8-12a8 8 0 0 1 16 0Z" /><circle cx="12" cy="10" r="3" /></svg>
                                {{ shop.location }}
                            </span>
                            <span v-if="shopJoined">On BuyTheWay since {{ shopJoined }}</span>
                        </p>

                        <ul
                            v-if="shop"
                            class="pd-shop-stats"
                            aria-label="Shop activity"
                        >
                            <li class="pd-shop-stat">
                                <template v-if="shopHasRating">
                                    <svg class="pd-shop-star" viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M12 2.8l2.8 5.9 6.4.8-4.7 4.4 1.2 6.4L12 17.2l-5.7 3.1 1.2-6.4-4.7-4.4 6.4-.8z" /></svg>
                                    <strong>{{ shop.rating.toFixed(1) }}</strong>
                                    <span class="sr-only">out of 5,</span>
                                    <span>{{ compactCount(shop.reviewCount) }} product {{ shop.reviewCount === 1 ? 'review' : 'reviews' }}</span>
                                </template>
                                <span v-else>No product reviews yet</span>
                            </li>
                            <li
                                v-for="stat in shopStats"
                                :key="stat.key"
                                class="pd-shop-stat"
                            >
                                <strong>{{ stat.value }}</strong> {{ stat.label }}
                            </li>
                            <li
                                v-if="followerLabel"
                                class="pd-shop-stat"
                            >
                                {{ followerLabel }}
                            </li>
                        </ul>
                        <p
                            v-else-if="shopLoading"
                            class="pd-shop-stats is-loading"
                            aria-hidden="true"
                        >
                            <span class="skeleton is-line"></span>
                        </p>
                    </div>

                    <div
                        v-if="shopId"
                        class="pd-shop-actions"
                    >
                        <button
                            v-if="buyerProfile"
                            type="button"
                            class="pd-btn pd-shop-btn"
                            :class="isFollowing ? 'pd-btn-secondary is-following' : 'pd-btn-primary'"
                            :aria-pressed="isFollowing"
                            :aria-busy="followBusy"
                            :disabled="followBusy || !followReady"
                            @click="toggleFollow"
                        >
                            <svg v-if="isFollowing" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                            <svg v-else viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                            <span v-if="followBusy">{{ isFollowing ? 'Unfollowing…' : 'Following…' }}</span>
                            <span v-else>{{ isFollowing ? 'Following' : 'Follow shop' }}</span>
                        </button>
                        <a
                            v-else
                            href="/login"
                            class="pd-btn pd-btn-primary pd-shop-btn"
                        >
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                            Sign in to follow
                        </a>
                        <div class="pd-contact-row">
                            <button
                                type="button"
                                class="pd-btn pd-btn-ghost pd-shop-btn"
                                aria-haspopup="dialog"
                                :disabled="blockedShop"
                                @click="messageShop"
                            >
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.9A8 8 0 1 1 21 12Z" /></svg>
                                {{ blockedShop ? 'Shop blocked' : 'Message seller' }}
                            </button>
                        </div>
                        <button
                            type="button"
                            class="pd-btn pd-btn-ghost pd-shop-btn"
                            @click="openShop()"
                        >
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9.5 4.6 4h14.8L21 9.5" /><path d="M4 9.5V20h16V9.5" /><path d="M3 9.5a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0" /><path d="M10 20v-5h4v5" /></svg>
                            Visit shop
                        </button>
                    </div>
                </div>

                <!-- Reviews from this shop (other products) -->
                <div
                    v-if="shopId"
                    class="pd-shop-block"
                >
                    <div class="pd-shop-block-head">
                        <h3 class="pd-shop-h3">
                            Reviews from this shop
                            <span
                                v-if="shopReviewTotal"
                                class="pd-shop-count"
                            >({{ shopReviewTotal.toLocaleString('en-PH') }})</span>
                        </h3>
                        <button
                            v-if="shopReviewTotal"
                            type="button"
                            class="pd-shop-link"
                            @click="openShop('reviews')"
                        >
                            View all
                        </button>
                    </div>

                    <ul
                        v-if="shopReviews.length"
                        class="pd-shop-reviews"
                    >
                        <li
                            v-for="review in shopReviews"
                            :key="review.id"
                            class="pd-shop-review"
                        >
                            <StarRating
                                :rating="review.rating"
                                :size="15"
                            />
                            <p
                                v-if="review.comment"
                                class="pd-shop-review-text"
                            >
                                {{ review.comment }}
                            </p>
                            <p
                                v-else
                                class="pd-shop-review-text is-empty"
                            >
                                Rated {{ review.rating }} out of 5, no written review.
                            </p>

                            <ul
                                v-if="review.images && review.images.length"
                                class="pd-shop-review-photos"
                                aria-label="Review photos"
                            >
                                <li
                                    v-for="(src, index) in review.images.slice(0, 3)"
                                    :key="src"
                                >
                                    <img
                                        :src="src"
                                        :alt="`Photo ${index + 1} from ${review.author}`"
                                        width="48"
                                        height="48"
                                        loading="lazy"
                                    >
                                </li>
                            </ul>

                            <div class="pd-shop-review-foot">
                                <p class="pd-shop-review-by">
                                    <strong>{{ review.author }}</strong>
                                    <span
                                        class="pd-meta-sep"
                                        aria-hidden="true"
                                    ></span>
                                    <time :datetime="review.createdAt">{{ shopReviewDate(review.createdAt) }}</time>
                                </p>
                                <p
                                    v-if="review.product"
                                    class="pd-shop-review-item"
                                >
                                    Purchased:
                                    <button
                                        type="button"
                                        class="pd-shop-review-product"
                                        :disabled="openingReviewProduct === review.product.id"
                                        @click="openReviewProduct(review.product)"
                                    >
                                        {{ review.product.name }}
                                    </button>
                                    <span
                                        v-if="review.variant"
                                        class="pd-shop-review-variant"
                                    > · {{ review.variant }}</span>
                                </p>
                            </div>
                        </li>
                    </ul>

                    <p
                        v-else-if="shopReviewsLoaded"
                        class="pd-shop-empty"
                    >
                        {{ shopReviewTotal ? `No other reviews yet. The ones for this product are below.` : `${shopName} has no product reviews yet.` }}
                    </p>

                    <ul
                        v-else
                        class="pd-shop-reviews"
                        aria-hidden="true"
                    >
                        <li
                            v-for="n in 3"
                            :key="n"
                            class="pd-shop-review is-skeleton"
                        >
                            <span class="skeleton is-line"></span>
                            <span class="skeleton is-line"></span>
                            <span class="skeleton is-line pd-sk-short"></span>
                        </li>
                    </ul>
                </div>

                <!-- More from this shop -->
                <div
                    v-if="shopProducts.length"
                    class="pd-shop-block"
                >
                    <div class="pd-shop-block-head">
                        <h3 class="pd-shop-h3">More from this shop</h3>
                        <button
                            type="button"
                            class="pd-shop-link"
                            @click="openShop()"
                        >
                            Visit shop
                        </button>
                    </div>

                    <ul class="product-grid pd-shop-products">
                        <li
                            v-for="item in shopProducts"
                            :key="item.id"
                        >
                            <ProductCard
                                :product="item"
                                hide-seller
                                @view="emit('select-product', $event)"
                            />
                        </li>
                    </ul>
                </div>
            </section>

            <!-- ======================================================= -->
            <!-- ABOUT + SPECIFICATIONS -->
            <!-- ======================================================= -->

            <section
                v-if="hasAbout"
                class="pd-about"
                :class="{ 'has-specs': specEntries.length > 0 && descriptionParagraphs.length > 0 }"
                aria-label="Product information"
            >
                <div
                    v-if="descriptionParagraphs.length"
                    class="pd-desc"
                >
                    <h2 class="pd-h2">About this product</h2>
                    <div
                        id="pd-desc-body"
                        class="pd-desc-body"
                        :class="{ 'is-clamped': isLongDescription && !descriptionExpanded }"
                    >
                        <p
                            v-for="(paragraph, index) in descriptionParagraphs"
                            :key="index"
                        >
                            {{ paragraph }}
                        </p>
                    </div>
                    <button
                        v-if="isLongDescription"
                        type="button"
                        class="link-btn pd-desc-toggle"
                        aria-controls="pd-desc-body"
                        :aria-expanded="descriptionExpanded"
                        @click="descriptionExpanded = !descriptionExpanded"
                    >
                        {{ descriptionExpanded ? 'Show less' : 'Read the full description' }}
                    </button>
                </div>

                <div
                    v-if="specEntries.length"
                    class="pd-specs-wrap"
                >
                    <h2 class="pd-h2">Specifications</h2>
                    <dl class="pd-specs">
                        <div
                            v-for="[label, value] in specEntries"
                            :key="label"
                            class="pd-spec"
                        >
                            <dt>{{ label }}</dt>
                            <dd>{{ value }}</dd>
                        </div>
                    </dl>
                </div>
            </section>

            <!-- ======================================================= -->
            <!-- REVIEWS -->
            <!-- ======================================================= -->

            <section
                ref="reviewsSection"
                class="pd-reviews"
                aria-labelledby="pd-reviews-title"
            >
                <header class="pd-reviews-head">
                    <h2
                        id="pd-reviews-title"
                        class="pd-reviews-title"
                    >
                        Customer Reviews
                        <span
                            v-if="hasReviews"
                            class="pd-reviews-total"
                        >({{ reviewTotal.toLocaleString('en-PH') }})</span>
                    </h2>
                    <p
                        v-if="hasReviews"
                        class="pd-reviews-sub"
                    >
                        From buyers who received this product.
                    </p>
                </header>

                <div
                    v-if="!hasReviews"
                    class="pd-reviews-empty"
                >
                    <p class="pd-reviews-empty-title">No reviews yet</p>
                    <p>Buyers can review this product once their order is delivered.</p>
                </div>

                <div
                    v-else
                    class="pd-reviews-layout"
                >
                    <!-- Summary column -->
                    <div class="pd-rsum">
                        <div class="pd-rsum-top">
                            <p class="pd-rsum-score">
                                <span class="pd-rsum-number">{{ product.rating.toFixed(1) }}</span>
                                <span class="pd-rsum-out">out of 5</span>
                            </p>
                            <StarRating
                                :rating="product.rating"
                                :size="18"
                            />
                            <p class="pd-rsum-count">
                                Based on {{ reviewTotal.toLocaleString('en-PH') }} {{ reviewTotal === 1 ? 'review' : 'reviews' }}
                            </p>
                        </div>

                        <ul
                            class="pd-bars"
                            aria-label="Filter by rating"
                        >
                            <li
                                v-for="row in ratingBreakdown"
                                :key="row.star"
                            >
                                <button
                                    type="button"
                                    class="pd-bar"
                                    :class="{ 'is-active': reviewFilter === row.star }"
                                    :disabled="!reviewSummary || row.count === 0"
                                    :aria-pressed="reviewFilter === row.star"
                                    :aria-label="`${row.star} ${row.star === 1 ? 'star' : 'stars'}: ${row.count} ${row.count === 1 ? 'review' : 'reviews'}. Show only these`"
                                    @click="setReviewFilter(row.star)"
                                >
                                    <span class="pd-bar-label">{{ row.star }} star</span>
                                    <span
                                        class="pd-bar-track"
                                        :class="{ 'is-loading': !reviewSummary }"
                                        aria-hidden="true"
                                    >
                                        <span
                                            class="pd-bar-fill"
                                            :style="{ transform: `scaleX(${row.percent / 100})` }"
                                        ></span>
                                    </span>
                                    <span class="pd-bar-count">{{ reviewSummary ? row.count : '' }}</span>
                                </button>
                            </li>
                        </ul>
                    </div>

                    <!-- List -->
                    <div class="pd-rlist">
                        <div class="pd-rtools">
                            <div
                                class="pd-rfilters"
                                role="group"
                                aria-label="Show reviews"
                            >
                                <button
                                    type="button"
                                    class="pd-rfilter"
                                    :class="{ 'is-active': reviewFilter === 'all' }"
                                    :aria-pressed="reviewFilter === 'all'"
                                    @click="reviewFilter !== 'all' && setReviewFilter('all')"
                                >
                                    All reviews
                                </button>
                                <button
                                    v-if="photoReviewCount > 0"
                                    type="button"
                                    class="pd-rfilter"
                                    :class="{ 'is-active': reviewFilter === 'photos' }"
                                    :aria-pressed="reviewFilter === 'photos'"
                                    @click="setReviewFilter('photos')"
                                >
                                    With photos ({{ photoReviewCount }})
                                </button>
                                <button
                                    v-if="typeof reviewFilter === 'number'"
                                    type="button"
                                    class="pd-rfilter is-active"
                                    :aria-label="`Remove filter: ${reviewFilter} stars`"
                                    @click="setReviewFilter('all')"
                                >
                                    {{ reviewFilter }} {{ reviewFilter === 1 ? 'star' : 'stars' }}
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                                </button>
                            </div>
                            <p
                                class="pd-rcount"
                                role="status"
                                aria-live="polite"
                            >
                                <template v-if="reviewMeta && !reviewsLoading">
                                    Showing {{ reviewItems.length }} of {{ reviewMeta.total }}
                                    {{ reviewFilterLabel ? `reviews ${reviewFilterLabel}` : (reviewMeta.total === 1 ? 'review' : 'reviews') }}, newest first
                                </template>
                            </p>
                        </div>

                        <!-- A filter change cross-fades the list instead of
                             swapping it abruptly. -->
                        <Transition
                            name="pd-rswap"
                            mode="out-in"
                        >
                            <ul
                                v-if="reviewsLoading"
                                key="loading"
                                class="pd-rentries"
                                aria-hidden="true"
                            >
                                <li
                                    v-for="n in 3"
                                    :key="n"
                                    class="pd-rentry"
                                >
                                    <span class="skeleton is-line pd-sk-short"></span>
                                    <span class="skeleton is-line"></span>
                                    <span class="skeleton is-line"></span>
                                </li>
                            </ul>

                            <div
                                v-else-if="reviewsError"
                                key="error"
                                class="pd-rstate"
                                role="alert"
                            >
                                <p>{{ reviewsError }}</p>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    @click="loadReviews"
                                >
                                    Try again
                                </button>
                            </div>

                            <div
                                v-else-if="reviewItems.length === 0"
                                key="empty"
                                class="pd-rstate"
                            >
                                <p>No reviews {{ reviewFilterLabel }} yet.</p>
                                <button
                                    type="button"
                                    class="link-btn"
                                    @click="setReviewFilter('all')"
                                >
                                    Show all reviews
                                </button>
                            </div>

                            <div
                                v-else
                                :key="`list-${reviewFilter}`"
                            >
                                <ul class="pd-rentries">
                                    <li
                                        v-for="review in reviewItems"
                                        :key="review.id"
                                        class="pd-rentry"
                                    >
                                        <div class="pd-rentry-head">
                                            <p class="pd-rentry-by">
                                                <strong>{{ review.author }}</strong>
                                                <span
                                                    v-if="review.verifiedPurchase"
                                                    class="pd-verified"
                                                >
                                                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                                                    Verified purchase
                                                </span>
                                            </p>
                                            <p
                                                v-if="formatReviewDate(review.createdAt)"
                                                class="pd-rentry-date"
                                            >
                                                <time :datetime="review.createdAt">{{ formatReviewDate(review.createdAt) }}</time>
                                                <span v-if="review.isEdited"> &middot; Edited</span>
                                            </p>
                                        </div>

                                        <div class="pd-rentry-meta">
                                            <StarRating
                                                :rating="review.rating"
                                                :size="14"
                                            />
                                            <span
                                                v-if="variantLabel(review.variant)"
                                                class="pd-rentry-variant"
                                            >
                                                Bought: {{ variantLabel(review.variant) }}
                                            </span>
                                        </div>

                                        <template v-if="review.comment">
                                            <p
                                                :id="`pd-rtext-${review.id}`"
                                                class="pd-rentry-text"
                                                :class="{ 'is-clamped': isLongReview(review) && !expandedReviews.has(review.id) }"
                                            >
                                                {{ review.comment }}
                                            </p>
                                            <button
                                                v-if="isLongReview(review)"
                                                type="button"
                                                class="link-btn pd-rentry-more"
                                                :aria-expanded="expandedReviews.has(review.id)"
                                                :aria-controls="`pd-rtext-${review.id}`"
                                                @click="toggleReview(review, $event)"
                                            >
                                                {{ expandedReviews.has(review.id) ? 'Show less' : 'Read more' }}
                                            </button>
                                        </template>
                                        <p
                                            v-else
                                            class="pd-rentry-text is-empty"
                                        >
                                            Rated without a written review.
                                        </p>

                                        <div
                                            v-if="review.images && review.images.length"
                                            class="pd-rentry-photos"
                                        >
                                            <button
                                                v-for="(src, index) in review.images"
                                                :key="src"
                                                type="button"
                                                class="pd-rentry-photo"
                                                :aria-label="`Open photo ${index + 1} of ${review.images.length} from ${review.author}'s review`"
                                                @click="openViewer(review.images, index, `Photos from ${review.author}'s review`, $event.currentTarget)"
                                            >
                                                <img
                                                    :src="src"
                                                    alt=""
                                                    width="80"
                                                    height="80"
                                                    loading="lazy"
                                                >
                                            </button>
                                        </div>

                                        <div
                                            v-if="review.sellerResponse"
                                            class="pd-reply"
                                        >
                                            <p class="pd-reply-by">
                                                Reply from {{ sellerName }}
                                                <span
                                                    v-if="formatReviewDate(review.respondedAt)"
                                                    class="pd-reply-date"
                                                > &middot; {{ formatReviewDate(review.respondedAt) }}</span>
                                            </p>
                                            <p class="pd-reply-text">{{ review.sellerResponse }}</p>
                                        </div>
                                    </li>
                                </ul>

                                <button
                                    v-if="hasMoreReviews"
                                    type="button"
                                    class="btn btn-secondary pd-rmore"
                                    :disabled="reviewsLoadingMore"
                                    @click="loadMoreReviews"
                                >
                                    {{ reviewsLoadingMore ? 'Loading…' : 'Show more reviews' }}
                                </button>
                            </div>
                        </Transition>
                    </div>
                </div>
            </section>

            <!-- ======================================================= -->
            <!-- RELATED -->
            <!-- ======================================================= -->

            <section
                ref="relatedSection"
                class="pd-related"
                aria-labelledby="pd-related-title"
            >
                <h2
                    id="pd-related-title"
                    class="pd-h2"
                >
                    More in {{ product.category }}
                </h2>

                <ul
                    v-if="relatedToShow.length > 0"
                    class="product-grid pd-related-grid"
                >
                    <li
                        v-for="item in relatedToShow"
                        :key="item.id"
                    >
                        <ProductCard
                            :product="item"
                            @view="emit('select-product', $event)"
                        />
                    </li>
                </ul>

                <div
                    v-else
                    class="pd-reviews-empty"
                >
                    <p class="pd-reviews-empty-title">Nothing else in {{ product.category }} yet</p>
                    <p>New listings from sellers show up here as they arrive.</p>
                    <button
                        type="button"
                        class="btn btn-secondary"
                        @click="emit('browse-all')"
                    >
                        Browse all products
                    </button>
                </div>
            </section>

        </main>

        <main
            v-else
            id="main-content"
            class="pd-not-found"
            tabindex="-1"
        >
            <h1>Product not found</h1>
            <p>It may have been removed or is no longer available.</p>
            <button
                type="button"
                class="btn btn-primary"
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

        <!-- Mobile buy bar -->
        <Transition name="pd-bar">
            <div
                v-if="showBuyBar"
                class="pd-buybar"
            >
                <div class="pd-buybar-info">
                    <span
                        :key="formattedPrice"
                        class="pd-buybar-price"
                        :class="{ 'nx-value-in': priceChanged }"
                    >{{ formattedPrice }}</span>
                    <span
                        v-if="selectionSummary"
                        class="pd-buybar-choice"
                    >{{ selectionSummary }}</span>
                </div>
                <button
                    type="button"
                    class="pd-btn pd-btn-primary pd-buybar-btn"
                    :disabled="buyBarAction === 'soldout' || isAdding"
                    @click="handleBuyBarAction"
                >
                    <template v-if="buyBarAction === 'soldout'">Sold out</template>
                    <template v-else-if="buyBarAction === 'choose'">Choose options</template>
                    <template v-else-if="buyBarAction === 'change'">Change option</template>
                    <template v-else>{{ justAdded ? 'Added' : 'Add to cart' }}</template>
                </button>
            </div>
        </Transition>

        <!-- Image viewer -->
        <Teleport to="body">
            <Transition name="pd-fade">
                <div
                    v-if="viewer"
                    ref="viewerDialog"
                    class="pd-viewer"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="viewer.label"
                    @keydown="handleViewerKeydown"
                    @click.self="closeViewer"
                >
                    <button
                        ref="viewerCloseButton"
                        type="button"
                        class="pd-viewer-close"
                        aria-label="Close photo viewer"
                        @click="closeViewer"
                    >
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                    </button>

                    <img
                        :key="viewer.images[viewer.index]"
                        class="pd-viewer-img"
                        :src="viewer.images[viewer.index]"
                        :alt="`${viewer.label}, ${viewer.index + 1} of ${viewer.images.length}`"
                    >

                    <template v-if="viewer.images.length > 1">
                        <button
                            type="button"
                            class="pd-viewer-nav is-prev"
                            aria-label="Previous photo"
                            @click="moveViewer(-1)"
                        >
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
                        </button>
                        <button
                            type="button"
                            class="pd-viewer-nav is-next"
                            aria-label="Next photo"
                            @click="moveViewer(1)"
                        >
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
                        </button>
                        <p
                            class="pd-viewer-count"
                            aria-live="polite"
                        >
                            {{ viewer.index + 1 }} / {{ viewer.images.length }}
                        </p>
                    </template>
                </div>
            </Transition>
        </Teleport>

        <Teleport to="body">
            <div v-if="reportDialogOpen" class="pd-report-backdrop" @click.self="!reportBusy && (reportDialogOpen = false)">
                <form class="pd-report-dialog" role="dialog" aria-modal="true" aria-labelledby="pd-report-title" @submit.prevent="submitReport">
                    <header>
                        <h2 id="pd-report-title">Report {{ reportTarget === 'product' ? 'product' : 'shop' }}</h2>
                        <button type="button" aria-label="Close report form" :disabled="reportBusy" @click="reportDialogOpen = false">×</button>
                    </header>
                    <label class="pd-report-field">
                        <span>Reason</span>
                        <select v-model="reportReason" required>
                            <option value="" disabled>Select a reason</option>
                            <option v-for="reason in reportReasons[reportTarget]" :key="reason.value" :value="reason.value">{{ reason.label }}</option>
                        </select>
                    </label>
                    <label class="pd-report-field">
                        <span>Details</span>
                        <textarea v-model="reportDetails" required maxlength="2000" rows="4" placeholder="Explain what happened and why this should be reviewed."></textarea>
                    </label>
                    <label class="pd-report-anonymous"><input v-model="reportAnonymous" type="checkbox"><span>Submit anonymously</span></label>
                    <div class="pd-report-field">
                        <span>Evidence <small>(optional, up to 8 images or videos)</small></span>
                        <input ref="reportEvidenceInput" class="pd-report-file-input" type="file" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime" multiple @change="selectReportFiles">
                        <button type="button" class="pd-report-dropzone" :class="{ 'is-active': reportDropActive }" @click="reportEvidenceInput?.click()" @dragover.prevent="reportDropActive = true" @dragleave.prevent="reportDropActive = false" @drop.prevent="handleReportDrop">
                            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4m0 0L7 9m5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>
                            <strong>Drop files here or <span>browse</span></strong>
                            <small>Images and videos, up to 50 MB each</small>
                        </button>
                        <ul v-if="reportFiles.length" class="pd-report-attachments">
                            <li v-for="(file, index) in reportFiles" :key="`${file.name}-${file.size}-${file.lastModified}`">
                                <img v-if="file.type.startsWith('image/')" :src="reportPreviewUrl(file)" alt="">
                                <span v-else class="pd-report-video-thumb"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg></span>
                                <span class="pd-report-file-name">{{ file.name }}</span>
                                <button type="button" :aria-label="`Remove ${file.name}`" @click="removeReportFile(index)">×</button>
                            </li>
                        </ul>
                    </div>
                    <p class="pd-report-note">Urgent reports are prioritized and may temporarily hide listings from discovery while Platform Admin reviews them.</p>
                    <p v-if="reportSubmitError" class="pd-report-error" role="alert">{{ reportSubmitError }}</p>
                    <footer>
                        <button type="button" class="pd-report-cancel" :disabled="reportBusy" @click="reportDialogOpen = false">Cancel</button>
                        <button type="submit" class="pd-report-submit" :disabled="reportBusy || !reportReason || !reportDetails.trim()">{{ reportBusy ? 'Submitting…' : 'Submit report' }}</button>
                    </footer>
                </form>
            </div>
        </Teleport>

    </div>

</template>

<style scoped>
/*
| Product page. One rounded element per region (gallery stage, review
| photos); structure otherwise comes from type, spacing and hairlines.
*/

.pd-page.has-buy-bar {
    padding-bottom: calc(76px + env(safe-area-inset-bottom, 0px));
}

.pd {
    width: 100%;
    max-width: 1400px;

    margin: 0 auto;
    padding: 24px var(--nx-gutter) 96px;

    box-sizing: border-box;
}

.pd:focus {
    outline: none;
}

.pd-crumbs :deep(ol) {
    margin-bottom: 20px;
}

.pd-crumb-current {
    max-width: 40ch;

    overflow: hidden;

    text-overflow: ellipsis;
    white-space: nowrap;
}

/* ---------------- Main: gallery + panel ---------------- */

/* One surface for gallery + purchase: white on the warm page, a hairline
   border and a soft, low shadow so it lifts without looking like a card
   stack. Nothing inside gets its own shadow. */
.pd-main {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(0, 3fr);
    align-items: start;
    gap: clamp(28px, 3.5vw, 56px);

    padding: clamp(18px, 2.4vw, 32px);

    border: 1px solid var(--nx-line);
    border-radius: var(--nx-radius);
    background: var(--nx-surface);
    box-shadow: 0 1px 2px rgba(43, 39, 34, 0.04), 0 12px 32px -20px rgba(43, 39, 34, 0.18);
}

.pd-gallery {
    position: sticky;
    top: calc(var(--nx-header-h) + 20px);

    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 14px;
}

.pd-gallery > .pd-stage,
.pd-gallery > .pd-swipe {
    order: 1;
}

/* Thumbnails run under the main image, scrolling sideways when long. */
.pd-thumbs {
    order: 2;

    display: flex;
    gap: 8px;

    padding: 2px;

    overflow-x: auto;
    overscroll-behavior-x: contain;
    scrollbar-width: none;
}

.pd-gallery-foot {
    order: 3;

    display: flex;
    align-items: center;
    justify-content: flex-end;

    padding-top: 2px;
}

.pd-thumbs::-webkit-scrollbar {
    display: none;
}

.pd-thumb {
    flex-shrink: 0;

    width: 72px;
    height: 72px;
    padding: 4px;

    overflow: hidden;
    border: 1px solid var(--nx-line);
    border-radius: var(--nx-radius-sm);
    background: var(--nx-sunken);

    opacity: 0.7;

    transition: opacity var(--nx-dur-fast) var(--nx-ease), border-color var(--nx-dur-fast) var(--nx-ease);
}

/* The whole photo, letterboxed, so a tall or wide shot is recognisable. */
.pd-thumb img {
    display: block;

    width: 100%;
    height: 100%;

    object-fit: contain;
}

.pd-thumb:hover {
    opacity: 1;
}

.pd-thumb.is-active {
    border-color: var(--nx-accent);
    box-shadow: inset 0 0 0 1px var(--nx-accent);

    opacity: 1;
}

.pd-stage,
.pd-swipe {
    position: relative;

    aspect-ratio: 1 / 1;

    overflow: hidden;
    border-radius: var(--nx-radius-lg);
    background: var(--nx-sunken);
}

/* A fixed box, independent of the photo: square, but never taller than the
   space under the header (so the whole photo shows without scrolling) or
   760px on very large screens. Switching photos or variants never changes
   its size. */
.pd-stage {
    max-height: min(760px, calc(100vh - var(--nx-header-h) - 56px));
}

/* Until the photo arrives, the box itself is the placeholder. */
.pd-stage.is-loading,
.pd-swipe-slide.is-loading {
    background: linear-gradient(90deg, var(--nx-sunken) 0%, var(--nx-line-soft) 50%, var(--nx-sunken) 100%);
    background-size: 200% 100%;

    animation: skeleton-shimmer 1.4s ease-in-out infinite;
}

.pd-stage-zoom {
    position: absolute;
    inset: 0;

    display: flex;
    align-items: center;
    justify-content: center;

    width: 100%;
    padding: 0;

    border: none;
    background: none;

    cursor: zoom-in;
}

/* Fit inside the box at no more than natural size: a tall photo is
   limited by the box height instead of growing it, a wide one by its
   width, and a small one isn't blown up blurry. Nothing is cropped; the
   viewer shows the original at full size. */
.pd-stage-img,
.pd-swipe-slide img {
    display: block;

    width: auto;
    height: auto;
    max-width: 100%;
    max-height: 100%;

    object-fit: contain;
}

.pd-stage-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;

    height: 100%;

    background: var(--accent-bg, var(--nx-line-soft));
    color: var(--nx-muted);
}

.pd-stage-empty p {
    margin: 0;
}

.pd-stage-nav {
    position: absolute;
    top: 50%;
    z-index: 1;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    width: 40px;
    height: 40px;

    border: 1px solid var(--nx-line);
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.92);
    color: var(--nx-ink);

    opacity: 0;
    transform: translateY(-50%);

    transition: opacity var(--nx-dur) var(--nx-ease);
}

.pd-stage-nav.is-prev { left: 14px; }
.pd-stage-nav.is-next { right: 14px; }

.pd-stage:hover .pd-stage-nav,
.pd-stage-nav:focus-visible {
    opacity: 1;
}

.pd-stage-hint {
    position: absolute;
    right: 14px;
    bottom: 14px;

    display: inline-flex;
    align-items: center;
    gap: 6px;

    padding: 5px 10px;

    border-radius: var(--nx-radius-sm);
    background: rgba(255, 255, 255, 0.9);
    color: var(--nx-text-2);

    font-size: 12px;
    font-weight: 600;

    opacity: 0;

    transition: opacity var(--nx-dur) var(--nx-ease);
    pointer-events: none;
}

.pd-stage:hover .pd-stage-hint {
    opacity: 1;
}

@media (hover: none) {
    .pd-stage-nav {
        opacity: 1;
    }
}

.pd-swipe-track {
    display: flex;

    height: 100%;

    overflow-x: auto;
    scroll-snap-type: x mandatory;
    scrollbar-width: none;
}

.pd-swipe-track::-webkit-scrollbar {
    display: none;
}

.pd-swipe-slide {
    display: flex;
    flex: 0 0 100%;
    align-items: center;
    justify-content: center;

    padding: 0;

    border: none;
    background: none;

    scroll-snap-align: center;
}

.pd-swipe-dots {
    position: absolute;
    left: 50%;
    bottom: 8px;

    display: flex;

    transform: translateX(-50%);
}

.pd-swipe-dots button {
    position: relative;

    width: 24px;
    height: 28px;
    padding: 0;

    border: none;
    background: none;
}

.pd-swipe-dots button::before {
    content: "";

    position: absolute;
    top: 50%;
    left: 50%;

    width: 7px;
    height: 7px;

    border-radius: 50%;
    background: rgba(28, 26, 23, 0.25);

    transform: translate(-50%, -50%);
    transition: background-color var(--nx-dur) var(--nx-ease), transform var(--nx-dur) var(--nx-ease);
}

.pd-swipe-dots button.is-active::before {
    background: var(--nx-ink);
    transform: translate(-50%, -50%) scale(1.25);
}

/* ---------------- Purchase panel ---------------- */

.pd-panel {
    min-width: 0;
}

.pd-title-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.pd-title-row .pd-title {
    flex: 1;
    min-width: 0;
}

.pd-product-report-button {
    display: inline-flex;
    flex: 0 0 42px;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    margin-top: -5px;
    border: 1px solid var(--nx-line);
    border-radius: 10px;
    background: var(--nx-surface);
    color: var(--nx-text-2);
    cursor: pointer;
    transition: background-color var(--nx-dur-fast) var(--nx-ease), color var(--nx-dur-fast) var(--nx-ease), border-color var(--nx-dur-fast) var(--nx-ease);
}

.pd-product-report-button:hover {
    border-color: var(--nx-accent);
    background: var(--nx-accent-soft);
    color: var(--nx-accent);
}

#buyer-app .pd-title {
    margin: 0;

    color: var(--nx-ink);

    font-size: clamp(22px, 1.9vw, 28px);
    font-weight: 700;
    letter-spacing: -0.015em;
    line-height: 1.2;
    overflow-wrap: anywhere;
}

.pd-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 12px;

    margin-top: 12px;

    color: var(--nx-text-2);

    font-size: 14px;
}

.pd-meta strong {
    color: var(--nx-ink);
    font-weight: 600;
}

.pd-meta-sep {
    width: 1px;
    height: 14px;

    background: var(--nx-line-strong);
}

.pd-meta-muted {
    color: var(--nx-text-2);
}

/* "1.2k reviews", "34 sold": the number carries the weight. */
.pd-meta-stat {
    display: inline-flex;
    align-items: center;
    gap: 4px;

    min-height: 32px;
    padding: 0;

    border: none;
    background: none;
    color: var(--nx-text-2);

    font: inherit;
}

.pd-meta-stat strong {
    color: var(--nx-ink);
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}

button.pd-meta-stat:hover strong {
    text-decoration: underline;
    text-underline-offset: 3px;
}

.pd-rating {
    display: inline-flex;
    align-items: center;
    gap: 6px;

    min-height: 32px;
    padding: 0;

    border: none;
    background: none;
    color: var(--nx-star);

    font: inherit;
}

.pd-rating-score {
    color: var(--nx-ink);
    font-weight: 700;
}

.pd-rating-score {
    font-size: 15px;
    text-decoration: underline;
    text-decoration-color: var(--nx-line-strong);
    text-underline-offset: 3px;
}

.pd-price-block {
    margin: 18px 0 0;
    padding: 16px 20px;

    border-radius: var(--nx-radius-sm);
    background: var(--nx-sunken);
}

.pd-price-row {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 4px 12px;

    margin: 0;
}

.pd-price-from {
    flex-basis: 100%;

    color: var(--nx-muted);

    font-size: 12.5px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.pd-price {
    color: var(--nx-accent);

    font-family: var(--nx-font-display);
    font-size: clamp(28px, 2.4vw, 34px);
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    letter-spacing: -0.02em;
    line-height: 1.1;
}

.pd-old-price {
    color: var(--nx-muted);

    font-size: 16px;
    font-variant-numeric: tabular-nums;
}

.pd-discount {
    align-self: center;

    padding: 2px 6px;

    border-radius: 4px;
    background: var(--nx-deal);
    color: #ffffff;

    font-size: 12px;
    font-weight: 700;
}

.pd-savings {
    margin: 6px 0 0;

    color: var(--nx-deal);

    font-size: 14px;
    font-weight: 600;
}

.pd-price-note {
    margin: 6px 0 0;

    color: var(--nx-muted);

    font-size: 13.5px;
}

/* ---------------- Label | value rows ---------------- */

.pd-rows {
    margin: 6px 0 0;
    padding: 0;
}

.pd-row {
    display: grid;
    grid-template-columns: 112px minmax(0, 1fr);
    align-items: start;
    gap: 6px 20px;

    padding: 13px 0;

    font-size: 14.5px;
}

.pd-row dt,
.pd-row-label {
    display: flex;
    align-items: flex-start;
    gap: 8px;

    margin: 0;

    color: var(--nx-muted);

    font-size: 14px;
    font-weight: 500;
    line-height: 1.5;
}

/* One icon style: 16px, 1.8 stroke, the label's own muted colour. */
.pd-row-icon {
    flex: none;

    margin-top: 2px;
}

.pd-pay-methods {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.pd-pay-method {
    display: inline-flex;
    align-items: center;
    gap: 8px;

    min-height: 30px;
    padding: 0 10px;

    border: 1px solid var(--nx-line);
    border-radius: var(--nx-radius-sm);
    color: var(--nx-ink);

    font-size: 13.5px;
    font-weight: 500;
}

.pd-pay-method svg {
    color: var(--nx-accent);
}

.pd-meta-seller {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.pd-meta-seller > svg {
    color: var(--nx-muted);
}

.pd-row dd,
.pd-row-value {
    display: flex;
    flex-direction: column;
    gap: 2px;

    min-width: 0;
    margin: 0;

    color: var(--nx-ink);
    line-height: 1.5;
}

.pd-row-line {
    color: var(--nx-ink);
}

.pd-row-muted {
    color: var(--nx-text-2);
}

.pd-row-sub {
    color: var(--nx-muted);

    font-size: 13px;
}

.pd-rows-buy {
    margin-top: 8px;
    padding-top: 8px;

    border-top: 1px solid var(--nx-line);
}

/* The option label sits level with the first row of buttons. */
.pd-options .pd-row-label {
    padding-top: 14px;
}

.pd-options .pd-row-value {
    gap: 8px;
}

/* ---------------- Variants ---------------- */





.pd-option-error {
    display: flex;
    align-items: center;
    gap: 6px;

    margin: 8px 0 0;

    color: #b42318;

    font-size: 13.5px;
    font-weight: 600;
}

.pd-clear {
    min-height: 0;
    margin-left: 8px;

    font-size: 13px;
}

/* ---------------- Summary + actions ---------------- */

.pd-summary-line {
    margin: 0;

    color: var(--nx-ink);

    font-size: 14px;
    font-weight: 500;
}

.pd-qty-row .pd-row-label {
    padding-top: 10px;
}

.pd-qty-line {
    flex-direction: row;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 18px;
}

.pd-availability {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 2px 14px;
}

.pd-availability p {
    margin: 0;
}

.pd-available {
    color: var(--nx-text-2);

    font-size: 14px;
    font-variant-numeric: tabular-nums;
}

.pd-summary-label {
    margin-right: 6px;

    color: var(--nx-muted);
    font-weight: 600;
}

.pd-stock {
    display: inline-flex;
    align-items: center;
    gap: 6px;

    font-size: 14px;
    font-weight: 600;
}

.pd-stock.is-in { color: var(--nx-accent-dark); }
.pd-stock.is-low { color: var(--nx-deal); }
.pd-stock.is-out { color: #b42318; }
.pd-stock.is-neutral { color: var(--nx-text-2); font-weight: 500; }

.pd-sku {
    color: var(--nx-muted);

    font-size: 12.5px;
    font-variant-numeric: tabular-nums;
}

.pd-actions {
    display: flex;
    flex-direction: column;
    gap: 10px;

    margin-top: 18px;
}

/* Add to cart (outlined) and Buy now (filled), side by side. */
.pd-buyrow {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;

    max-width: 560px;
}

.pd-qty {
    display: inline-flex;
    align-items: center;

    height: 44px;

    border: 1px solid var(--nx-line-strong);
    border-radius: var(--nx-radius-sm);
    background: var(--nx-surface);
}

.pd-qty button {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    width: 42px;
    height: 100%;

    border: none;
    background: none;
    color: var(--nx-ink);
}

.pd-qty button:disabled {
    color: var(--nx-muted-2);
}

.pd-qty-value {
    min-width: 40px;

    color: var(--nx-ink);

    font-size: 15px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    text-align: center;
}

.pd-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;

    min-height: 52px;
    padding: 0 20px;

    border: 1px solid transparent;
    border-radius: var(--nx-radius-sm);

    font: inherit;
    font-size: 15.5px;
    font-weight: 600;

    transition: background-color var(--nx-dur-fast) var(--nx-ease), border-color var(--nx-dur-fast) var(--nx-ease), color var(--nx-dur-fast) var(--nx-ease), transform var(--nx-dur-fast) var(--nx-ease);
}

.pd-btn:active:not(:disabled) {
    transform: scale(0.985);
}

.pd-btn:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}

.pd-btn-primary {
    background: var(--nx-accent);
    color: #ffffff;
}

.pd-btn-primary:hover:not(:disabled) {
    background: var(--nx-accent-dark);
}

.pd-btn-secondary.is-added svg {
    animation: pd-check 0.3s var(--nx-ease);
}

@keyframes pd-check {
    from { opacity: 0; transform: scale(0.5); }
}

.pd-btn-secondary {
    border-color: var(--nx-accent);
    background: var(--nx-surface);
    color: var(--nx-accent-dark);
}

.pd-btn-secondary:hover:not(:disabled),
.pd-btn-secondary.is-added {
    background: var(--nx-accent-soft);
}

.pd-fav {
    display: inline-flex;
    align-items: center;
    gap: 8px;

    min-height: 40px;
    padding: 0 6px;

    border: none;
    border-radius: var(--nx-radius-sm);
    background: none;
    color: var(--nx-text-2);

    font: inherit;
    font-size: 14px;
    font-weight: 500;

    transition: color var(--nx-dur-fast) var(--nx-ease);
}

.pd-fav:hover {
    color: var(--nx-ink);
}

.pd-fav.is-on {
    color: var(--nx-deal);
}

.pd-fav.is-on svg {
    animation: pd-check 0.3s var(--nx-ease);
}

.pd-purchase-note {
    margin: 2px 0 0;

    color: var(--nx-text-2);

    font-size: 13.5px;
}

.pd-purchase-note .link-btn {
    min-height: 0;
    margin-left: 4px;
}

/* ---------------- Shop overview ---------------- */

/* The same surface as the main container, a little quieter (no shadow),
   with hairlines between identity, reviews and products. */
.pd-shop {
    margin-top: 16px;
    padding: clamp(18px, 2.4vw, 32px);

    border: 1px solid var(--nx-line);
    border-radius: var(--nx-radius);
    background: var(--nx-surface);
}

.pd-shop-head {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) 210px;
    align-items: start;
    gap: 16px 24px;
}

.pd-shop-id {
    display: flex;
    flex-direction: column;
    gap: 6px;

    min-width: 0;
}

.pd-shop-eyebrow {
    margin: 0;

    color: var(--nx-muted);

    font-size: 12.5px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

#buyer-app .pd-shop-name {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 10px;

    margin: 0;

    color: var(--nx-ink);

    font-family: var(--nx-font-display);
    font-size: clamp(20px, 1.8vw, 24px);
    font-weight: 700;
    letter-spacing: -0.015em;
    line-height: 1.2;
}

.pd-shop-name-link {
    padding: 0;

    border: none;
    background: none;
    color: inherit;

    font: inherit;
    letter-spacing: inherit;
    text-align: left;
}

.pd-shop-name-link:hover {
    text-decoration: underline;
    text-decoration-thickness: 2px;
    text-underline-offset: 4px;
}

.pd-shop-verified {
    display: inline-flex;
    align-items: center;
    gap: 4px;

    min-height: 24px;
    padding: 0 8px;

    border-radius: 999px;
    background: var(--nx-accent-soft);
    color: var(--nx-accent);

    font-family: var(--nx-font-body, inherit);
    font-size: 12.5px;
    font-weight: 600;
    letter-spacing: 0;
}

.pd-shop-line,
.pd-shop-stats {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 0;

    margin: 0;
    padding: 0;

    color: var(--nx-text-2);

    font-size: 14px;
    list-style: none;
}

/* Dot separators between facts (not before the first). */
.pd-shop-line > * + *::before,
.pd-shop-stats > * + *::before {
    content: "";

    display: inline-block;

    width: 3px;
    height: 3px;
    margin: 0 10px;

    border-radius: 50%;
    background: var(--nx-line-strong);

    vertical-align: middle;
}

.pd-shop-loc {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.pd-shop-loc svg {
    color: var(--nx-muted);
}

.pd-shop-stats {
    margin-top: 2px;

    color: var(--nx-text-2);
}

.pd-shop-stat {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.pd-shop-stat strong {
    color: var(--nx-ink);

    font-weight: 600;
    font-variant-numeric: tabular-nums;
}

.pd-shop-star {
    color: var(--nx-star);
}

.pd-shop-stats.is-loading .skeleton {
    width: 260px;
    margin: 0;
}

.pd-shop-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.pd-contact-row {
    display: flex;
    align-items: center;
    gap: 8px;
}

.pd-contact-row .pd-shop-btn { flex: 1; width: auto; }

.pd-shop-btn {
    width: 100%;
    min-height: 44px;

    font-size: 14.5px;
    text-decoration: none;
}

.pd-btn-ghost {
    border-color: var(--nx-line-strong);
    background: var(--nx-surface);
    color: var(--nx-ink);
}

.pd-btn-ghost:hover:not(:disabled) {
    border-color: var(--nx-ink);
}

.pd-shop-btn.is-following {
    color: var(--nx-accent-dark);
}

.pd-shop-btn[disabled] {
    cursor: progress;
}

.pd-report-backdrop {
    position: fixed;
    z-index: 120;
    inset: 0;
    display: grid;
    place-items: center;
    padding: 16px;
    background: rgba(15, 23, 42, .48);
}
.pd-report-dialog {
    display: grid;
    width: min(100%, 520px);
    gap: 16px;
    padding: 20px;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 24px 64px rgba(15, 23, 42, .22);
}
.pd-report-dialog header,
.pd-report-dialog footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.pd-report-dialog header h2 { margin: 0; color: #0f172a; font-size: 20px; }
.pd-report-dialog header button { width: 40px; height: 40px; border: 0; border-radius: 10px; background: #f1f5f9; font-size: 24px; cursor: pointer; }
.pd-report-field { display: grid; gap: 6px; color: #334155; font-size: 14px; font-weight: 600; }
.pd-report-field small { color: #64748b; font-weight: 400; }
.pd-report-anonymous { display: flex; align-items: center; gap: 9px; color: #334155; font-size: 14px; }
.pd-report-field select,
.pd-report-field textarea { width: 100%; min-height: 44px; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff; color: #0f172a; font: inherit; }
.pd-report-field textarea { resize: vertical; }
.pd-report-file-input { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; clip-path: inset(50%); }
.pd-report-dropzone { display: grid; justify-items: center; gap: 8px; width: 100%; min-height: 142px; padding: 20px; border: 1.5px dashed #aab9b2; border-radius: 12px; background: #f8fbf9; color: #64748b; font: inherit; cursor: pointer; transition: border-color .16s ease, background-color .16s ease, color .16s ease; }
.pd-report-dropzone:hover,
.pd-report-dropzone.is-active { border-color: #0f766e; background: #eef8f4; color: #0f766e; }
.pd-report-dropzone strong { color: #0f172a; font-size: 14px; }
.pd-report-dropzone strong span { color: #0f766e; text-decoration: underline; }
.pd-report-dropzone small { color: #64748b; font-size: 12px; font-weight: 400; }
.pd-report-attachments { display: grid; gap: 8px; margin: 10px 0 0; padding: 0; list-style: none; }
.pd-report-attachments li { display: flex; align-items: center; gap: 10px; min-width: 0; padding: 8px; border: 1px solid #e2e8f0; border-radius: 9px; background: #fff; }
.pd-report-attachments li > img,
.pd-report-video-thumb { display: grid; flex: 0 0 42px; place-items: center; width: 42px; height: 42px; border-radius: 6px; background: #f1f5f9; object-fit: cover; color: #0f766e; }
.pd-report-file-name { flex: 1; overflow: hidden; color: #0f172a; font-size: 13px; font-weight: 500; text-overflow: ellipsis; white-space: nowrap; }
.pd-report-attachments li > button { flex: 0 0 34px; width: 34px; height: 34px; border: 0; border-radius: 7px; background: transparent; color: #64748b; font-size: 22px; cursor: pointer; }
.pd-report-attachments li > button:hover { background: #fff0f0; color: #c62828; }
.pd-report-note { margin: 0; color: #64748b; font-size: 12px; line-height: 1.5; }
.pd-report-error { margin: 0; padding: 10px 12px; border: 1px solid #fecaca; border-radius: 8px; background: #fef2f2; color: #b91c1c; font-size: 13px; }
.pd-report-cancel,
.pd-report-submit { min-height: 44px; padding: 0 16px; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff; font: inherit; font-weight: 700; cursor: pointer; }
.pd-report-submit { margin-left: auto; border-color: #0f766e; background: #0f766e; color: #fff; }
.pd-report-submit:disabled { opacity: .55; cursor: wait; }

.pd-shop-block {
    margin-top: 24px;
    padding-top: 24px;

    border-top: 1px solid var(--nx-line);
}

.pd-shop-block-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px 16px;

    margin-bottom: 16px;
}

#buyer-app .pd-shop-h3 {
    margin: 0;

    color: var(--nx-ink);

    font-family: var(--nx-font-display);
    font-size: 18px;
    font-weight: 700;
    letter-spacing: -0.01em;
}

.pd-shop-count {
    color: var(--nx-muted);

    font-weight: 500;
}

.pd-shop-link {
    display: inline-flex;
    align-items: center;

    min-height: 36px;
    padding: 0 14px;

    border: 1px solid var(--nx-line-strong);
    border-radius: var(--nx-radius-sm);
    background: var(--nx-surface);
    color: var(--nx-ink);

    font: inherit;
    font-size: 14px;
    font-weight: 600;

    transition: border-color var(--nx-dur-fast) var(--nx-ease), transform var(--nx-dur-fast) var(--nx-ease);
}

.pd-shop-link:hover {
    border-color: var(--nx-ink);
}

.pd-shop-link:active {
    transform: scale(0.98);
}

.pd-shop-reviews {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr));
    gap: 14px;

    margin: 0;
    padding: 0;

    list-style: none;
}

.pd-shop-review {
    display: flex;
    flex-direction: column;
    gap: 10px;

    min-width: 0;
    min-height: 196px;
    padding: 16px 18px;

    border: 1px solid var(--nx-line);
    border-radius: var(--nx-radius-sm);

    color: var(--nx-star);

    transition: border-color var(--nx-dur-fast) var(--nx-ease);
}

.pd-shop-review:hover {
    border-color: var(--nx-line-strong);
}

.pd-shop-review.is-skeleton .skeleton {
    margin: 0;
}

.pd-shop-review-text {
    display: -webkit-box;

    margin: 0;

    overflow: hidden;

    color: var(--nx-text);

    font-size: 14.5px;
    line-height: 1.55;
    overflow-wrap: anywhere;

    -webkit-box-orient: vertical;
    -webkit-line-clamp: 4;
}

.pd-shop-review-text.is-empty {
    color: var(--nx-muted);
    font-style: italic;
}

.pd-shop-review-photos {
    display: flex;
    gap: 6px;

    margin: 0;
    padding: 0;

    list-style: none;
}

.pd-shop-review-photos img {
    display: block;

    width: 48px;
    height: 48px;

    border-radius: 4px;
    background: var(--nx-sunken);

    object-fit: cover;
}

.pd-shop-review-foot {
    display: flex;
    flex-direction: column;
    gap: 2px;

    margin-top: auto;

    color: var(--nx-text-2);

    font-size: 13px;
}

.pd-shop-review-foot p {
    margin: 0;
}

.pd-shop-review-by {
    display: flex;
    align-items: center;
    gap: 8px;
}

.pd-shop-review-by strong {
    color: var(--nx-ink);
    font-weight: 600;
}

.pd-shop-review-item {
    overflow: hidden;

    text-overflow: ellipsis;
    white-space: nowrap;
}

.pd-shop-review-product {
    padding: 0;

    border: none;
    background: none;
    color: var(--nx-ink);

    font: inherit;
    text-decoration: underline;
    text-decoration-color: var(--nx-line-strong);
    text-underline-offset: 3px;
}

.pd-shop-review-product:hover {
    text-decoration-color: currentColor;
}

.pd-shop-review-product[disabled] {
    cursor: progress;
}

.pd-shop-review-variant {
    color: var(--nx-muted);
}

.pd-shop-empty {
    margin: 0;

    color: var(--nx-muted);

    font-size: 14px;
}

.pd-shop-products {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 20px;
}

/* ---------------- Lower sections ---------------- */

#buyer-app .pd-h2 {
    margin: 0 0 18px;

    color: var(--nx-ink);

    font-size: 24px;
    font-weight: 700;
    letter-spacing: -0.015em;
}

.pd-about,
.pd-related {
    margin-top: 40px;
    padding-top: 24px;

    border-top: 1px solid var(--nx-line);
}

.pd-about.has-specs {
    display: grid;
    grid-template-columns: minmax(0, 7fr) minmax(0, 5fr);
    gap: clamp(32px, 4vw, 64px);
}

.pd-desc-body {
    max-width: 68ch;

    color: var(--nx-text);

    font-size: 16px;
    line-height: 1.7;
}

.pd-desc-body p {
    margin: 0 0 14px;
}

.pd-desc-body.is-clamped {
    max-height: 13.6em;

    overflow: hidden;

    -webkit-mask-image: linear-gradient(to bottom, #000 70%, transparent);
    mask-image: linear-gradient(to bottom, #000 70%, transparent);
}

.pd-desc-toggle {
    margin-top: 4px;
}

.pd-specs {
    margin: 0;
}

.pd-spec {
    display: grid;
    grid-template-columns: minmax(110px, 2fr) minmax(0, 3fr);
    gap: 16px;

    padding: 12px 0;

    border-bottom: 1px solid var(--nx-line-soft);

    font-size: 14.5px;
}

.pd-spec:first-child {
    border-top: 1px solid var(--nx-line-soft);
}

.pd-spec dt {
    color: var(--nx-muted);
}

.pd-spec dd {
    margin: 0;

    color: var(--nx-ink);
    font-weight: 500;
    overflow-wrap: anywhere;
}

/* Reviews: one contained panel, like the product and store panels above */

.pd-reviews {
    margin-top: 36px;
    padding: clamp(20px, 2.6vw, 36px);

    border: 1px solid var(--nx-line);
    border-radius: var(--nx-radius);
    background: var(--nx-surface);
    box-shadow: 0 1px 2px rgba(43, 39, 34, 0.04), 0 12px 32px -24px rgba(43, 39, 34, 0.16);
}

.pd-reviews-head {
    margin-bottom: 24px;
}

#buyer-app .pd-reviews-title {
    margin: 0;

    color: var(--nx-ink);

    font-family: var(--nx-font-display);
    font-size: 24px;
    font-weight: 700;
    letter-spacing: -0.015em;
}

.pd-reviews-total {
    color: var(--nx-muted);

    font-weight: 500;
}

.pd-reviews-sub {
    margin: 4px 0 0;

    color: var(--nx-muted);

    font-size: 14px;
}

.pd-reviews-layout {
    display: grid;
    grid-template-columns: 280px minmax(0, 1fr);
    align-items: start;
    gap: clamp(28px, 4vw, 56px);
}

/* Summary: a quiet tinted column, so the score reads first. */
.pd-rsum {
    position: sticky;
    top: calc(var(--nx-header-h) + 24px);

    padding: 20px;

    border-radius: var(--nx-radius-sm);
    background: var(--nx-sunken);
    color: var(--nx-star);
}

.pd-rsum-top {
    padding-bottom: 16px;
    margin-bottom: 12px;

    border-bottom: 1px solid var(--nx-line);
}

.pd-rsum-score {
    display: flex;
    align-items: baseline;
    gap: 8px;

    margin: 0 0 8px;
}

.pd-rsum-number {
    color: var(--nx-ink);

    font-family: var(--nx-font-display);
    font-size: 44px;
    font-weight: 700;
    letter-spacing: -0.03em;
    line-height: 1;
}

.pd-rsum-out {
    color: var(--nx-muted);

    font-size: 14px;
}

.pd-rsum-count {
    margin: 8px 0 0;

    color: var(--nx-text-2);

    font-size: 13.5px;
}

.pd-bars {
    display: flex;
    flex-direction: column;
    gap: 2px;

    margin: 0 -6px;
    padding: 0;

    list-style: none;
}

.pd-bar {
    display: grid;
    grid-template-columns: 46px minmax(0, 1fr) 28px;
    align-items: center;
    gap: 10px;

    width: 100%;
    min-height: 32px;
    padding: 0 6px;

    border: 1px solid transparent;
    border-radius: var(--nx-radius-sm);
    background: none;
    color: var(--nx-text);

    font: inherit;
    font-size: 13px;
    text-align: left;

    transition: background-color var(--nx-dur-fast) var(--nx-ease), border-color var(--nx-dur-fast) var(--nx-ease);
}

@media (hover: hover) and (pointer: fine) {
    .pd-bar:hover:not(:disabled) {
        background: var(--nx-surface);
    }
}

.pd-bar.is-active {
    border-color: var(--nx-ink);
    background: var(--nx-surface);
}

.pd-bar:disabled {
    color: var(--nx-muted-2);
    cursor: default;
}

.pd-bar-track {
    position: relative;

    height: 8px;

    overflow: hidden;
    border-radius: 999px;
    background: var(--nx-line);
}

.pd-bar-track.is-loading {
    animation: skeleton-shimmer 1.4s ease-in-out infinite;
}

/* Scaled, not resized: the bars grow in on the compositor. */
.pd-bar-fill {
    position: absolute;
    inset: 0;

    border-radius: inherit;
    background: var(--nx-star);

    transform-origin: left center;
    transition: transform 0.5s var(--nx-ease-out);
}

.pd-bar-count {
    color: var(--nx-muted);

    font-variant-numeric: tabular-nums;
    text-align: right;
}

.pd-rtools {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px 20px;

    padding-bottom: 16px;

    border-bottom: 1px solid var(--nx-line);
}

.pd-rfilters {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.pd-rfilter {
    display: inline-flex;
    align-items: center;
    gap: 6px;

    min-height: 36px;
    padding: 0 12px;

    border: 1px solid var(--nx-line-strong);
    border-radius: var(--nx-radius-sm);
    background: var(--nx-surface);
    color: var(--nx-text);

    font: inherit;
    font-size: 13.5px;
    font-weight: 500;

    transition:
        background-color var(--nx-dur-fast) var(--nx-ease),
        border-color var(--nx-dur-fast) var(--nx-ease),
        color var(--nx-dur-fast) var(--nx-ease),
        transform var(--nx-dur-fast) var(--nx-ease);
}

@media (hover: hover) and (pointer: fine) {
    .pd-rfilter:hover {
        border-color: var(--nx-ink);
    }
}

.pd-rfilter:active {
    transform: scale(0.97);
}

.pd-rfilter.is-active {
    border-color: var(--nx-ink);
    background: var(--nx-ink);
    color: #ffffff;
}

.pd-rcount {
    margin: 0;

    color: var(--nx-muted);

    font-size: 13px;
}

.pd-rentries {
    margin: 0;
    padding: 0;

    list-style: none;
}

/* Reviews are rows split by hairlines, never cards inside the card. */
.pd-rentry {
    padding: 22px 0;

    border-bottom: 1px solid var(--nx-line-soft);

    color: var(--nx-star);
}

.pd-rentry:last-child {
    padding-bottom: 4px;

    border-bottom: none;
}

.pd-rentry .skeleton {
    margin: 8px 0;
}

.pd-sk-short {
    width: 30%;
}

.pd-rentry-head {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    justify-content: space-between;
    gap: 4px 16px;
}

.pd-rentry-by {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 10px;

    min-width: 0;
    margin: 0;

    font-size: 14.5px;
}

.pd-rentry-by strong {
    color: var(--nx-ink);

    font-weight: 600;
    overflow-wrap: anywhere;
}

.pd-verified {
    display: inline-flex;
    align-items: center;
    gap: 4px;

    color: var(--nx-accent-dark);

    font-size: 12.5px;
    font-weight: 600;
}

.pd-rentry-date {
    flex: none;

    margin: 0;

    color: var(--nx-muted);

    font-size: 13px;
}

.pd-rentry-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 12px;

    margin-top: 8px;
}

.pd-rentry-variant {
    color: var(--nx-muted);

    font-size: 13px;
}

.pd-rentry-text {
    max-width: 70ch;
    margin: 12px 0 0;

    overflow: hidden;

    color: var(--nx-text);

    font-size: 15px;
    line-height: 1.65;
    overflow-wrap: anywhere;
    white-space: pre-line;

    transition: max-height 240ms var(--nx-ease-out);
}

.pd-rentry-text.is-clamped {
    max-height: calc(1.65em * 5);

    -webkit-mask-image: linear-gradient(to bottom, #000 65%, transparent);
    mask-image: linear-gradient(to bottom, #000 65%, transparent);
}

.pd-rentry-text.is-empty {
    color: var(--nx-muted);
    font-style: italic;
}

.pd-rentry-more {
    margin-top: 6px;

    font-size: 13.5px;
}

.pd-rentry-photos {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;

    margin-top: 14px;
}

.pd-rentry-photo {
    width: 80px;
    height: 80px;
    padding: 0;

    overflow: hidden;
    border: 1px solid var(--nx-line);
    border-radius: var(--nx-radius-sm);
    background: var(--nx-sunken);

    cursor: zoom-in;
}

.pd-rentry-photo img {
    display: block;

    width: 100%;
    height: 100%;

    object-fit: cover;

    transition: transform var(--nx-dur) var(--nx-ease);
}

@media (hover: hover) and (pointer: fine) {
    .pd-rentry-photo:hover img {
        transform: scale(1.05);
    }
}

/* Seller reply: indented under the review it answers. */
.pd-reply {
    max-width: 70ch;
    margin-top: 14px;
    padding: 10px 14px;

    border-left: 2px solid var(--nx-accent);
    border-radius: 0 var(--nx-radius-sm) var(--nx-radius-sm) 0;
    background: var(--nx-sunken);
}

.pd-reply p {
    margin: 0;
}

.pd-reply-by {
    color: var(--nx-ink);

    font-size: 13px;
    font-weight: 600;
}

.pd-reply-date {
    color: var(--nx-muted);

    font-weight: 400;
}

.pd-reply-text {
    margin-top: 4px !important;

    color: var(--nx-text);

    font-size: 14.5px;
    line-height: 1.6;
}

.pd-rstate {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;

    padding: 28px 0;

    color: var(--nx-text-2);
}

.pd-rstate p {
    margin: 0;
}

.pd-rmore {
    margin-top: 20px;
}

/* Filter changes: the list fades out and the new one fades in. */
.pd-rswap-enter-active {
    transition: opacity 180ms var(--nx-ease-out), transform 180ms var(--nx-ease-out);
}

.pd-rswap-leave-active {
    transition: opacity 120ms var(--nx-ease-out);
}

.pd-rswap-enter-from {
    opacity: 0;
    transform: translateY(4px);
}

.pd-rswap-leave-to {
    opacity: 0;
}

.pd-reviews-empty {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;

    padding: 20px;

    border-radius: var(--nx-radius-sm);
    background: var(--nx-sunken);
    color: var(--nx-text-2);
}

.pd-reviews-empty p {
    margin: 0;
}

.pd-reviews-empty-title {
    color: var(--nx-ink);

    font-size: 16px;
    font-weight: 600;
}

.pd-reviews-empty .btn {
    margin-top: 10px;
}

.pd-related-grid {
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
}

/* ---------------- Not found ---------------- */

.pd-not-found {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;

    padding: 96px 24px;

    text-align: center;
}

.pd-not-found p {
    margin: 0 0 8px;

    color: var(--nx-text-2);
}

/* ---------------- Mobile buy bar ---------------- */

.pd-buybar {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 35;

    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 14px;

    padding: 10px var(--nx-gutter) calc(10px + env(safe-area-inset-bottom, 0px));

    border-top: 1px solid var(--nx-line);
    background: var(--nx-surface);
    box-shadow: 0 -8px 24px -18px rgba(43, 39, 34, 0.4);
}

.pd-buybar-info {
    display: flex;
    flex-direction: column;

    min-width: 0;
}

.pd-buybar-price {
    color: var(--nx-ink);

    font-family: var(--nx-font-display);
    font-size: 18px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.pd-buybar-choice {
    overflow: hidden;

    color: var(--nx-muted);

    font-size: 12.5px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pd-buybar-btn {
    min-height: 48px;
}

.pd-bar-enter-active,
.pd-bar-leave-active {
    transition: transform var(--nx-dur) var(--nx-ease);
}

.pd-bar-enter-from,
.pd-bar-leave-to {
    transform: translateY(100%);
}

/* ---------------- Viewer ---------------- */

.pd-viewer {
    position: fixed;
    inset: 0;
    z-index: 80;

    display: flex;
    align-items: center;
    justify-content: center;

    padding: 64px 72px;

    background: rgba(18, 17, 15, 0.92);
}

.pd-viewer-img {
    max-width: 100%;
    max-height: 100%;

    object-fit: contain;

    animation: pd-viewer-in 0.24s var(--nx-ease);
}

@keyframes pd-viewer-in {
    from { opacity: 0; transform: scale(0.98); }
}

.pd-viewer-close,
.pd-viewer-nav {
    position: absolute;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    width: 48px;
    height: 48px;

    border: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.08);
    color: #ffffff;
}

.pd-viewer-close:hover,
.pd-viewer-nav:hover {
    background: rgba(255, 255, 255, 0.18);
}

.pd-viewer-close {
    top: 16px;
    right: 16px;
}

.pd-viewer-nav {
    top: 50%;

    transform: translateY(-50%);
}

.pd-viewer-nav.is-prev { left: 16px; }
.pd-viewer-nav.is-next { right: 16px; }

.pd-viewer-count {
    position: absolute;
    left: 50%;
    bottom: 20px;

    margin: 0;

    color: rgba(255, 255, 255, 0.85);

    font-size: 14px;
    font-variant-numeric: tabular-nums;

    transform: translateX(-50%);
}

.pd-viewer :focus-visible {
    outline: 2px solid #ffffff;
    outline-offset: 2px;
}

.pd-fade-enter-active,
.pd-fade-leave-active {
    transition: opacity var(--nx-dur) var(--nx-ease);
}

.pd-fade-enter-from,
.pd-fade-leave-to {
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .pd-viewer-img,
    .pd-btn-primary.is-added svg {
        animation: none;
    }

    .pd-fade-enter-active,
    .pd-fade-leave-active,
    .pd-bar-enter-active,
    .pd-bar-leave-active,
    .pd-bar-fill,
    .pd-rentry-text {
        transition: none;
    }

    /* Filter changes still cross-fade; nothing moves. */
    .pd-rswap-enter-from {
        transform: none;
    }

    .pd-rfilter:active {
        transform: none;
    }
}

/* ---------------- Responsive ---------------- */

@media (max-width: 1100px) {
    .pd-main {
        grid-template-columns: minmax(0, 5fr) minmax(0, 6fr);
    }

    .pd-row {
        grid-template-columns: 96px minmax(0, 1fr);
        gap: 6px 14px;
    }

    .pd-reviews-layout {
        grid-template-columns: 240px minmax(0, 1fr);
    }
}

@media (max-width: 900px) {
    .pd-shop {
        margin: 16px calc(-1 * var(--nx-gutter)) 0;
        padding: 20px var(--nx-gutter);

        border-right: none;
        border-left: none;
        border-radius: 0;
    }

    .pd-shop-head {
        grid-template-columns: auto minmax(0, 1fr);
    }

    .pd-shop-actions {
        grid-column: 1 / -1;

        flex-direction: row;
        flex-wrap: wrap;
    }

    .pd-shop-btn {
        flex: 1 1 160px;
        width: auto;
    }

    .pd-shop-products {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 20px 12px;
    }

    .pd {
        padding-top: 16px;
    }

    .pd-crumb-current {
        display: none;
    }

    .pd-main {
        grid-template-columns: minmax(0, 1fr);
        gap: 24px;

        margin: 0 calc(-1 * var(--nx-gutter));
        padding: 0 var(--nx-gutter) 24px;

        border-right: none;
        border-left: none;
        border-radius: 0;
        box-shadow: none;
    }

    .pd-gallery {
        position: static;

        margin: 0 calc(-1 * var(--nx-gutter));
    }

    /* The gallery runs edge to edge; its actions keep the page gutter. */
    .pd-gallery-foot {
        padding: 0 var(--nx-gutter);
    }

    /* Not a full-width square: on a tablet that is ~800px of photo before
       the title. Square-ish on phones, capped so the name, price and buy
       button start within the first screen. */
    .pd-swipe {
        height: clamp(240px, min(100vw, 56vh), 560px);
        height: clamp(240px, min(100vw, 56svh), 560px);
        aspect-ratio: auto;

        border-radius: 0;
    }

    .pd-about.has-specs,
    .pd-reviews-layout {
        grid-template-columns: minmax(0, 1fr);
        gap: 28px;
    }

    .pd-rsum {
        position: static;
    }

    .pd-about,
    .pd-related {
        margin-top: 28px;
        padding-top: 20px;
    }

    /* Edge to edge on phones and tablets, like the store panel. */
    .pd-reviews {
        margin: 32px calc(-1 * var(--nx-gutter)) 0;
        padding: 24px var(--nx-gutter);

        border-right: none;
        border-left: none;
        border-radius: 0;
        box-shadow: none;
    }

    #buyer-app .pd-reviews-title {
        font-size: 21px;
    }

    .pd-reviews-head {
        margin-bottom: 18px;
    }

    .pd-rsum {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        align-items: center;
        gap: 16px 24px;

        padding: 16px;
    }

    .pd-rsum-top {
        margin: 0;
        padding: 0 24px 0 0;

        border-right: 1px solid var(--nx-line);
        border-bottom: none;
    }

    .pd-rtools {
        flex-direction: column;
        align-items: flex-start;
    }

    .pd-viewer {
        padding: 72px 12px;
    }

    .pd-viewer-nav {
        top: auto;
        bottom: 12px;

        transform: none;
    }
}

@media (max-width: 520px) {
    /* Wrapped fact lines would strand a dot; space them instead. */
    .pd-shop-line,
    .pd-shop-stats {
        gap: 4px 14px;
    }

    .pd-shop-line > * + *::before,
    .pd-shop-stats > * + *::before {
        display: none;
    }

    .pd-shop-actions {
        flex-direction: column;
    }

    .pd-shop-btn {
        flex: none;
        width: 100%;
    }

    .pd-contact-row .pd-shop-btn { flex: 1; width: auto; }

    /* Labels above their controls on narrow screens. */
    .pd-row {
        grid-template-columns: minmax(0, 1fr);
        gap: 6px;
    }

    .pd-options .pd-row-label,
    .pd-qty-row .pd-row-label {
        padding-top: 0;
    }

    .pd-price-block {
        padding: 14px 16px;
    }

    /* Score above the bars on narrow phones. */
    .pd-rsum {
        grid-template-columns: minmax(0, 1fr);
    }

    .pd-rsum-top {
        padding: 0 0 12px;

        border-right: none;
        border-bottom: 1px solid var(--nx-line);
    }




    .pd-spec {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr);
    }

    .pd-related-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 22px 12px;
    }
}
</style>
