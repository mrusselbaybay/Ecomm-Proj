<script setup>
/*
|--------------------------------------------------------------------------
| OrderDetails.vue
|--------------------------------------------------------------------------
|
| Adapted from a pasted reference design ("ShopVerse Order Details") the
| same way as CategoryListing.vue / Account.vue: Tailwind utilities
| (matching Cart.vue's precedent), the shared Header/Footer, #0d9488 brand
| teal the reference already used. All the actual order logic below
| (cancel/review/return, tracking, formatters) is the previous version of
| this file almost unchanged — it was already real and working, just
| completely unstyled (no `.order-details-*`/`.tracking-*` rules existed
| anywhere in layout.css) and, until this same pass, sitting on top of a
| backend that couldn't actually load an order at all — see the note above
| Order.php: App\Models\Order didn't exist as a file, so every request
| through CheckoutService / this page's data source was a fatal error.
|
| Two real additions now that the API can actually expose them:
|   - Order Timeline shows real per-step timestamps from
|     order.statusHistory (order_status_history rows — see
|     OrderController::transform()) instead of just highlighting a step
|     with no date. A step with no history entry yet shows no date, rather
|     than a fabricated one.
|   - Payment Method now shows the order's real payment_status (Paid/
|     Unpaid/Refunded) instead of a fabricated "Visa ending in 4321" —
|     there's no stored card/payment-method vault to draw that from.
|
*/
import { computed, ref } from 'vue';
import { useBuyer } from '../composables/useBuyer';
import { useBuyerChat } from '../composables/useBuyerChat';
import { metaFor } from '../composables/useCategoryMeta';
import { useConfirm } from '../composables/useConfirm';
import {
    trackingSteps,
    stepLabels,
    stepDescriptions,
    stepIcons,
    isTrackingStepCompleted,
    timelineTimestamp
} from '../composables/useOrderTimeline';
import { useToasts } from '../composables/useToasts';
import ReturnRequestModal from './ReturnRequestModal.vue';
import ReviewModal from './ReviewModal.vue';

const props = defineProps({
    order: {
        type: Object,
        default: null
    }
});

const emit = defineEmits([
    'back',
    'go-home',
    'search',
    'select-category',
    'open-cart',
    'view-profile',
    'view-wishlist',
    'view-reviews',
    'view-addresses',
    'view-payments',
    'track-order'
]);

const {
    ORDER_STATUSES,
    cancelOrder,
    submitReview,
    submitReturnRequest
} = useBuyer();

const { success, error: toastError, warning } = useToasts();
const { confirm } = useConfirm();

// status -> most recent order_status_history entry for it, so the
// timeline can show a real date per reached step instead of just a
// highlighted circle. Orders placed before this endpoint started
// returning statusHistory (or any step not yet logged) simply show no
// date — see the fallback for the first step below, the one case where
// an equally-real alternative (the order's own placed-at time) exists.
// (See useOrderTimeline.js — shared with OrderTracking.vue.)

const canCancelOrder = computed(() => {
    return props.order?.status === ORDER_STATUSES.TO_SHIP;
});

const canReviewOrder = computed(() => {
    return props.order?.status === ORDER_STATUSES.DELIVERED;
});

const canRequestReturn = computed(() => {
    return props.order?.status === ORDER_STATUSES.DELIVERED;
});

const isCancelled = computed(() => {
    return props.order?.status === ORDER_STATUSES.CANCELLED;
});

// RETURNED is an alias of CANCELLED in ORDER_STATUSES (there's no
// returns subsystem yet — see submitReturnRequest() in useBuyer.js), so
// this always mirrors isCancelled for now.
const isReturned = computed(() => false);

const deliveryAddress = computed(() => props.order?.delivery_address || {});

const orderTotals = computed(() => ({
    subtotal: Number(props.order?.subtotal || 0),
    shippingFee: Number(props.order?.shipping_fee || 0),
    tax: Number(props.order?.tax || 0),
    discount: Number(props.order?.discount || 0),
    total: Number(props.order?.total || 0)
}));

