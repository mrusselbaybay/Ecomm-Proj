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
import { computed, nextTick, ref } from 'vue';
import { useBuyer } from '../composables/useBuyer';
import { useBuyerChat } from '../composables/useBuyerChat';
import { metaFor } from '../composables/useCategoryMeta';
import {
    trackingSteps,
    stepLabels,
    stepDescriptions,
    stepIcons,
    isTrackingStepCompleted,
    timelineTimestamp
} from '../composables/useOrderTimeline';
import Footer from './Footer.vue';
import Header from './Header.vue';
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
        alert('This order can no longer be cancelled.');

        return;
    }

    const confirmed = window.confirm('Are you sure you want to cancel this order?');

    if (!confirmed) {
        return;
    }

    const cancelled = await cancelOrder(props.order.orderId, 'Cancelled by buyer');

    if (!cancelled) {
        alert('Unable to cancel this order right now \u2014 please contact the seller.');

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

    alert('Order cancelled successfully.');
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
    } catch (err) {
        alert(err?.message || 'Unable to submit this review right now.');
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
        alert('Return / refund request submitted successfully.');
    } catch (err) {
        alert(err?.message || 'Unable to submit this request. The item may already have an open request or the order is not eligible.');
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
        `Hi NEXMART support,\n\nI need help with my order ${props.order?.orderId || ''}.\n\n`
    );

    return `mailto:support@nexmart.com?subject=${subject}&body=${body}`;
});

/*
|--------------------------------------------------------------------------
| Message Seller
|--------------------------------------------------------------------------
|
| Opens (or reuses) the buyer<->seller thread for this order's seller via
| useBuyerChat.startConversation(), which pops the messaging popup open on
| it. The order id is attached so the seller sees which order it's about.
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

    if (!body || !props.order?.seller_id) {
        return;
    }

    messageSending.value = true;
    messageError.value = '';

    try {
        await startConversation({
            sellerId: props.order.seller_id,
            orderNumber: props.order.orderId,
            subject: `Order ${props.order.orderId}`,
            body
        });

        messageDraft.value = '';
        messageOpen.value = false;
    } catch (err) {
        messageError.value = err?.message || 'Could not send your message. Please try again.';
    } finally {
        messageSending.value = false;
    }
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

/*
|--------------------------------------------------------------------------
| Header Relay
|--------------------------------------------------------------------------
*/

function handleHeaderSearch(query) {
    emit('search', query);
}

function handleHeaderSelectCategory(category) {
    emit('select-category', category);
}
</script>

<template>

    <div
        v-if="order"
        class="buyer-page"
    >

        <Header
            active-category=""
            @select-category="handleHeaderSelectCategory"
            @cart-click="emit('open-cart')"
            @account-click="emit('view-profile')"
            @logo-click="emit('go-home')"
            @search="handleHeaderSearch"
        />

        <main class="max-w-7xl mx-auto w-full px-4 lg:px-8 py-10">
            <div class="flex flex-col lg:flex-row lg:items-start gap-8">

                <!-- ==================================================== -->
                <!-- SIDEBAR NAV -->
                <!-- ==================================================== -->

                <aside class="w-full lg:w-64 shrink-0 lg:sticky lg:top-36">
                    <nav class="bg-white rounded-3xl p-4 border border-slate-100 space-y-1" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-profile')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" />
                            </svg>
                            My Profile
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl bg-slate-100 text-[#0d9488] font-semibold transition-colors"
                            @click="emit('back')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z" />
                                <path d="M12 22V12" /><polyline points="3.29 7 12 12 20.71 7" /><path d="m7.5 4.27 9 5.15" />
                            </svg>
                            My Orders
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('track-order')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" /><circle cx="12" cy="10" r="3" />
                            </svg>
                            Order Tracking
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-wishlist')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5" />
                            </svg>
                            Wishlist
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-reviews')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" />
                            </svg>
                            My Reviews
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-addresses')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" /><circle cx="12" cy="10" r="3" />
                            </svg>
                            Saved Addresses
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-payments')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect width="20" height="14" x="2" y="5" rx="2" /><line x1="2" x2="22" y1="10" y2="10" />
                            </svg>
                            Payment Methods
                        </button>

                    </nav>
                </aside>

                <!-- ==================================================== -->
                <!-- MAIN CONTENT -->
                <!-- ==================================================== -->

                <div class="flex-1 space-y-6 min-w-0">

                    <!-- Breadcrumb + Actions -->
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 text-sm font-semibold text-[#0d9488] hover:underline mb-2"
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
                                class="px-5 py-2.5 bg-white border border-slate-200 rounded-xl text-sm font-bold text-[#0d9488] hover:bg-slate-50 transition-all flex items-center gap-2"
                                style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                                @click="toggleMessageComposer"
                            >
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
                                </svg>
                                {{ messageOpen ? 'Cancel' : 'Message Seller' }}
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
                                class="px-5 py-2.5 bg-[#0d9488] text-white rounded-xl text-sm font-bold hover:bg-[#0f766e] transition-all flex items-center gap-2"
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

                    <!-- Message Seller composer -->
                    <div
                        v-if="messageOpen"
                        class="bg-white rounded-3xl p-6 border border-slate-100"
                        style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                    >
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2">Message the seller about this order</label>
                        <textarea
                            ref="messageInput"
                            v-model="messageDraft"
                            rows="3"
                            placeholder="Type your message…"
                            class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-sm text-slate-900 focus:outline-none focus:border-[#0d9488] focus:ring-2 focus:ring-[#0d9488]/10 transition-all resize-y"
                        ></textarea>
                        <p v-if="messageError" class="text-xs text-red-500 mt-2">{{ messageError }}</p>
                        <div class="mt-3 flex justify-end">
                            <button
                                type="button"
                                class="px-6 py-2.5 bg-[#0d9488] text-white rounded-xl text-sm font-bold hover:bg-[#0f766e] transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                                :disabled="messageSending || !messageDraft.trim()"
                                @click="sendSellerMessage"
                            >
                                {{ messageSending ? 'Sending…' : 'Send Message' }}
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
                            <p class="font-bold text-[#0d9488]">{{ formatPrice(orderTotals.total) }}</p>
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
                                                    Seller: {{ item.seller || 'NEXMART Seller' }}
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
                                            :class="isTrackingStepCompleted(order, index + 1) ? 'bg-[#0d9488]' : 'bg-slate-200'"
                                        ></span>

                                        <div
                                            class="w-10 h-10 rounded-full flex items-center justify-center shrink-0 z-10"
                                            :class="isTrackingStepCompleted(order, index)
                                                ? 'bg-[#0d9488] text-white shadow-lg shadow-[#0d9488]/20'
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
                                                    class="text-xs font-semibold text-[#0d9488] shrink-0"
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
                                                <span class="text-sm font-bold text-[#0d9488]">{{ order.tracking_number || 'Not yet available' }}</span>
                                                <button
                                                    v-if="order.tracking_number"
                                                    type="button"
                                                    class="text-slate-400 hover:text-[#0d9488] transition-colors"
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
                                        <span class="text-2xl font-bold text-[#0d9488]">{{ formatPrice(orderTotals.total) }}</span>
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

            </div>
        </main>

        <Footer
            @browse-all="emit('go-home')"
            @browse-categories="emit('go-home')"
            @cart-click="emit('open-cart')"
        />

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