const isReviewModalOpen = ref(false);
const selectedReviewItem = ref(null);
const selectedReviewItemIndex = ref(-1);

const isReturnModalOpen = ref(false);
const selectedReturnItem = ref(null);
const selectedReturnItemIndex = ref(-1);

function formatPrice(price) {
    return `₱${Number(price || 0).toFixed(2)}`;
}

function formatDate(date) {
    if (!date) {
        return null;
    }

    return new Date(date).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit'
    });
}

function formatLongDate(date) {
    if (!date) {
        return 'Not available';
    }

    return new Date(date).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
}

function formatPaymentMethod(method) {
    if (!method) {
        return 'Not specified';
    }

    if (method === 'cod') {
        return 'Cash on Delivery';
    }

    if (method === 'gcash') {
        return 'GCash';
    }

    if (method === 'card') {
        return 'Credit / Debit Card';
    }

    return method;
}

function formatShippingMethod(method) {
    if (!method) {
        return 'Not specified';
    }

    if (method === 'standard') {
        return 'Standard Delivery';
    }

    if (method === 'express') {
        return 'Express Delivery';
    }

    return method;
}

function formatReturnType(type) {
    if (type === 'return_and_refund') {
        return 'Return and Refund';
    }

    if (type === 'refund_only') {
        return 'Refund Only';
    }

    return type || 'Not specified';
}

function formatReturnReason(reason) {
    const reasonLabels = {
        damaged: 'Product arrived damaged',
        wrong_item: 'Wrong product received',
        incomplete: 'Missing parts or items',
        not_as_described: 'Product is not as described',
        quality_issue: 'Product quality issue',
        other: 'Other reason'
    };

    return reasonLabels[reason] || reason || 'Not specified';
}

function returnStatusClass(status) {
    return `return-status-${String(status || 'pending').toLowerCase()}`;
}

function getItemPrice(item) {
    return Number(item.unit_price ?? item.price ?? 0);
}

async function handleCancelOrder() {
    if (!props.order) {
        return;
    }

    if (!canCancelOrder.value) {
        warning('This order can no longer be cancelled.');

        return;
    }

    const confirmed = await confirm({
        title: 'Cancel this order?',
        message: 'The seller will be notified and, if the order hasn\'t shipped, any reserved stock is released. This can\'t be undone.',
        confirmLabel: 'Cancel order',
        cancelLabel: 'Keep order',
        tone: 'danger',
    });

    if (!confirmed) {
        return;
    }

    const cancelled = await cancelOrder(props.order.orderId, 'Cancelled by buyer');

    if (!cancelled) {
        toastError('We couldn\'t cancel this order right now. Please try again or message the seller.');

        return;
    }

    // props.order is the same live object Orders.vue holds (and passes on
    // to OrderTracking), so reflecting the new status/history here updates
    // every view that shares it without a re-fetch \u2014 same pattern as the
    // review/return handlers below.
    Object.assign(props.order, {
        status: cancelled.status,
        payment_status: cancelled.payment_status,
        statusHistory: cancelled.statusHistory
    });

    success(`Order ${props.order.orderId} was cancelled.`);
}

function openReviewModal(item, index) {
    if (!canReviewOrder.value || item.review) {
        return;
    }

    selectedReviewItem.value = item;
    selectedReviewItemIndex.value = index;
    isReviewModalOpen.value = true;
}

function closeReviewModal() {
    isReviewModalOpen.value = false;
    selectedReviewItem.value = null;
    selectedReviewItemIndex.value = -1;
}

async function handleReviewSubmit(reviewData) {
    if (!props.order || selectedReviewItemIndex.value < 0 || !selectedReviewItem.value) {
        return;
    }

    try {
        const review = await submitReview(selectedReviewItem.value.id, reviewData);

        // props.order is the same reactive object Orders.vue holds in its
        // `orders` list (passed down by reference, not copied), so this
        // mutation is visible there too — the alternative, a full
        // re-fetch of this order just to show one new review, isn't
        // worth the round trip for what's otherwise already known here.
        props.order.items[selectedReviewItemIndex.value].review = review;

        closeReviewModal();
        success('Review submitted. Thanks for the feedback!');
    } catch (err) {
        toastError(err?.message || 'We couldn\'t submit this review right now. Please try again.');
    }
}

function openReturnModal(item, index) {
    if (!canRequestReturn.value || item.returnRequest) {
        return;
    }

    selectedReturnItem.value = item;
    selectedReturnItemIndex.value = index;
    isReturnModalOpen.value = true;
}

function closeReturnModal() {
    isReturnModalOpen.value = false;
    selectedReturnItem.value = null;
    selectedReturnItemIndex.value = -1;
}

async function handleReturnSubmit(requestData) {
    if (!props.order || selectedReturnItemIndex.value < 0 || !selectedReturnItem.value) {
        return;
    }

    try {
        const returnRequest = await submitReturnRequest(selectedReturnItem.value.id, requestData);

        // Same reasoning as handleReviewSubmit: props.order is the live
        // object Orders.vue holds, so mutating the item here shows the
        // submitted request without a full re-fetch.
        props.order.items[selectedReturnItemIndex.value].returnRequest = returnRequest;

        closeReturnModal();
        success('Return request submitted. The seller will review it shortly.');
    } catch (err) {
        toastError(err?.message || 'We couldn\'t submit this request. The item may already have an open request, or the order isn\'t eligible.');
    }
}

/*
|--------------------------------------------------------------------------
| Need Help
|--------------------------------------------------------------------------
|
| No support-ticket system exists yet, so — same honest pattern as
| Account.vue's "Delete Account" — this opens a real, pre-filled email
| instead of a form that would imply a ticket gets filed somewhere.
*/

const needHelpMailtoHref = computed(() => {
    const subject = encodeURIComponent(`Help with order ${props.order?.orderId || ''}`);
    const body = encodeURIComponent(
        `Hi BuyTheWay support,\n\nI need help with my order ${props.order?.orderId || ''}.\n\n`
    );

    return `mailto:support@nexmart.com?subject=${subject}&body=${body}`;
});

/*
|--------------------------------------------------------------------------
| Message Seller
|--------------------------------------------------------------------------
|
| Opens the Messages modal on this order's thread with its seller (or a
| new-conversation screen about the order when there isn't one yet).
*/

const { messageSeller } = useBuyerChat();

function messageOrderSeller() {
    const order = props.order;

    if (!order?.seller_id) {
        return;
    }

    messageSeller({
        sellerId: order.seller_id,
        seller: order.seller || order.items?.[0]?.seller || '',
        order: {
            number: order.orderId,
            status: order.status || null,
            items: (order.items || []).map(item => ({
                name: item.name || 'Item',
                variant: item.variation || item.variant || null,
                quantity: Number(item.quantity) || 1,
                unitPrice: getItemPrice(item)
            }))
        }
    });
}

// Small inline copy icon lives next to the tracking number itself in the
// Shipping Information card below — a more natural home for it than a
// standalone header button once there's a real tracking page to link to.
const trackingCopied = ref(false);

async function copyTrackingNumber() {
    if (!props.order?.tracking_number) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.order.tracking_number);
        trackingCopied.value = true;
        setTimeout(() => {
 trackingCopied.value = false; 
}, 2000);
    } catch (err) {
        // Clipboard permission denied/unavailable — nothing to recover
        // from mid-click; the number is still visible to copy by hand.
    }
}
</script>

<template>

    <div
        v-if="order"
        class="acc-view"
    >

        <div class="flex-1 space-y-6 min-w-0">

            <!-- Breadcrumb + Actions -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 text-sm font-semibold text-brand hover:underline mb-2"
                        @click="emit('back')"
                    >
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m12 19-7-7 7-7" /><path d="M19 12H5" />
                        </svg>
                        Back to My Orders
                    </button>
                    <h1 class="text-3xl font-bold text-slate-900 tracking-tight">Order {{ order.orderId }}</h1>
                </div>
                <div class="flex items-center gap-3">
                    <button
                        v-if="order.seller_id"
                        type="button"
                        class="px-5 py-2.5 bg-white border border-slate-200 rounded-xl text-sm font-bold text-brand hover:bg-slate-50 transition-all flex items-center gap-2"
                        style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                        aria-haspopup="dialog"
                        @click="messageOrderSeller"
                    >
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
                        </svg>
                        Message Seller
                    </button>
                    <a
                        :href="needHelpMailtoHref"
                        class="px-5 py-2.5 bg-white border border-slate-200 rounded-xl text-sm font-bold text-slate-700 hover:bg-slate-50 transition-all"
                        style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                    >
                        Need Help
                    </a>
                    <button
                        type="button"
                        class="px-5 py-2.5 bg-brand text-white rounded-xl text-sm font-bold hover:bg-brand-dark transition-all flex items-center gap-2"
                        style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                        @click="emit('track-order')"
                    >
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2" /><path d="M15 18H9" />
                            <path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14" />
                            <circle cx="17" cy="18" r="2" /><circle cx="7" cy="18" r="2" />
                        </svg>
                        Track Package
                    </button>
                </div>
            </div>

            <!-- Order Summary Bar -->
            <div class="grid grid-cols-2 md:grid-cols-4 bg-white rounded-3xl p-6 border border-slate-100" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">
                <div class="px-4 py-2 border-r border-slate-100">
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Order Placed</p>
                    <p class="font-bold text-slate-900">{{ formatLongDate(order.createdAt) }}</p>
                </div>
                <div class="px-4 py-2 border-r border-slate-100">
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Total Amount</p>
                    <p class="font-bold text-brand">{{ formatPrice(orderTotals.total) }}</p>
                </div>
                <div class="px-4 py-2 border-r border-slate-100">
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Shipping Method</p>
                    <p class="font-bold text-slate-900">{{ formatShippingMethod(order.shipping_method) }}</p>
                </div>
                <div class="px-4 py-2">
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Status</p>
                    <span
                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold"
                        :class="{
                            'bg-blue-50 text-blue-600': order.status === ORDER_STATUSES.IN_TRANSIT,
                            'bg-amber-50 text-amber-600': order.status === ORDER_STATUSES.TO_SHIP || order.status === ORDER_STATUSES.PROCESSING,
                            'bg-emerald-50 text-emerald-600': order.status === ORDER_STATUSES.DELIVERED,
                            'bg-red-50 text-red-600': isCancelled
                        }"
                    >
                        {{ order.status }}
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">

                <!-- Left: Products + Timeline -->
                <div class="xl:col-span-2 space-y-8">

                    <!-- Ordered Products -->
                    <section class="bg-white rounded-3xl border border-slate-100 overflow-hidden" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">
                        <div class="px-8 py-5 border-b border-slate-100">
                            <h2 class="text-lg font-bold text-slate-900">Ordered Products</h2>
                        </div>
                        <div class="divide-y divide-slate-50">
                            <div
                                v-for="(item, index) in order.items"
                                :key="`${order.orderId}-${index}`"
                                class="p-8"
                            >
                                <div class="flex flex-col sm:flex-row sm:items-center gap-6">
                                    <div
                                        class="w-24 h-24 rounded-2xl flex items-center justify-center shrink-0"
                                        :class="'accent-' + metaFor(item.category).accent"
                                        style="background: var(--accent-bg, #f1f5f9); color: var(--accent-fg, #64748b);"
                                    >
                                        <span class="w-9 h-9" v-html="metaFor(item.category).icon"></span>
                                    </div>
                                    <div class="flex-1">
                                        <h3 class="font-bold text-slate-900">{{ item.name || `Product #${item.product_id}` }}</h3>
                                        <p class="text-sm text-slate-500 mt-1">
                                            Seller: {{ item.seller || 'BuyTheWay Seller' }}
                                            <template v-if="item.variation"> • {{ item.variation }}</template>
                                        </p>
                                    </div>
                                    <div class="text-left sm:text-center sm:px-8">
                                        <p class="text-xs text-slate-400 font-bold uppercase tracking-wider mb-1">Qty</p>
                                        <p class="font-bold text-slate-900">{{ item.quantity }}</p>
                                    </div>
                                    <div class="text-left sm:text-right">
                                        <p class="text-sm text-slate-400">{{ formatPrice(getItemPrice(item)) }} each</p>
                                        <p class="text-2xl font-bold text-slate-900">{{ formatPrice(getItemPrice(item) * Number(item.quantity)) }}</p>
                                    </div>
                                </div>

                                <!-- Review -->
                                <div
                                    v-if="canReviewOrder"
                                    class="mt-6 pt-6 border-t border-slate-50"
                                >
                                    <div
                                        v-if="item.review"
                                        class="bg-slate-50 rounded-2xl p-5"
                                    >
                                        <div class="flex items-center justify-between mb-2">
                                            <strong class="text-sm text-slate-900">Your review</strong>
                                            <div
                                                class="text-amber-400 text-sm"
                                                :aria-label="`${item.review.rating} out of 5 stars`"
                                            >
                                                <span
                                                    v-for="star in 5"
                                                    :key="star"
                                                    :class="star > item.review.rating ? 'text-slate-200' : ''"
                                                >&#9733;</span>
                                            </div>
                                        </div>
                                        <p class="text-sm text-slate-600">{{ item.review.comment || 'No written comment was added.' }}</p>
                                        <small class="text-xs text-slate-400 block mt-2">Submitted {{ formatLongDate(item.review.createdAt) }}</small>
                                    </div>
                                    <button
                                        v-else
                                        type="button"
                                        class="px-4 py-2 border border-slate-200 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors"
                                        @click="openReviewModal(item, index)"
                                    >
                                        Rate Product
                                    </button>
                                </div>

                                <!-- Return / Refund -->
                                <div
                                    v-if="canRequestReturn || item.returnRequest"
                                    class="mt-4"
                                >
                                    <article
                                        v-if="item.returnRequest"
                                        class="bg-slate-50 rounded-2xl p-5"
                                    >
                                        <div class="flex items-center justify-between mb-3">
                                            <div>
                                                <span class="text-xs text-slate-400 block">Return / Refund Request</span>
                                                <strong class="text-sm text-slate-900">{{ formatReturnType(item.returnRequest.requestType) }}</strong>
                                            </div>
                                            <span class="text-[10px] font-bold uppercase tracking-wide bg-amber-50 text-amber-600 px-2.5 py-1 rounded-full">
                                                {{ item.returnRequest.status }}
                                            </span>
                                        </div>
                                        <div class="grid grid-cols-3 gap-3 text-xs mb-3">
                                            <div><span class="text-slate-400 block">Reason</span><strong class="text-slate-900">{{ formatReturnReason(item.returnRequest.reason) }}</strong></div>
                                            <div><span class="text-slate-400 block">Quantity</span><strong class="text-slate-900">{{ item.returnRequest.quantity }}</strong></div>
                                            <div><span class="text-slate-400 block">Evidence</span><strong class="text-slate-900">{{ item.returnRequest.evidence?.length || 0 }} image(s)</strong></div>
                                        </div>
                                        <p class="text-sm text-slate-600">{{ item.returnRequest.details }}</p>
                                        <small class="text-xs text-slate-400 block mt-2">Submitted {{ formatLongDate(item.returnRequest.submittedAt) }}</small>
                                    </article>
                                    <button
                                        v-else
                                        type="button"
                                        class="px-4 py-2 border border-slate-200 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors"
                                        @click="openReturnModal(item, index)"
                                    >
                                        Return / Refund
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Order Timeline -->
                    <section
                        id="order-timeline"
                        class="bg-white rounded-3xl border border-slate-100 overflow-hidden scroll-mt-24"
                        style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                    >
                        <div class="px-8 py-5 border-b border-slate-100">
                            <h2 class="text-lg font-bold text-slate-900">Order Timeline</h2>
                        </div>

                        <div
                            v-if="!isCancelled && !isReturned"
                            class="p-8"
                        >
                            <div
                                v-for="(step, index) in trackingSteps"
                                :key="step"
                                class="flex gap-6 relative"
                                :class="index < trackingSteps.length - 1 ? 'pb-10' : ''"
                            >
                                <span
                                    v-if="index < trackingSteps.length - 1"
                                    class="absolute left-5 top-10 bottom-0 w-0.5"
                                    :class="isTrackingStepCompleted(order, index + 1) ? 'bg-brand' : 'bg-slate-200'"
                                ></span>

                                <div
                                    class="w-10 h-10 rounded-full flex items-center justify-center shrink-0 z-10"
                                    :class="isTrackingStepCompleted(order, index)
                                        ? 'bg-brand text-white shadow-lg shadow-brand/20'
                                        : 'bg-slate-100 border-2 border-slate-200 text-slate-300'"
                                >
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" v-html="stepIcons[step]"></svg>
                                </div>

                                <div class="flex-1">
                                    <div class="flex items-center justify-between gap-3">
                                        <h4
                                            class="font-bold"
                                            :class="isTrackingStepCompleted(order, index) ? 'text-slate-900' : 'text-slate-400'"
                                        >
                                            {{ stepLabels[step] || step }}
                                        </h4>
                                        <span
                                            v-if="formatDate(timelineTimestamp(order, step))"
                                            class="text-xs font-semibold text-slate-400 shrink-0"
                                        >
                                            {{ formatDate(timelineTimestamp(order, step)) }}
                                        </span>
                                        <span
                                            v-else-if="order.status === step"
                                            class="text-xs font-semibold text-brand shrink-0"
                                        >
                                            Current status
                                        </span>
                                    </div>
                                    <p
                                        class="text-sm mt-1"
                                        :class="isTrackingStepCompleted(order, index) ? 'text-slate-500' : 'text-slate-400'"
                                    >
                                        {{ stepDescriptions[step] }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div
                            v-else-if="isCancelled"
                            class="p-8"
                        >
                            <div class="bg-red-50 rounded-2xl p-6">
                                <strong class="text-red-600 block mb-1">Order Cancelled</strong>
                                <p class="text-sm text-red-500">This order has been cancelled.</p>
                                <p
                                    v-if="order.cancellationReason"
                                    class="text-sm text-red-500 mt-1"
                                >
                                    Reason: {{ order.cancellationReason }}
                                </p>
                                <p
                                    v-if="order.cancelledAt"
                                    class="text-sm text-red-500 mt-1"
                                >
                                    Cancelled: {{ formatLongDate(order.cancelledAt) }}
                                </p>
                            </div>
                        </div>
                    </section>

                </div>

                <!-- Right: Address / Payment / Shipping -->
                <div class="space-y-6">

                    <section class="bg-white rounded-3xl p-8 border border-slate-100 space-y-8" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">

                        <div>
                            <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">Delivery Address</h3>
                            <p class="font-bold text-slate-900 mb-1">{{ deliveryAddress.recipient_name || 'Recipient not available' }}</p>
                            <p
                                v-if="deliveryAddress.contact_number"
                                class="text-sm text-slate-500"
                            >
                                {{ deliveryAddress.contact_number }}
                            </p>
                            <p class="text-sm text-slate-500 leading-relaxed mt-1">
                                {{ deliveryAddress.address || 'No address available' }}
                            </p>
                        </div>

                        <div class="pt-8 border-t border-slate-50">
                            <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">Payment Method</h3>
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-bold text-slate-900">{{ formatPaymentMethod(order.payment_method) }}</span>
                                <span
                                    v-if="order.payment_status"
                                    class="text-[10px] font-bold uppercase tracking-wide px-2.5 py-1 rounded-full"
                                    :class="order.payment_status === 'Paid' ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600'"
                                >
                                    {{ order.payment_status }}
                                </span>
                            </div>
                        </div>

                        <div class="pt-8 border-t border-slate-50">
                            <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">Shipping Information</h3>
                            <div class="space-y-2">
                                <div class="flex justify-between">
                                    <span class="text-sm text-slate-500">Courier</span>
                                    <span class="text-sm font-bold text-slate-900">{{ order.shipping_carrier || 'Not yet assigned' }}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-sm text-slate-500">Tracking No.</span>
                                    <span class="flex items-center gap-2">
                                        <span class="text-sm font-bold text-brand">{{ order.tracking_number || 'Not yet available' }}</span>
                                        <button
                                            v-if="order.tracking_number"
                                            type="button"
                                            class="text-slate-400 hover:text-brand transition-colors"
                                            :title="trackingCopied ? 'Copied!' : 'Copy tracking number'"
                                            @click="copyTrackingNumber"
                                        >
                                            <svg v-if="trackingCopied" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M20 6 9 17l-5-5" />
                                            </svg>
                                            <svg v-else viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <rect width="14" height="14" x="8" y="8" rx="2" ry="2" /><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
                                            </svg>
                                        </button>
                                    </span>
                                </div>
                            </div>
                        </div>

                    </section>

                    <section class="bg-white rounded-3xl p-8 border border-slate-100" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">
                        <h2 class="text-lg font-bold text-slate-900 mb-6">Payment Details</h2>
                        <div class="space-y-4">
                            <div class="flex justify-between">
                                <span class="text-sm text-slate-500 font-medium">Subtotal</span>
                                <span class="text-sm font-bold text-slate-900">{{ formatPrice(orderTotals.subtotal) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm text-slate-500 font-medium">Shipping</span>
                                <span
                                    class="text-sm font-bold"
                                    :class="orderTotals.shippingFee === 0 ? 'text-emerald-600 text-xs uppercase tracking-tight' : 'text-slate-900'"
                                >
                                    {{ orderTotals.shippingFee === 0 ? 'Free' : formatPrice(orderTotals.shippingFee) }}
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm text-slate-500 font-medium">Tax</span>
                                <span class="text-sm font-bold text-slate-900">{{ formatPrice(orderTotals.tax) }}</span>
                            </div>
                            <div
                                v-if="orderTotals.discount > 0"
                                class="flex justify-between"
                            >
                                <span class="text-sm text-slate-500 font-medium">Voucher Discount</span>
                                <span class="text-sm font-bold text-emerald-600">-{{ formatPrice(orderTotals.discount) }}</span>
                            </div>
                            <div class="pt-4 mt-4 border-t border-slate-100 flex justify-between items-center">
                                <span class="text-lg font-bold text-slate-900">Total</span>
                                <span class="text-2xl font-bold text-brand">{{ formatPrice(orderTotals.total) }}</span>
                            </div>
                        </div>
                    </section>

                    <section
                        v-if="canCancelOrder"
                        class="bg-white rounded-3xl p-8 border border-slate-100"
                        style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                    >
                        <strong class="text-sm text-slate-900 block mb-1">Need to cancel this order?</strong>
                        <p class="text-sm text-slate-500 mb-4">You can cancel while it hasn't shipped yet.</p>
                        <button
                            type="button"
                            class="w-full px-5 py-2.5 border border-red-200 text-red-500 rounded-xl text-sm font-bold hover:bg-red-50 transition-colors"
                            @click="handleCancelOrder"
                        >
                            Cancel Order
                        </button>
                    </section>

                </div>

            </div>

        </div>

        <ReviewModal
            :show="isReviewModalOpen"
            :item="selectedReviewItem"
            :order-id="order.orderId"
            @close="closeReviewModal"
            @submit="handleReviewSubmit"
        />

        <ReturnRequestModal
            :show="isReturnModalOpen"
            :item="selectedReturnItem"
            :order-id="order.orderId"
            @close="closeReturnModal"
            @submit="handleReturnSubmit"
        />
    </div>

    <div
        v-else
        class="empty-products"
    >
        <p>Order not found.</p>
        <button
            type="button"
            class="clear-filters-button"
            @click="emit('back')"
        >
            Back to Orders
        </button>
    </div>

</template>